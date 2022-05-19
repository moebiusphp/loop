<?php
namespace Co\Loop\Drivers;

use Co\Loop;
use Closure, SplMinHeap, Ev, EvLoop, EvTimer, EvIo;
use Co\Loop\{
    DriverInterface,
    EventHandle
};

abstract class AbstractDriver implements DriverInterface {

    private bool $scheduled = false;
    private bool $stopped = false;

    private array $tasks = [];
    private int $taskStart = 0;
    private int $taskEnd = 0;

    private array $microtasks = [];
    private array $microtaskArg = [];
    private int $microtaskStart = 0;
    private int $microtaskEnd = 0;

    private Closure $exceptionHandler;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
    }

    public function queueMicrotask(Closure $callback, $argument=null): void {
        $this->microtasks[$this->microtaskEnd] = $callback;
        $this->microtaskArg[$this->microtaskEnd++] = $argument;
        $this->schedule();
    }

    public function defer(Closure $callback): void {
        $this->tasks[$this->taskEnd++] = $callback;
        if (!$this->scheduled) {
            $this->schedule();
        }
    }

    /**
     * Returns true if the driver has work that is immediately pending
     */
    protected function hasPendingWork(): bool {
        return $this->taskStart < $this->taskEnd || $this->microtaskStart < $this->microtaskEnd;
    }

    protected function handleException(\Throwable $e): void {
        ($this->exceptionHandler)($e);
    }

    protected function runMicrotasks(): void {
        while ($this->microtaskStart < $this->microtaskEnd) {
            try {
                ($this->microtasks[$this->microtaskStart])($this->microtaskArg[$this->microtaskStart]);
            } catch (\Throwable $e) {
                $this->stopped = true;
                ++$this->microtaskStart;
                $this->handleException($e);
                return;
            }
            ++$this->microtaskStart;
        }
    }

}
