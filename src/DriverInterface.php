<?php
namespace Moebius\Loop;

use Closure;

interface DriverInterface {

    /**
     * Get a time reference in seconds from an arbitrary point in time. This time
     * reference is monotonic and may not be identical to wall-clock time.
     */
    public function getTime(): float;

    /**
     * Run the event loop until there are no more pending tasks or event listeners,
     * or until `DriverInterface::stop()` is called.
     */
    public function run(): void;

    /**
     * Stop the event loop if it is currently running.
     */
    public function stop(): void;

    /**
     * Run the event loop until the promise has been either fulfilled or rejected
     */
    public function await(object $promise, ?float $timeLimit): mixed;

    /**
     * Schedule a callback to run as soon as possible following all other scheduled
     * callbacks.
     */
    public function defer(Closure $callback, mixed ...$args): void;

    /**
     * Schedule a callback to run as soon as possible following before any deferred
     * callbacks or event callbacks, but after any previously scheduled microtask
     * callbacks.
     */
    public function queueMicrotask(Closure $callback, mixed $argument=null): void;

    /**
     * Schedule a callback to run as soon as possible after running callbacks
     * scheduled for this iteration of the event loop cycle. The callback will be
     * run before any read or write stream polling and should primarily schedule
     * event callbacks for the next cycle.
     */
    public function poll(Closure $callback): void;

    /**
     * Return an event handler promise which will be triggered as soon as `$time`
     * number of seconds have elapsed.
     */
    public function delay(float $time): Handler;

    /**
     * Return an event handler promise which will be triggered as soon as reading
     * from stream `$resource` will not block.
     */
    public function readable($resource): Handler;

    /**
     * Return an event handler promise which will be triggered as soon as writing
     * to stream `$resource` will not block.
     */
    public function writable($resource): Handler;
}
