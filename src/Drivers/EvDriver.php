<?php
namespace Moebius\Loop\Drivers;

use Moebius\Loop;
use Closure, SplMinHeap, Ev, EvLoop, EvTimer, EvIo;
use Moebius\Loop\{
    DriverInterface,
    EventHandle
};

class EvDriver implements DriverInterface {

    private array $eventHandlers = [];
    private int $eventId = 0;

    private bool $scheduled = false;
    private bool $stopped = false;
    private array $tasks = [];
    private int $taskStart = 0;
    private int $taskEnd = 0;

    private array $microtasks = [];
    private array $microtaskArg = [];
    private int $microtaskStart = 0;
    private int $microtaskEnd = 0;

    private EvLoop $loop;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->loop = EvLoop::defaultLoop();
    }

    public function getTime(): float {
        return $this->loop->now();
    }

    public function run(Closure $shouldResumeFunction=null): void {
        do {
            $this->tick();
        } while ($shouldResumeFunction ? $shouldResumeFunction() : false);
    }

    private function tick(): void {
//        echo "tick time=".$this->getTime()." activity=".count($this->eventHandlers)." tasks={$this->taskStart} {$this->taskEnd} microtasks={$this->microtaskStart} {$this->microtaskEnd}\n";
        if ($this->stopped) {
            return;
        }

        $this->runMicrotasks();
        if ($this->stopped) {
            return;
        }

        if ($this->taskStart < $this->taskEnd) {
            $this->loop->run(Ev::RUN_NOWAIT);
        } else {
            $this->loop->run(Ev::RUN_ONCE);
        }

        $this->runMicrotasks();
        if ($this->stopped) {
            return;
        }

        $taskEnd = $this->taskEnd;
        while ($this->taskStart < $taskEnd) {
            try {
                ($this->tasks[$this->taskStart++])();
            } catch (\Throwable $e) {
                $this->stopped = true;
                $this->handleException($e);
                return;
            }
            $this->runMicrotasks();
        }

        if (
            !empty($this->eventHandlers) ||
            $this->taskStart < $this->taskEnd
        ) {
            $this->schedule();
        }
    }

    public function defer(Closure $callback): void {
        $this->tasks[$this->taskEnd++] = $callback;
        if (!$this->scheduled) {
            $this->schedule();
        }
    }

    public function queueMicrotask(Closure $callback, $argument=null): void {
        $this->microtasks[$this->microtaskEnd] = $callback;
        $this->microtaskArg[$this->microtaskEnd++] = $argument;
        $this->schedule();
    }

    public function delay(float $delay, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->eventHandlers[$eventId] = $this->loop->timer($delay, 0.0, function() use ($eventId, $callback) {
            // invoker
            unset($this->eventHandlers[$eventId]);
            $this->queueMicrotask($callback);
            $this->runMicrotasks();
        });

        $this->schedule();

        return EventHandle::for($this, $eventId);
    }

    public function readable($resource, Closure $callback): EventHandle {
        $eventId = $this->eventId++;
        $eh = EventHandle::for($this, $eventId);

        $this->eventHandlers[$eventId] = $this->loop->io($resource, Ev::READ, function($a) use ($resource, $callback, $eh) {
            if (!\is_resource($resource)) {
                $eh->cancel();
            }
            // invoker
            $this->queueMicrotask($callback, $resource);
            $this->runMicrotasks();
        });

        $this->schedule();

        return $eh;
    }

    public function writable($resource, Closure $callback): EventHandle {
        $eh = EventHandle::for($this, $this->eventId++);

        $this->eventHandlers[$eh->getId()] = $this->loop->io($resource, Ev::WRITE, function() use ($resource, $callback, $eh) {
            if (!\is_resource($resource)) {
                $eh->cancel();
            }
            // invoker
            $this->queueMicrotask($callback, $resource);
            $this->runMicrotasks();
        });

        $this->schedule();

        return EventHandle::for($this, $eventId);
    }

    public function signal(int $signalNumber, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->eventHandlers[$eventId] = $this->loop->signal($signalNumber, function() {
            $this->queueMicrotask($callback, $signalNumber);
            $this->runMicrotasks();
        });

        $this->schedule();

        return EventHandle::for($this, $eventId);
    }

    public function cancel(int $eventId): void {
        if (isset($this->eventHandlers[$eventId])) {
            $this->eventHandlers[$eventId]->stop();
            unset($this->eventHandlers[$eventId]);
        }
    }

    public function suspend(int $eventId): ?Closure {
        if (!isset($this->eventHandlers[$eventId])) {
            return null;
        }
        if ($this->eventHandlers[$eventId] instanceof EvTimer) {
            return null;
        }

        $eventHandlers = &$eventHandlers;
        $event = $this->eventHandlers[$eventId];
        unset($this->eventHandlers[$eventId]);
        $event->stop();
        return function() use ($event, $eventId) {
            $event->start();
            $this->eventHandlers[$eventId] = $event;
            $this->schedule();
        };
    }

    private function handleException(\Throwable $e) {
        ($this->exceptionHandler)($e);
    }

    private function runMicrotasks(): void {
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

    private function schedule(): void {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        \register_shutdown_function(function() {
            $this->scheduled = false;
            $this->tick();
        });
    }

}
