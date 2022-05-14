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

    public function __construct(\Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->timers = new \SplMinHeap();
    }

    abstract protected function schedule(): void;

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
            --$this->pcntlSignalActivity;
            \pcntl_signal($signalNumber, \SIG_DFL);
        };
        $promise->then($cancelHandler, $cancelHandler);
        ++$this->pcntlSignalActivity++;
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

    protected function tick(): void {
        $this->scheduled = false;

        // Run timers scheduled
        while (!$this->timers->isEmpty() && $this->timers->top()[0] < hrtime(true)) {
            if ($this->stopped) {
                return;
            }
            try {
                $this->timers->extract()[1]->fulfill(null);
                if ($this->microtasks !== []) {
                    $this->runMicrotasks();
                }
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
                    return;
                }
                try {
                    $queuedTask();
                    if ($this->microtasks !== []) {
                        $this->runMicrotasks();
                    }
                } catch (\Throwable $e) {
                    $this->stop();
                    ($this->exceptionHandler)($e);
                }
            }
        }

        if (
            !$this->scheduled && (
                $this->queue !== [] ||
                !$this->timers->isEmpty() ||
                $this->pcntlSignalActivity > 0
            )
        ) {
            $this->schedule();
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
