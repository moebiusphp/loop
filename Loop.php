<?php
namespace Moebius;

use Closure;

use Moebius\Loop\{
    Factory,
    EventLoop,
    DriverInterface,
    DriverFactory,
    TimeoutException,
    RejectedException
};

final class Loop {

    private static ?EventLoop $loop = null;

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
    public static function delay(float $time, Closure $callback): Closure {
        return self::get()->delay($time, $callback);
    }

    public static function interval(float $interval, Closure $callback): Closure {
        return self::get()->interval($interval, $callback);
    }

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes readable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public static function readable(mixed $resource, Closure $callback): Closure {
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
        return self::get()->readable($resource, $callback);
    }

    /**
     * Read data from the stream resource until EOF.
     */
    public static function read(mixed $resource, Closure $callback, ?Closure $onError=null): Closure {
        $meta = \stream_get_meta_data($resource);
        $cleanup = [];
        if ($meta['blocked']) {
            if (!\stream_set_blocking($resource, false)) {
                throw new \RuntimeException("Unable to set stream to non-blocking mode");
            }
        }
        \stream_set_read_buffer($resource, 0);

        $cancelFunction = self::readable($resource, function() use ($resource, $callback, $onError, &$cancelFunction) {
            $error = null;
            if (\feof($resource)) {
                if ($cancelFunction) {
                    $cancelFunction();
                    $cancelFunction = null;
                }
                return;
            }
            \set_error_handler(static function($errno, $errstr, $errfile, $errline) use (&$error) {
                $error = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            });
            $chunk = \stream_get_contents($resource, 65536);
            \restore_error_handler();
            if (null === $error && $chunk === false) {
                $error = new \RuntimeException("Failed to read stream");
            }
            if (null !== $error) {
                if ($onError) {
                    self::queueMicrotask($onError, $error);
                } else {

                }
                fclose($resource);
                $cancelFunction();
                $cancelFunction = null;
            }
            self::queueMicrotask($callback, $chunk);
        });

        return static function() use (&$cancelFunction) {
            if ($cancelFunction) {
                $cancelFunction();
            }
        };
    }

    /**
     * Enqueue the provided callback as a microtask whenever a stream resource
     * becomes writable. The callbacks stop when the resource is closed or when
     * the returned callback is invoked.
     */
    public static function writable(mixed $resource, Closure $callback): Closure {
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
        return self::get()->writable($resource, $callback);
    }

    /**
     * Enqueue the provided callback as a microtask whenever a signal is received by
     * the process. The callbacks stop when the resource is closed or when the 
     * returned callback is invoked.
     */
    public static function signal(int $signalNumber, Closure $callback): Closure {
        return self::get()->signal($signalNumber, $callback);
    }

    /**
     * Return the event loop instance
     */
    public static function get(): EventLoop {
        if (self::$loop === null) {
            self::$loop = new EventLoop(Factory::getExceptionHandler());
        }
        return self::$loop;
    }

}

