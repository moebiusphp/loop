<?php
namespace Moebius\Loop\Util;

use Closure;
use Moebius\Loop\DriverInterface;

class Listeners {

    private int $listenerId = 0;
    private Closure $subscribeFunction;
    private Closure $deferFunction;
    private ?Closure $identifyFunction;
    private array $listeners = [];
    private array $resources = [];
    private array $unsubscribers = [];

    public function __construct(Closure $subscribeFunction, Closure $deferFunction, Closure $identifyFunction=null) {
        $this->subscribeFunction = $subscribeFunction;
        $this->deferFunction = $deferFunction;
        $this->identifyFunction = $identifyFunction;
    }

    public function add($resource, Closure $callback): Closure {
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
