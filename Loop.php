<?php
namespace Moebius;

use Closure;

use Moebius\Loop\{
    Handler,
    Factory,
    DriverInterface
};

final class Loop {

    private static ?DriverInterface $loop = null;

    /**
     * Get the event loop time in seconds.
     */
    public static function getTime(): float {
        return self::getDriver()->getTime();
    }

    public static function run(): void {
        self::getDriver()->run();
    }

    public static function stop(): void {
        self::getDriver()->stop();
    }

    /**
     * Run the event loop until the promise is resolved.
     */
    public static function await(object $promise, float $timeout=null): mixed {
        return self::getDriver()->await($promise, $timeout);
    }

    /**
     * Schedule a callback to be executed on the next iteration of the event
     * loop, or delay according to $delay.
     */
    public static function defer(Closure $callback): void {
        self::getDriver()->defer($callback);
    }

    /**
     * Schedule a callback to be executed as soon as possible following the
     * currently executing callback and any other queued microtasks.
     */
    public static function queueMicrotask(Closure $callback, mixed $argument=null): void {
        self::getDriver()->queueMicrotask($callback, $argument);
    }


    /**
     * Schedule a callback to be exercuted after all deferred and microtasks
     * this loop iteration. This function is intended to schedule callbacks
     * for running in the next event loop iteration to create custom event
     * types.
     */
    public static function poll(Closure $callback): void {
        self::getDriver()->poll($callback);
    }

    /**
     * Schedule a callback to run after $time seconds.
     */
    public static function delay(float $time, Closure $callback=null): Handler {
        $handler = self::getDriver()->delay($time);
        if ($callback !== null) {
            $handler->then($callback);
        }
        return $handler;
    }

    /**
     * Schedule a callback to run as soon as $resource becomes readable or closed.
     */
    public static function readable(mixed $resource, Closure $callback=null): Handler {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new \TypeError("Expecting a stream resource");
        }
        $meta = \stream_get_meta_data($resource);
        if (
            strpos($meta['mode'], 'r') === false &&
            strpos($meta['mode'], '+') === false
        ) {
            throw new \TypeError("Expecting a readable stream resource");
        }
        $handler = self::getDriver()->readable($resource);
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes writable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public static function writable(mixed $resource, Closure $callback=null): Handler {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new \TypeError("Expecting a stream resource");
        }
        $meta = \stream_get_meta_data($resource);
        if (
            strpos($meta['mode'], '+') === false &&
            strpos($meta['mode'], 'r') !== false
        ) {
            throw new \TypeError("Expecting a writable stream resource");
        }
        $handler = self::getDriver()->writable($resource);
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }

    /**
     * Return the event loop instance
     */
    private static function getDriver(): DriverInterface {
        if (self::$loop !== null) {
            return self::$loop;
        }
        return self::$loop = Factory::getDriver();
    }

    private static function getExceptionHandler(): Closure {
        if (self::$exceptionHandler !== null) {
            return self::$exceptionHandler;
        }
        return self::$exceptionHandler = Factory::getExceptionHandler();
    }

    public static function setExceptionHandler(Closure $exceptionHandler): void {
        self::$exceptionHandler = $exceptionHandler;
    }

}

