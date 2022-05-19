<?php
namespace Co\Loop;

use Co\{
    Loop,
    Promise,
    PromiseInterface
};
use Co\Loop\DriverInterface;

/**
 * The abstract driver implementations will run deferred
 * tasks, microtasks and timers. The only requirement is
 * that the `schedule()` method is implemented and will
 * schedule `$this->tick(...)` to run.
 */
abstract class AbstractDriver implements DriverInterface {

    private \SplMinHeap $timers;
    private array $queue = [];
    private array $microtasks = [];
    protected bool $scheduled = false;
    private bool $stopped = false;
    private \Closure $exceptionHandler;

    // counter which holds the number of events being
    // handled by the implementing driver. This is used
    // to determine if the event loop needs to run
    // another iteration
    protected int $activityLevel = 0;

    public function __construct(\Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->timers = new \SplMinHeap();
    }

    /**
     * Function is responsible for ensuring that another
     * tick iteration will be executed (by scheduling
     * a call to `$this->tick(...)`.
     */
    abstract protected function schedule(): void;

    /**
     * Function is responsible for running codes that
     * must happen on every tick iteration.
     */
    abstract protected function onTick(): void;

    public function defer(callable $callback): void {
        $this->queue[] = $callback;
        if (!$this->scheduled) {
            $this->schedule();
        }
    }

    public function queueMicrotask(callable $callback): void {
        $this->microtasks[] = $callback;
    }

    public function delay(float $time): PromiseInterface {
        $promise = new Promise();
        $this->timers->insert([ hrtime(true) + intval($time * 1_000_000_000), $promise ]);
        $this->schedule();
        return $promise;
    }

    public function signal(int $signalNumber): PromiseInterface {
        if (!function_exists("\pcntl_signal")) {
            throw new \Exception("Signal handling requires ext-pcntl or ext-ev");
        }

        $promise = new Promise();

        \pcntl_signal($signalNumber, function() use ($signalNumber, $promise) {
            $promise->fulfill($signalNumber);
        });
        $cancelHandler = function() {
            --$this->activityLevel;
            \pcntl_signal($signalNumber, \SIG_DFL);
        };
        $promise->then($cancelHandler, $cancelHandler);

        ++$this->activityLevel;

        $this->schedule();
        return $promise;
    }

    public function stop(): void {
        $this->stopped = true;
    }

    public function start(): void {
        $this->stopped = false;
        $this->schedule();
    }

    public function isStopped(): bool {
        return $this->stopped;
    }

    public final function tick(): void {
        if ($this->isInTick) {
            throw new \LogicException("Already running a tick");
        }
        $this->isInTick = true;
        $this->scheduled = false;

        if ($this->microtasks !== []) {
            $this->runMicrotasks();
        }

        $this->onTick();

        // Run timers scheduled
        while (!$this->timers->isEmpty() && $this->timers->top()[0] < hrtime(true)) {
            if ($this->stopped) {
                $this->isInTick = false;
                return;
            }
            try {
                if ($this->microtasks !== []) {
                    $this->runMicrotasks();
                }
                $this->timers->extract()[1]->fulfill(null);
            } catch (\Throwable $e) {
                $this->stop();
                ($this->exceptionHandler)($e);
            }
        }

        // Run queued tasks
        if ($this->queue !== []) {
            $queue = $this->queue;
            $this->queue = [];
            foreach ($queue as $queuedTask) {
                if ($this->stopped) {
                    $this->isInTick = false;
                    return;
                }
                try {
                    if ($this->microtasks !== []) {
                        $this->runMicrotasks();
                    }
                    $queuedTask();
                } catch (\Throwable $e) {
                    $this->stop();
                    ($this->exceptionHandler)($e);
                }
            }
        }

        if ($this->microtasks !== []) {
            $this->runMicrotasks();
        }

        /**
         * Determine if we need to schedule another tick
         */
        if (!$this->scheduled) {
            if ($this->activityLevel > 0) {
                $this->schedule();
            } elseif ($this->queue !== []) {
                $this->schedule();
            } elseif (!$this->timers->isEmpty()) {
                $this->schedule();
            }
        }
    }

    private function runMicrotasks(): void {
        while ($this->microtasks !== []) {
            if ($this->stopped) {
                return;
            }
            try {
                (array_shift($this->microtasks))();
            } catch (\Throwable $e) {
                $this->stop();
                ($this->exceptionHandler)($e);
            }
        }
    }

}
