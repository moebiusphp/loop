<?php
namespace Co;

use Co\Loop\DriverFactory;
use Co\Loop\DriverInterface;
use Co\Loop\RejectedException;

/**
 * Note on micro-optimizations
 * ---------------------------
 *
 * This code contains micro-optimizations which may in some
 * configurations have little effect (because of JIT or future
 * PHP-versions etc). They are here because this code is going
 * to be very busy in many circumstances.
 */
final class Loop implements LoopInterface {

    private static DriverInterface $driver;

    /**
     * High resolution timestamp from hrtime(true), updated
     * at the beginning of every tick iteration.
     */
    private static int $time;

    /**
     * The offset of the next deferred task to run
     */
    private static int $deferredStart = 0;

    /**
     * The offset of the next task scheduled.
     */
    private static int $deferredEnd = 0;

    /**
     * An array (list) of scheduled callbacks.
     */
    private static array $deferred = [];

    /**
     * The offset of the next microtask to run.
     */
    private static int $microtaskStart = 0;

    /**
     * The offset of the next microtask scheduled.
     */
    private static int $microtaskEnd = 0;

    /**
     * An array (list) of scheduled microtasks.
     */
    private static array $microtasks = [];

    /**
     * An array (list) of arguments for microtasks. This is kept separate
     * from the self::$microtasks array to reduce the need for allocating
     * and garbage collecting arrays.
     */
    private static array $microtaskArguments = [];

    /**
     * An ordered heap where the next scheduled timed
     * callback is recorded.
     */
    private static ?\SplMinHeap $timers;

    /**
     * True if the loop is not allowed to execute tasks
     */
    private static bool $stopped = false;

    /**
     * True if the tick function is already scheduled to run again
     */
    private static bool $scheduled = false;

    /**
     * True after Loop::bootstrap() has been run.
     */
    private static bool $bootstrapped = false;

    /**
     * Schedule a task to run immediately or after some time delay.
     */
    public static function defer(callable $task, float $delay=0): void {
        if ($delay <= 0) {
            self::$deferred[self::$deferredEnd++] = $task;
        } else {
            self::$timers->insert([hrtime(true) + intval($delay * 1_000_000_000), $task]);
        }

        if (!self::$stopped && !self::$scheduled) {
            self::$driver->schedule();
            self::$scheduled = true;
        }
    }

    /**
     * Schedule a task to run immediately, before any deferred or timed
     * tasks. A signle argument is allowed because it is efficient and
     * also very helpful for scheduling promise fulfilment and rejections.
     */
    public static function queueMicrotask(callable $task, mixed $argument=null): void {
        self::$microtasks[self::$microtaskEnd] = $task;
        self::$microtaskArguments[self::$microtaskEnd++] = $argument;

        if (!self::$stopped && !self::$scheduled) {
            self::$driver->schedule();
            self::$scheduled = true;
        }
    }

    public static function await(object $promiseLike): mixed {
        static $statuses = [
            0 => 'pending',
            1 => 'fulfilled',
            2 => 'rejected',
        ];
        $status = 0;
        $promiseValue = null;
        $promiseLike->then(static function($result) use (&$status, &$promiseValue, $statuses) {
            if ($status !== 0) {
                throw new \LogicException("Promise is already ".$statuses[$status]);
            }
            $status = 1;
            $promiseValue = $result;
        }, static function($result) use (&$status, &$promiseValue, $statuses) {
            if ($status !== 0) {
                throw new \LogicException("Promise is already ".$statuses[$status]);
            }
            $status = 2;
            $promiseValue = $result;
        });
        while ($status === 0) {
            self::tick();
        }
        switch ($status) {
            case 1: return $promiseValue;
            case 2:
                if ($promiseValue instanceof \Throwable) {
                    throw $promiseValue;
                } else {
                    throw new RejectedException($promiseValue);
                }
        }
        throw new \LogicException("Promise was neither rejected nor fulfilled (status=$status)");
    }

    /**
     * Get the current high resolution timestamp which is updated on every tick.
     */
    public static function getTime(): int {
        return self::$time;
    }

