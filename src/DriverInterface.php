<?php
namespace Moebius\Loop;

use Closure;

interface DriverInterface {

    /**
     * Get the current event loop time
     */
    public function getTime(): float;

    /**
     * Run one iteration of ticks
     */
    public function run(Closure $shouldResumeFunction=null): void;

    /**
     * Stop the event loop from running
     */
    public function stop(): void;

    /**
     * Schedule a callback to be executed on the next iteration of the event
     * loop.
     */
    public function defer(Closure $callback): void;

    /**
     * Schedule a callback to be executed as soon as possible following the
     * currently executing callback and any other queued microtasks.
     */
    public function queueMicrotask(Closure $callback): void;

    /**
     * Schedule a callback to be executed in $time seconds.
     */
    public function delay(float $time, Closure $callback): EventHandle;

    /**
     * Schedule a callback to be executed in $time seconds.
     */
    public function interval(float $interval, Closure $callback): EventHandle;

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes readable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public function readable(mixed $resource, Closure $callback): EventHandle;

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes writable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public function writable(mixed $resource, Closure $callback): EventHandle;

    /**
     * Enqueue the provided callback as a microtask whenever a signal is received by
     * the process. The callbacks stop when the resource is closed or when the 
     * returned callback is invoked.
     */
    public function signal(int $signalNumber, Closure $callback): EventHandle;

    public function cancel(int $eventId): void;
    public function suspend(int $eventId): ?Closure;

}
