<?php
namespace Moebius;

use Closure;

use Moebius\Loop\{
    DriverInterface,
    DriverFactory,
    EventHandle,
    TimeoutException,
    RejectedException
};

final class Loop {

    private static $driver = null;

    public static function getTime(): float {
        return self::get()->getTime();
    }

    public static function await(object $promise, float $timeout=null) {
        $status = null;
        $result = null;

        $expiration = $timeout !== null ? self::getTime() + $timeout : null;

        $promise->then(
            static function($value) use (&$status, &$result) {
                if ($status === null) {
                    $status = true;
                    $result = $value;
                }
            },
            static function($reason) use (&$status, &$result) {
                if ($status === null) {
                    $status = false;
                    $result = $reason;
                }
            }
        );

        self::run(function() use (&$status, &$result) {
            return $status === null;
        });

        if ($status === true) {
            return $result;
        } elseif ($status === false) {
            if ($result instanceof \Throwable) {
                throw $result;
            } else {
                throw new RejectedException($result);
            }
        } else {
            throw new TimeoutException("Await timed out after $timeout seconds");
        }
    }

    /**
     * Run the loop as long as $shouldResumeFunction returns true. If no
     * function is provided, the loop will run until no more events are
     * are pending.
     */
    public static function run(Closure $shouldResumeFunction=null): void {
        self::get()->run($shouldResumeFunction);
    }

    /**
     * Schedule a callback to be executed on the next iteration of the event
     * loop, or delay according to $delay.
     */
    public static function defer(Closure $callback): void {
        self::get()->defer($callback);
    }

    /**
     * Schedule a callback to be executed as soon as possible following the
     * currently executing callback and any other queued microtasks.
     */
    public static function queueMicrotask(Closure $callback, mixed $argument=null): void {
        self::get()->queueMicrotask($callback, $argument);
    }

    /**
     * Schedule a callback to run after $time seconds.
     */
    public static function delay(float $time, Closure $callback): EventHandle {
        return self::get()->delay($time, $callback);
    }

    public static function interval(float $interval, Closure $callback): EventHandle {
        return self::get()->interval($interval, $callback);
    }

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes readable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public static function readable(mixed $resource, Closure $callback): EventHandle {
        return self::get()->readable($resource, $callback);
    }

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes writable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public static function writable(mixed $resource, Closure $callback): EventHandle {
        return self::get()->writable($resource, $callback);
    }

    /**
     * Enqueue the provided callback as a microtask whenever a signal is received by
     * the process. The callbacks stop when the resource is closed or when the 
     * returned callback is invoked.
     */
    public static function signal(int $signalNumber, Closure $callback): EventHandle {
        return self::get()->signal($signalNumber, $callback);
    }

    /**
     * Return the best driver instance available.
     */
    private static function get(): Loop\DriverInterface {
        if (self::$driver === null) {
            $factory = DriverFactory::getFactory();
            self::$driver = $factory->getDriver();
        }
        return self::$driver;
    }

    public static function setDriver(Loop\DriverInterface $driver): void {
        self::$driver = $driver;
    }

}
