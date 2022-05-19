<?php
namespace Co;

use Co\Loop\DriverFactory;
use Co\Loop\Drivers;
use Co\Loop\DriverInterface;

final class Loop {
    private static ?DriverInterface $driver = null;

    /**
     * Schedule a callback to be executed later
     */
    public static function defer(callable $callback): void {
        self::getDriver()->defer($callback);
    }

    /**
     * Run ticks until the promise is resolved.
     */
    public static function await(object $promise): mixed {
        if (!Promise::isPromise($promise)) {
            throw new \TypeError("Expecting a promise");
        }
        $status = 0;
        $result = null;
        $promise->then(function($value) use (&$status, &$result) {
            $status = 1;
            $result = $value;
        }, function($reason) use (&$status, &$result) {
            $status = 2;
            $result = $reason;
        });
        while ($status === 0) {
            self::tick();
        }
        if ($status === 1) {
            return $result;
        } elseif ($status === 2) {
            throw $result;
        }
    }

    /**
     * Run one tick iteration. This function MUST NOT be called
     * by events already running in the event loop.
     */
    public static function tick(): void {
        self::getDriver()->tick();
    }

    /**
     * Queue a callback to run immediately after the current
     * callback completes
     */
    public static function queueMicrotask(callable $callback): void {
        self::getDriver()->queueMicrotask($callback);
    }

    /**
     * The default exception handler is passed to the driver
     * when the driver is initialized.
     */
    private static function onException(\Throwable $e): void {
        self::getDriver()->stop();
        echo "\n".get_class($e).": ".$e->getMessage()."\n".$e->getTraceAsString()."\n";
    }

    /**
     * Return the backend driver. Initialize it if needed.
     */
    private static function getDriver(): DriverInterface {
        if (self::$driver === null) {
            self::$driver = DriverFactory::getDriver(self::onException(...));
        }
        return self::$driver;
    }

    /**
     * Return a promise which is fulfilled when a stream resource
     * becomes readable.
     */
/*
    public static function readable($resource, float $timeout=null): PromiseInterface {
        return self::getDriver()->readable($resource, $timeout);
    }
*/

    /**
     * Return a promise which is fulfilled when a stream resource
     * becomes readable.
     */
/*
    public static function writable($resource, float $timeout=null): PromiseInterface {
        return self::getDriver()->writable($resource, $timeout);
    }
*/

    /**
     * Return a promise which is fulfilled when a number of seconds
     * have passed.
     */
/*
    public static function delay(float $timeout): PromiseInterface {
        return self::getDriver()->delay($timeout);
    }
*/

    /**
     * Return a promise which is fulfilled when a signal is received
     * by the process. Requires ext-pcntl.
     */
/*
    public static function signal(int $signal): PromiseInterface {
        return self::getDriver()->signal($signal);
    }
*/

    /**
     * Function is invoked to run events when PHP shuts down.
     */
/*
    private static function onShutdown(): void {
        if (self::getDriver()->isStopped()) {
            return;
        }
        $callbacks = self::$callbacks;
        self::$callbacks = [];
        $l = count($callbacks);
        for ($i = 0; $i < $l; $i++) {
            try {
                $callbacks[$i]();
            } catch (\Throwable $e) {
                self::$callbacks = array_merge(array_slice($callbacks, $i+1), self::$callbacks);
                self::onException($e);
                return;
            }
            while (self::$microTasks !== []) {
                try {
                    (array_shift(self::$microTasks))();
                } catch (\Throwable $e) {
                    self::onException($e);
                    return;
                }
            }
        }
        if (self::$callbacks !== []) {
            self::getDriver()->defer($callback);
        }
    }
*/
}
