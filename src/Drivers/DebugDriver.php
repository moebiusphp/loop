<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\DriverInterface;
use Moebius\Loop\EventHandle;


final class DebugDriver implements DriverInterface {

    private DriverInterface $driver;

    public function __construct(DriverInterface $driver) {
        $this->driver = $driver;
    }

    public function getTime(): float {
        $this->log(__METHOD__, func_get_args());
        return $this->driver->getTime();
    }

    /**
     * Run one iteration of ticks
     */
    public function run(Closure $shouldResumeFunction=null): void {
        $this->log(__METHOD__, func_get_args());
        $this->driver->run($shouldResumeFunction);
    }

    /**
     * Schedule a callback to be executed on the next iteration of the event
     * loop.
     */
    public function defer(Closure $callback): void {
        $this->log(__METHOD__, func_get_args());
        $this->driver->defer($callback);
    }

    /**
     * Schedule a callback to be executed as soon as possible following the
     * currently executing callback and any other queued microtasks.
     */
    public function queueMicrotask(Closure $callback, mixed $argument=null): void {
        $this->log(__METHOD__, func_get_args());
        $this->driver->queueMicrotask($callback, $argument);
    }

    /**
     * Schedule a callback to be executed in $time seconds.
     */
    public function delay(float $time, Closure $callback): EventHandle {
        $this->log(__METHOD__, func_get_args());
        return $this->driver->delay($time, $callback);
    }

    /**
     * Schedule a callback to be executed every $interval seconds.
     */
    public function interval(float $interval, Closure $callback): EventHandle {
        $this->log(__METHOD__, func_get_args());
        return $this->driver->interval($interval, $callback);
    }

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes readable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public function readable(mixed $resource, Closure $callback): EventHandle {
        $this->log(__METHOD__, func_get_args());
        return $this->driver->readable($resource, $callback);
    }

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes writable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public function writable(mixed $resource, Closure $callback): EventHandle {
        $this->log(__METHOD__, func_get_args());
        return $this->driver->writable($resource, $callback);
    }


    /**
     * Enqueue the provided callback as a microtask whenever a signal is received by
     * the process. The callbacks stop when the resource is closed or when the 
     * returned callback is invoked.
     */
    public function signal(int $signalNumber, Closure $callback): EventHandle {
        $this->log(__METHOD__, func_get_args());
        return $this->driver->signal($signalNumber, $callback);
    }

    public function cancel(int $eventId): void {
        $this->log(__METHOD__, func_get_args());
        $this->driver->cancel($eventId);
    }

    public function suspend(int $eventId): ?Closure {
        $this->log(__METHOD__, func_get_args());
        return $this->driver->suspend($eventId);
    }

    private function log(string $method, array $args) {
        fwrite(STDERR, $method."(".implode(", ", array_map(json_encode(...), $args)).")\n");
    }

}
