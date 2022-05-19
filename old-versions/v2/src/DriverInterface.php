<?php
namespace Co\Loop;

interface DriverInterface {

    /**
     * When the driver is constructed, it is responsible for
     * keeping itself alive..
     */
    public function __construct(
        \Closure $exceptionHandler
    );

    /**
     * This function is called by Loop::tick() to run event handlers
     */
    public function tick(?float $maxDelay): void;

    /**
     * This function is called to notify the driver that the loop has
     * pending events.
     */
    public function schedule(): void;

    /**
     * Create a watcher for when a resource becomes readable. The watcher must
     * be activated via a call to DriverInterface::activate().
     */
    public function readable(mixed $resource, \Closure $handler): int;

    /**
     * Create a watcher for when a resource becomes writable. The watcher must
     * be activated via a call to DriverInterface::activate().
     */
    public function writable(mixed $resource, \Closure $handler): int;

    /**
     * Create a watcher for when a signal is received by the process. The watcher
     * must be activated via a call to DriverInterface::activate()
     */
    public function signal(int $signalNumber, \Closure $handler): int;

    /**
     * Activate a watcher
     */
    public function activate(int $watcherId): void;

    /**
     * Suspend a watcher
     */
    public function suspend(int $watcherId): void;

    /**
     * Destroy a watcher
     */
    public function cancel(int $watcherId): void;
}
