<?php
namespace Moebius\Loop;

use Closure;

interface DriverInterface {

    /**
     * Schedule a callback to run on the next iteration
     * of the event loop
     */
    public function defer(Closure $callback): void;

    /**
     * Run a callback whenever a resource is determined to be
     * readable. Return a callback which will cancel the read
     * stream watcher.
     *
     * At most one readable listener will be created per unique
     * resource.
     */
    public function readable($resource, Closure $callback): Closure;

    /**
     * Run a callback whenever a resource is determined to be
     * writable. Return a callback which will cancel the write
     * stream watcher.
     *
     * At most one writable listener will be created per unique
     * resource.
     */
    public function writable($resource, Closure $callback): Closure;

    /**
     * Run a callback after $delay seconds. The returned callback can be
     * used to cancel the timer.
     */
    public function delay(float $delay, Closure $callback): Closure;

    /**
     * Run a callback whenever a process control signal is received by the
     * application. The returned callback can be used to cancel the
     * signal watcher.
     *
     * At most one watcher will be created per signal number.
     */
    public function signal(int $signalNumber, Closure $callback): Closure;

    /**
     * Get the current event loop time as a decimal number of seconds.
     */
    public function getTime(): float;

    /**
     * Run the event loop until it is stopped by a call to
     * {@see self::stop()}.
     */
    public function run(): void;

    /**
     * Stop the backend event loop (without cancelling any pending event
     * listeners).
     */
    public function stop(): void;

}
