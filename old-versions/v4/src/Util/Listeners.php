<?php
namespace Moebius\Loop\Util;

use Closure;
use Moebius\Loop\DriverInterface;

class Listeners {

    private int $listenerId = 0;

    /**
     * Callback function in the driver to start subscribing for events
     */
    private Closure $subscribeFunction;

    /**
     * Callback function for scheduling a callback after an event occurs
     */
    private Closure $deferFunction;

    /**
     * Callback function which translates a resource to a value which can
     * be used as a key in arrays.
     */
    private Closure $identifyFunction;

    private array $listeners = [];
    private array $resources = [];

    /**
     * Callback function from the driver to cancel listening for events
     */
    private array $unsubscribers = [];

    public function __construct(Closure $subscribeFunction, Closure $deferFunction, Closure $identifyFunction=null) {
        $this->subscribeFunction = $subscribeFunction;
        $this->deferFunction = $deferFunction;
        $this->identifyFunction = $identifyFunction ?? function($resource) { return $resource; };
    }

    public function isEmpty(): bool {
        return empty($this->unsubscribers);
    }

    public function add($resource, Closure $callback): Closure {
die(" NOT WORKING phpd -dauto_prepend_file=vendor/autoload.php charm-tests/loop/00-basics/05-readable.php");
        $listenerId = $this->listenerId++;
        $resourceId = ($this->identifyFunction)($resource);
        if (!isset($this->resources[$resourceId])) {
            $this->resources[$resourceId] = $resource;
            $this->unsubscribers[$resourceId] = ($this->subscribeFunction)($resource, function() use ($resource) {
                $this->trigger($resource);
            });
        }
        $this->listeners[$resourceId][$listenerId] = $callback;
        return function() use ($resourceId, $listenerId) {
echo "REMOVING LISTENER\n";sleep(1);
            unset($this->listeners[$resourceId][$listenerId]);
            if (empty($this->listeners[$resourceId])) {
                ($this->unsubscribers[$resourceId])();
                unset($this->listeners[$resourceId], $this->unsubscribers[$resourceId], $this->resources[$resourceId]);
            }
        };
    }

    public function remove($resource): void {
        $resourceId = ($this->identifyFunction)($resource);
        unset($this->resources[$resourceId], $this->listeners[$resourceId]);
    }

    private function trigger($resource): void {

        $defer = $this->deferFunction;
        $resourceId = ($this->identifyFunction)($resource);
        if (empty($this->listeners[$resourceId])) {
            return;
        }
        foreach ($this->listeners[$resourceId] as $listener) {
            $defer(static function() use ($listener, $resource) {
                $listener($resource);
            });
        }
    }
}
