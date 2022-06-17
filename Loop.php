<?php
namespace Moebius;

use Closure;
use CurlMultiHandle;

use Moebius\Loop\{
    Handler,
    Factory,
    DriverInterface,
    EventLoop
};

final class Loop {

    private static ?DriverInterface $rootLoop = null;
    private static ?DriverInterface $loop = null;
    private static ?CurlMultiHandle $curlMulti = null;
    private static array $curlHandles = [];
    private static array $curlFulfill = [];

    /**
     * Get the event loop time in seconds.
     */
    public static function getTime(): float {
        return (self::$loop ?? self::getDriver())->getTime();
    }

    public static function setInterval(int $interval): void {
        (self::$loop ?? self::getDriver())->setInterval($interval);
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
    public static function defer(Closure $callback, mixed ...$args): void {
        (self::$loop ?? self::getDriver())->defer($callback, ...$args);
    }

    /**
     * Schedule a callback to be executed as soon as possible following the
     * currently executing callback and any other queued microtasks.
     */
    public static function queueMicrotask(Closure $callback, mixed ...$args): void {
        (self::$loop ?? self::getDriver())->queueMicrotask($callback, ...$args);
    }


    /**
     * Schedule a callback whose purpose is to check for scheduled events.
     *
     * @deprecated Use defer() instead.
     */
    public static function poll(Closure $callback): void {
        (self::$loop ?? self::getDriver())->defer($callback, 0.001);
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
     * Execute the provided curl handle concurrently.
     */
    public static function curl(\CurlHandle $curlHandle, Closure $callback=null): Handler {
        if (self::$curlMulti === null) {
            self::$curlMulti = \curl_multi_init();
        }

        $id = \spl_object_id($curlHandle);
        $cancelFunction = null;
        if (isset(self::$curlHandles[$id])) {
            throw new \LogicException("Handle is already being executed");
        }

        if (self::$curlHandles === []) {
            self::defer($pollFunction = static function() use (&$pollFunction) {
                if (self::$curlHandles === []) {
                    return;
                }
                \curl_multi_exec(self::$curlMulti, $stillRunning);
                if ($stillRunning) {
                    self::defer($pollFunction);
                    \curl_multi_select(self::$curlMulti, 0.001);
                }
                while ($info = \curl_multi_info_read(self::$curlMulti)) {
                    $id = \spl_object_id($info['handle']);
                    self::defer(self::$curlFulfill[$id], \curl_multi_getcontent($info['handle']));
                    \curl_multi_remove_handle(self::$curlMulti, $info['handle']);
                    unset(self::$curlHandles[$id], self::$curlFulfill[$id]);
                }
            });
        }

        self::$curlHandles[$id] = $curlHandle;
        \curl_multi_add_handle(self::$curlMulti, $curlHandle);
        $cancelled = false;
        $cancelFunction = static function() use ($id, $curlHandle, &$cancelled) {
            if (!$cancelled && isset(self::$curlHandles[$id])) {
                $cancelled = true;
                \curl_multi_remove_handle(self::$curlMulti, $curlHandle);
                unset(self::$curlHandles[$id], self::$curlFulfill[$id]);
            }
        };

        [$handler, $fulfill] = Handler::create($cancelFunction);
        if (null !== $callback) {
            $handler->then($callback);
        }
        self::$curlFulfill[$id] = $fulfill;

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