    /**
     * Advanced usage; immediately run one iteration of the main task queue and
     * activating any timers.
     *
     * @return bool Is there more work enqueued?
     */
    public static function tick(): void {
        self::$scheduled = false;

        $time = self::$time = hrtime(true);

        // micro-optimization: these are accessed very often in this method, and
        // making them local variables is slightly faster
        $deferredStart = &self::$deferredStart;
        $deferred = &self::$deferred;
        $microtaskStart = &self::$microtaskStart;
        $microtaskEnd = &self::$microtaskEnd;
        $microtasks = &self::$microtasks;
        $microtaskArguments = &self::$microtaskArguments;
        $timers = &self::$timers;
        $stopped = &self::$stopped;

        /**
         * This value is not fetched by reference, because any new
         * deferred tasks must wait until the next tick so we don't 
         * want to update the value anyway. The self::$deferredEnd
         * value will be increased if new functions are queued during
         * this tick, so we need a copy.
         */
        $deferredEnd = self::$deferredEnd;

        /**
         * If there are scheduled delayed tasks, add them at the beginning
         * of the deferred queue.
         */
        if (!$stopped && !$timers->isEmpty() && $timers->top()[0] <= $time) {
            $stack = [];
            while (!$timers->isEmpty() && $timers->top()[0] <= $time) {
                $stack[] = $timers->extract()[1];
            }
            for ($i = count($stack)-1; $i >= 0; $i--) {
                $deferred[--$deferredStart] = $stack[$i];
            }
            unset($stack);
        }


        if (
            self::$deferredStart < self::$deferredEnd ||
            self::$microtaskStart < self::$microtaskEnd
        ) {
            $maxDelay = 0;
        } elseif (!self::$timers->isEmpty()) {
            $maxDelay = (max(0, min(1_000_000_000, self::$timers->top()[0] - hrtime(true)))) / 1_000_000_000;
        } else {
            $maxDelay = null;
        }
        self::$driver->tick($maxDelay);
        /**
         * Run any deferred tasks, while running microtasks between
         * between each task.
         */
        while ($deferredStart < $deferredEnd) {
            if ($stopped) {
                return;
            }

            if ($microtaskStart < $microtaskEnd) {
                if (!self::runMicrotasks()) {
                    return;
                }
            }

            try {
                $deferred[$deferredStart]();
                unset($deferred[$deferredStart++]);
            } catch (\Throwable $e) {
                unset($deferred[$deferredStart++]);
                self::handleException($e);
                return;
            }
        }

        /**
         * Run any microtasks that may have been scheduled by the last
         * deferred task.
         */
        if ($microtaskStart < $microtaskEnd) {
            self::runMicrotasks();
        }


        /**
         * If the task queue is not stopped, we must schedule another tick
         * if we have more work to do.
         */
        if (
            !$stopped &&
            !self::$scheduled && (
                !self::$timers->isEmpty() ||
                $deferredStart < $deferredEnd
            )
        ) {
            self::$driver->schedule();
            self::$scheduled = true;
        }
    }

    /**
     * Run all enqueued microtasks.
     *
     * @return bool If all tasks were executed successfully
     */
    private static function runMicrotasks(): ?bool {
        // micro-optimization bringing these variables to local scope
        $microtaskStart = &self::$microtaskStart;
        $microtaskEnd = &self::$microtaskEnd;
        $microtasks = &self::$microtasks;
        $microtaskArguments = &self::$microtaskArguments;
        $stopped = &self::$stopped;

        while ($microtaskStart < $microtaskEnd) {
            if ($stopped) {
                return false;
            }

            try {
                ($microtasks[$microtaskStart])($microtaskArguments[$microtaskStart]);
                unset($microtasks[$microtaskStart], $microtaskArguments[$microtaskStart++]);
            } catch (\Throwable $e) {
                unset($microtasks[$microtaskStart], $microtaskArguments[$microtaskStart++]);
                self::handleException($e);
                return false;
            }
        }
        return true;
    }

    /**
     * Are we immediately busy again, or can we sleep a bit? The driver is
     * responsible for sleeping.
     */
    private static function getMaxDelay(): ?float {
    }

    /**
     * When exceptions happen inside the driver, this function should be
     * called so that we have consistent error handling.
     */
    private static function handleException(\Throwable $e) {
        fwrite(STDERR, get_class($e).": ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\n".$e->getTraceAsString()."\n");
        self::$stopped = true;
    }

    /**
     * Initialize the static class
     *
     * @internal
     * @psalm-external-mutation-free
     */
    public static function bootstrap(): void {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;

        self::$driver = DriverFactory::discoverDriver(
            self::handleException(...)
        );

        self::$time = hrtime(true);

        self::$timers = new \SplMinHeap();
    }

    public static function getDriver(): DriverInterface {
        return self::$driver;
    }
}

/**
 * Since PHP does not allow dynamic initializers, we'll setup the Loop class here.
 */
Loop::bootstrap();
