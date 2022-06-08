<?php
namespace Moebius;

use Closure;

use Moebius\Loop\{
    Handler,
    Factory,
    DriverInterface,
    EventLoop
};

final class Loop {

    private static ?DriverInterface $rootLoop = null;
    private static ?DriverInterface $loop = null;

    /**
     * Get the event loop time in seconds.
     */
    public static function getTime(): float {
        return (self::$loop ?? self::getDriver())->getTime();
    }

    public static function run(): void {
        (self::$loop ?? self::getDriver())->run();
    }

    public static function stop(): void {
        (self::$loop ?? self::getDriver())->stop();
    }

    /**
     * Run the event loop until the promise is resolved.
     */
    public static function await(object $promise, float $timeout=null): mixed {
        return (self::$loop ?? self::getDriver())->await($promise, $timeout);
    }

    /**
     * Schedule a callback to be executed on the next iteration of the event
     * loop, or delay according to $delay.
     */
    public static function defer(Closure $callback): void {
        (self::$loop ?? self::getDriver())->defer($callback);
    }

    /**
     * Schedule a callback to be executed as soon as possible following the
     * currently executing callback and any other queued microtasks.
     */
    public static function queueMicrotask(Closure $callback, mixed $argument=null): void {
        (self::$loop ?? self::getDriver())->queueMicrotask($callback, $argument);
    }


    /**
     * Schedule a callback to be exercuted after all deferred and microtasks
     * this loop iteration. This function is intended to schedule callbacks
     * for running in the next event loop iteration to create custom event
     * types.
     */
    public static function poll(Closure $callback): void {
        (self::$loop ?? self::getDriver())->poll($callback);
    }

    /**
     * Schedule a callback to run after $time seconds.
     */
    public static function delay(float $time, Closure $callback=null): Handler {
        $handler = (self::$loop ?? self::getDriver())->delay($time);
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
        $handler = (self::$loop ?? self::getDriver())->readable($resource);
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
        $handler = (self::$loop ?? self::getDriver())->writable($resource);
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }

    /**
     * Returns a child event loop which can be separately paused and resumed.
     */
    public static function get(): EventLoop {
        return new EventLoop(self::$loop, static function(DriverInterface $loop): DriverInterface {
            // child event loops can take over the static API while they are
            // running events via this function.
            $old = self::$loop;
            self::$loop = $loop;
            return $old;
        });
    }

    /**
     * Return the configured driver
     */
    private static function getDriver(): DriverInterface {
        if (self::$loop !== null) {
            return self::$loop;
        }
        return self::$rootLoop = self::$loop = Factory::getDriver();
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

    /**
     * Function to check if the active event loop is actually
     * the one running. For testing purposes.
     *
     * @internal
     */
    public static function test_driver_is(?DriverInterface $loop): bool {
        if ($loop === null) {
            return self::$loop === self::$rootLoop;
        }
        return $loop === self::$loop;
    }

}

