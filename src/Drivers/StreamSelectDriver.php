<?php
namespace Co\Loop\Drivers;

use Co\Loop;
use Closure, SplMinHeap;
use Co\Loop\{
    DriverInterface,
    EventHandle
};

class StreamSelectDriver implements DriverInterface {

    private array $eventCallbacks = [];
    private array $eventStreamInfo = [];
    private array $signals = [];
    private array $eventTimers = [];

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

    private $timers;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->time = hrtime(true);
        $this->timers = new \SplMinHeap();
    }

    public function getTime(): float {
        return $this->time / 1_000_000_000;
    }

    public function run(Closure $shouldResumeFunction=null): void {
        do {
            $this->tick();
        } while ($shouldResumeFunction ? $shouldResumeFunction() : false);
    }

    private function tick(): void {
//        echo "tick time=".$this->getTime()." activity=".count($this->eventCallbacks)." tasks={$this->taskStart} {$this->taskEnd} microtasks={$this->microtaskStart} {$this->microtaskEnd}\n";
        if ($this->stopped) {
            return;
        }

        $this->runMicrotasks();
        if ($this->stopped) {
            return;
        }

        $this->time = hrtime(true);

        while (!$this->timers->isEmpty() && $this->timers->top()[0] < $this->time) {
            $eventId = $this->timers->extract()[1];
            if (isset($this->eventCallbacks[$eventId])) {
                $this->defer($this->eventCallbacks[$eventId]);
                $this->deleteEventHandler($eventId);
            }
        }

        if ($this->taskStart < $this->taskEnd) {
            $uSeconds = 0;
        } else {
            if (!$this->timers->isEmpty()) {
                $uSeconds = min(250000, intval(($this->timers->top()[0] - $this->time) / 1000));
            } else {
                $uSeconds = 0;
            }
        }

        $reads = [];
        $readListeners = [];
        $writes = [];
        $writeListeners = [];
        foreach ($this->eventStreamInfo as $eventId => $info) {
            $fdId = $info['fdId'];
            if (!\is_resource($info['fd'])) {
                $this->defer($this->eventCallbacks[$eventId]);
                $this->deleteEventHandler($eventId);
            } elseif ($info['read']) {
                if (!isset($reads[$fdId])) {
                    $reads[$fdId] = $info['fd'];
                }
                $readListeners[$fdId][] = $eventId;
            } elseif (!$info['read']) {
                if (!isset($writes[$fdId])) {
                    $writes[$fdId] = $info['fd'];
                }
                $writeListeners[$fdId][] = $eventId;
            }
        }

        if (!empty($reads) || !empty($writes)) {
            $void = [];
            $count = \stream_select($reads, $writes, $void, 0, $uSeconds);
            if ($count !== false && $count > 0) {
                foreach ($reads as $fd) {
                    $fdId = \get_resource_id($fd);
                    foreach ($readListeners[$fdId] as $eventId) {
                        $this->defer($this->eventCallbacks[$eventId]);
                    }
                }
                foreach ($writes as $fd) {
                    $fdId = \get_resource_id($fd);
                    foreach ($writeListeners[$fdId] as $eventId) {
                        $this->defer($this->eventCallbacks[$eventId]);
                    }
                }
            }
        } elseif ($uSeconds > 0) {
            \usleep($uSeconds);
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
            !empty($this->eventCallbacks) ||
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

        $time = intval(($this->getTime() + $delay) * 1_000_000_000);

        $this->timers->insert([$time, $eventId]);

        $this->eventTimers[$eventId] = true;

        $this->eventCallbacks[$eventId] = function() use ($eventId, $callback) {
            $this->deleteEventHandler($eventId);
            $this->defer($callback);
        };

        $this->schedule();

        return EventHandle::for($this, $eventId);
    }

    public function readable($resource, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->eventStreamInfo[$eventId] = [
            'fd' => $resource,
            'fdId' => \get_resource_id($resource),
            'read' => true,
        ];

        $this->eventCallbacks[$eventId] = static function() use ($callback, $resource) {
            $callback($resource);
        };

        $this->schedule();

        return EventHandle::for($this, $eventId);
    }

    public function writable($resource, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->eventStreamInfo[$eventId] = [
            'fd' => $resource,
            'fdId' => \get_resource_id($resource),
            'read' => false,
        ];

        $this->eventCallbacks[$eventId] = static function() use ($callback, $resource) {
            $callback($resource);
        };

        $this->schedule();

        return EventHandle::for($this, $eventId);
    }

    public function signal(int $signalNumber, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->eventCallbacks[$eventId] = $callback;

        if (!isset($this->signals[$signalNumber])) {
            \pcntl_signal($signalNumber, $this->onSignal(...));
        }
        $this->signals[$signalNumber][] = $eventId;
        $this->eventSignals[$eventId] = $signalNumber;

        return EventHandle::for($this, $eventId);
    }

    private function deleteEventHandler(int $id): void {
        if (isset($this->eventSignals[$id])) {
            $signalNumber = $this->eventSignals[$id];
            $signals = [];
            foreach ($this->signals[$signalNumber] as $eventId) {
                if ($eventId !== $id) {
                    $signals[] = $eventId;
                }
            }
            if (empty($signals)) {
                \pcntl_signal($signalNumber, \SIG_DFL);
                unset($this->signals[$signalNumber]);
            } else {
                $this->signals[$signalNumber] = $signals;
            }
            unset($this->eventSignals[$id]);
        }
        unset($this->eventStreamInfo[$id], $this->eventCallbacks[$id], $this->eventTimers[$id]);
    }

    public function cancel(int $eventId): void {
        $this->deleteEventHandler($eventId);
    }

    public function suspend(int $eventId): ? Closure {
        if (!isset($this->eventCallbacks[$eventId])) {
            return null;
        }
        if (isset($this->eventTimers[$eventId])) {
            return null;
        }

        $callback = $this->eventCallbacks[$eventId];
        $streamInfo = $this->eventStreamInfo[$eventId] ?? null;
        $signal = $this->eventSignals[$eventId] ?? null;
        $this->deleteEventHandler($eventId);
        return function() use ($eventId, $callback, $streamInfo, $signal) {
            $this->eventCallbacks[$eventId] = $callback;
            if ($streamInfo !== null) {
                $this->eventStreamInfo[$eventId] = $streamInfo;
            }
            if ($signal !== null) {
                if (!isset($this->signals[$signal])) {
                    \pcntl_signal($signal, $this->onSignal(...));
                }
                $this->signals[$signal][] = $eventId;
            }
            $this->schedule();
        };
    }

    private function onSignal(int $signalNumber, $sigInfo): void {
        if (empty($this->signals[$signalNumber])) {
            // signals can be received any time, also immediately after cancellation
            return;
        }
        foreach ($this->signals[$signalNumber] as $eventId) {
            $this->defer($this->eventCallbacks[$eventId]);
        }
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

    private function handleException(\Throwable $e): void {
        ($this->exceptionHandler)($e);
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
