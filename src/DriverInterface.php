<?php
namespace Co\Loop;

use Co\PromiseInterface;

interface DriverInterface {

    /**
     * Schedule a function to run.
     */
    public function defer(callable $callback): void;

    /**
     * Run a callback when the process receives a signal.
     */
    public function signal(int $signalNumber): PromiseInterface;

    /**
     * Return a promise which will be resolved after
     * a time has elapsed.
     */
    public function delay(float $time): PromiseInterface;

    /**
     * Return a promise which will be resolved when
     * a resource becomes readable.
     */
    public function readable($resource): PromiseInterface;

    /**
     * Return a promise which will be resolved when
     * a resource becomes writable.
     */
    public function writable($resource): PromiseInterface;

    /**
     * Stop the event loop from processing events
     */
    public function stop(): void;

    /**
     * Is the event loop stopped?
     */
    public function isStopped(): bool;

    /**
     * Start the event loop again, after stopping it.
     */
    public function start(): void;
}
