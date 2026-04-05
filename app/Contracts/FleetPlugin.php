<?php

namespace App\Contracts;

/**
 * Host-app alias for the SDK FleetPlugin contract.
 *
 * Extends FleetQ\PluginSdk\Contracts\FleetPlugin so that code using either
 * namespace is satisfied. New plugins distributed as Composer packages should
 * implement the SDK interface directly.
 *
 * @deprecated Use \FleetQ\PluginSdk\Contracts\FleetPlugin instead.
 */
interface FleetPlugin extends \FleetQ\PluginSdk\Contracts\FleetPlugin {}
