<?php
namespace Co;

interface LoopInterface {

    /**
     * Defer a callback until the next tick, optionally waiting a number of seconds
     */
    public static function defer(callable $task, float $delay=0): void;

    /**
     * Run a callback immediately in the current tick
     */
    public static function queueMicrotask(callable $task, mixed $argument=null): void;

    /**
     * Run ticks until a promise or promise-like object is fulfilled or rejected.
     */
    public static function await(object $promiseLike): mixed;

    /**
     * Get the monotonic tick time in nano-seconds (from hrtime())
     */
    public static function getTime(): int;

    /**
     * Run one tick of queued tasks and timers
     */
    public static function tick(): void;

    /**
     * Get the driver implementation
     *
     * @internal
     */
    public static function getDriver(): Loop\DriverInterface;

}
