<?php
namespace Co\Loop;

interface AdapterInterface {

    /**
     * Watch for a stream resource to become readable
     */
    const READABLE = 1;

    /**
     * Watch for a stream resource to become writable
     */
    const WRITABLE = 2;

    /**
     * Watch for a UNIX signal
     */
    const SIGNAL = 4;

    /**
     * This function is called automatically on every tick and
     * is meant to block the application for up to $maxDelay
     * seconds while waiting for events (readable, writable or
     * signal) to occur.
     */
    public function wait(float $maxDelay): void;

    /**
     * Run exactly one iteration of the adapters event loop.
     * This function is called when the event loop needs to
     * step forward to resolve promises. This function should
     * cause any scheduled future ticks to be invoked either
     * directly or indirectly via an event loop.
     */
    public function tick(): void;

    /**
     * Return true if there are watchers that need polling. This
     * function is called to determine if the Co\Loop needs to
     * schedule future ticks.
     */
    public function hasWatchers(): bool;

    /**
     * Schedule a future tick.
     */
    public function schedule(callable $callback): void;

    /**
     * Watch for any of the core event types
     */
    public function watch(int $event, mixed $parameter, callable $handler): int;

    /**
     * Stop watching an event listener
     */
    public function unwatch(int $watcherId): void;

}
