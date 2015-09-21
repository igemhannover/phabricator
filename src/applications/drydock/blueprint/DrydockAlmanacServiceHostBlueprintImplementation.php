<?php

final class DrydockAlmanacServiceHostBlueprintImplementation
  extends DrydockBlueprintImplementation {

  private $services;
  private $freeBindings;

  public function isEnabled() {
    $almanac_app = 'PhabricatorAlmanacApplication';
    return PhabricatorApplication::isClassInstalled($almanac_app);
  }

  public function getBlueprintName() {
    return pht('Almanac Hosts');
  }

  public function getDescription() {
    return pht(
      'Allows Drydock to lease existing hosts defined in an Almanac service '.
      'pool.');
  }

  public function canAnyBlueprintEverAllocateResourceForLease(
    DrydockLease $lease) {
    return true;
  }

  public function canEverAllocateResourceForLease(
    DrydockBlueprint $blueprint,
    DrydockLease $lease) {
    $services = $this->loadServices($blueprint);
    $bindings = $this->loadAllBindings($services);

    if (!$bindings) {
      // If there are no devices bound to the services for this blueprint,
      // we can not allocate resources.
      return false;
    }

    return true;
  }

  public function canAllocateResourceForLease(
    DrydockBlueprint $blueprint,
    DrydockLease $lease) {

    // We will only allocate one resource per unique device bound to the
    // services for this blueprint. Make sure we have a free device somewhere.
    $free_bindings = $this->loadFreeBindings($blueprint);
    if (!$free_bindings) {
      return false;
    }

    return true;
  }

  public function allocateResource(
    DrydockBlueprint $blueprint,
    DrydockLease $lease) {

    $free_bindings = $this->loadFreeBindings($blueprint);
    shuffle($free_bindings);

    $exceptions = array();
    foreach ($free_bindings as $binding) {
      $device = $binding->getDevice();
      $device_name = $device->getName();

      $resource = $this->newResourceTemplate($blueprint, $device_name)
        ->setActivateWhenAllocated(true)
        ->setAttribute('almanacServicePHID', $binding->getServicePHID())
        ->setAttribute('almanacBindingPHID', $binding->getPHID());

      // TODO: This algorithm can race, and the "free" binding may not be
      // free by the time we acquire it. Do slot-locking here if that works
      // out, or some other kind of locking if it does not.

      try {
        return $resource->allocateResource(DrydockResourceStatus::STATUS_OPEN);
      } catch (Exception $ex) {
        $exceptions[] = $ex;
      }
    }

    throw new PhutilAggregateException(
      pht('Unable to allocate any binding as a resource.'),
      $exceptions);
  }

  public function canAcquireLeaseOnResource(
    DrydockBlueprint $blueprint,
    DrydockResource $resource,
    DrydockLease $lease) {

    // TODO: We'll currently lease each resource an unlimited number of times,
    // but should stop doing that.

    return true;
  }

  public function acquireLease(
    DrydockBlueprint $blueprint,
    DrydockResource $resource,
    DrydockLease $lease) {

    // TODO: Once we have limit rules, we should perform slot locking (or other
    // kinds of locking) here.

    $lease
      ->setActivateWhenAcquired(true)
      ->acquireOnResource($resource);
  }

  public function getType() {
    return 'host';
  }

  public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type) {
    // TODO: Actually do stuff here, this needs work and currently makes this
    // entire exercise pointless.
  }

  public function getFieldSpecifications() {
    return array(
      'almanacServicePHIDs' => array(
        'name' => pht('Almanac Services'),
        'type' => 'datasource',
        'datasource.class' => 'AlmanacServiceDatasource',
        'datasource.parameters' => array(
          'serviceClasses' => $this->getAlmanacServiceClasses(),
        ),
        'required' => true,
      ),
      'credentialPHID' => array(
        'name' => pht('Credentials'),
        'type' => 'credential',
        'credential.provides' =>
          PassphraseSSHPrivateKeyCredentialType::PROVIDES_TYPE,
        'credential.type' =>
          PassphraseSSHPrivateKeyTextCredentialType::CREDENTIAL_TYPE,
      ),
    ) + parent::getFieldSpecifications();
  }

  private function loadServices(DrydockBlueprint $blueprint) {
    if (!$this->services) {
      $service_phids = $blueprint->getFieldValue('almanacServicePHIDs');
      if (!$service_phids) {
        throw new Exception(
          pht(
            'This blueprint ("%s") does not define any Almanac Service PHIDs.',
            $blueprint->getBlueprintName()));
      }

      $viewer = PhabricatorUser::getOmnipotentUser();
      $services = id(new AlmanacServiceQuery())
        ->setViewer($viewer)
        ->withPHIDs($service_phids)
        ->withServiceClasses($this->getAlmanacServiceClasses())
        ->needBindings(true)
        ->execute();
      $services = mpull($services, null, 'getPHID');

      if (count($services) != count($service_phids)) {
        $missing_phids = array_diff($service_phids, array_keys($services));
        throw new Exception(
          pht(
            'Some of the Almanac Services defined by this blueprint '.
            'could not be loaded. They may be invalid, no longer exist, '.
            'or be of the wrong type: %s.',
            implode(', ', $missing_phids)));
      }

      $this->services = $services;
    }

    return $this->services;
  }

  private function loadAllBindings(array $services) {
    assert_instances_of($services, 'AlmanacService');
    $bindings = array_mergev(mpull($services, 'getBindings'));
    return mpull($bindings, null, 'getPHID');
  }

  private function loadFreeBindings(DrydockBlueprint $blueprint) {
    if ($this->freeBindings === null) {
      $viewer = PhabricatorUser::getOmnipotentUser();

      $pool = id(new DrydockResourceQuery())
        ->setViewer($viewer)
        ->withBlueprintPHIDs(array($blueprint->getPHID()))
        ->withStatuses(
          array(
            DrydockResourceStatus::STATUS_PENDING,
            DrydockResourceStatus::STATUS_OPEN,
            DrydockResourceStatus::STATUS_CLOSED,
          ))
        ->execute();

      $allocated_phids = array();
      foreach ($pool as $resource) {
        $allocated_phids[] = $resource->getAttribute('almanacDevicePHID');
      }
      $allocated_phids = array_fuse($allocated_phids);

      $services = $this->loadServices($blueprint);
      $bindings = $this->loadAllBindings($services);

      $free = array();
      foreach ($bindings as $binding) {
        if (empty($allocated_phids[$binding->getPHID()])) {
          $free[] = $binding;
        }
      }

      $this->freeBindings = $free;
    }

    return $this->freeBindings;
  }

  private function getAlmanacServiceClasses() {
    return array(
      'AlmanacDrydockPoolServiceType',
    );
  }


}
