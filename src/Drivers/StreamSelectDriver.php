<?php
namespace Moebius\Loop\Drivers;

use Moebius\Loop;
use Closure, SplMinHeap;
use Moebius\Loop\{
    DriverInterface,
    EventHandle,
    Event
};

class StreamSelectDriver implements DriverInterface {

    private float $time;

    private array $events = [];
    private array $readStreams = [];    // eventId => resource
    private array $writeStreams = [];   // eventId => resource
    private array $signals = [];        // signal => eventId[]
    private array $timers = [];         // eventId => time

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

    private $timerQueue;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->time = hrtime(true) / 1_000_000_000;
        $this->timerQueue = new class extends \SplMinHeap {
            protected function compare($a, $b): int {
                if ($a->time > $b->time) return -1;
                elseif ($a->time < $b->time) return 1;
                return 0;
            }
        };
    }

    public function getTime(): float {
        return $this->time;
    }

    public function run(Closure $shouldResumeFunction=null): void {
        $this->stopped = false;
        do {
            $this->tick();
        } while (!$this->stopped && $shouldResumeFunction ? $shouldResumeFunction() : false);
    }

    public function stop(): void {
        $this->stopped = true;
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

        $this->time = hrtime(true) / 1_000_000_000;

        while (!$this->timerQueue->isEmpty() && $this->timerQueue->top()->time < $this->getTime()) {
            $event = $this->timerQueue->extract();

            if (!isset($this->timers[$event->id])) {
                // timer is deactivated or descheduled
                continue;
            }
            if ($event->time !== $this->timers[$event->id]) {
                // timer is being rescheduled
                $event->time = $this->timers[$event->id];
                $this->timerQueue->insert($event);
                continue;
            }

            if ($event->type === Event::TIMER) {
                unset($this->timers[$event->id]);
            } elseif ($event->type === Event::INTERVAL) {
                $event->time = $event->time + $event->value;
                $this->timers[$event->id] = $event->time;
                $this->timerQueue->insert($event);
            }

            $this->defer($event->callback);
        }

        if ($this->taskStart < $this->taskEnd) {
            $uSeconds = 0;
        } else {
            if (!$this->timerQueue->isEmpty()) {
                $uSeconds = min(250000, intval(($this->timerQueue->top()->time - $this->getTime()) * 1000000));
            } else {
                $uSeconds = 0;
            }
        }

        $reads = array_unique($this->readStreams, \SORT_NUMERIC);
        $writes = array_unique($this->writeStreams, \SORT_NUMERIC);

        if (!empty($reads) || !empty($writes)) {
            $void = [];
            $count = \stream_select($reads, $writes, $void, 0, $uSeconds);
            if ($count !== false && $count > 0) {
                foreach ($reads as $fd) {
                    foreach ($this->readStreams as $eventId => $eventFd) {
                        if ($eventFd === $fd && isset($this->events[$eventId])) {
                            $callback = $this->events[$eventId]->callback;
                            $this->defer(static function() use ($callback, $eventFd) {
                                $callback($eventFd);
                            });
                        }
                    }
                }
                foreach ($writes as $fd) {
                    foreach ($this->writeStreams as $eventId => $eventFd) {
                        if ($eventFd === $fd && isset($this->events[$eventId])) {
                            $callback = $this->events[$eventId]->callback;
                            $this->defer(static function() use ($callback, $eventFd) {
                                $callback($eventFd);
                            });
                        }
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
            !$this->timerQueue->isEmpty() ||
            !empty($this->readStreams) ||
            !empty($this->writeStreams) ||
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

    public function readable($resource, Closure $callback): EventHandle {
        $event = Event::create($this->eventId++, Event::READABLE, $resource, $callback);
        $this->scheduleEvent($event);
        return EventHandle::create($this, $event->id);
    }

    public function writable($resource, Closure $callback): EventHandle {
        $event = Event::create($this->eventId++, Event::WRITABLE, $resource, $callback);
        $this->scheduleEvent($event);
        return EventHandle::create($this, $event->id);
    }

    public function delay(float $delay, Closure $callback): EventHandle {
        $event = Event::create($this->eventId++, Event::TIMER, $delay, $callback, $this->getTime() + $delay);
        $this->scheduleEvent($event);
        return EventHandle::create($this, $event->id);
    }

    public function interval(float $interval, Closure $callback): EventHandle {
        $event = Event::create($this->eventId++, Event::INTERVAL, $interval, $callback, $this->getTime() + $interval);
        $this->scheduleEvent($event);
        return EventHandle::create($this, $event->id);
    }

    public function signal(int $signalNumber, Closure $callback): EventHandle {
        $event = Event::create($this->eventId++, Event::SIGNAL, $signalNumber, $callback);
        $this->scheduleEvent($event);
        return EventHandle::create($this, $event->id);
    }

    private function scheduleEvent(Event $event): void {
        $this->events[$event->id] = $event;
        switch ($event->type) {
            case Event::READABLE:
                $this->readStreams[$event->id] = $event->value;
                break;
            case Event::WRITABLE:
                $this->writeStreams[$event->id] = $event->value;
                break;
            case Event::SIGNAL:
                if (empty($this->signals[$event->value])) {
                    \pcntl_signal($event->value, $this->onSignal(...));
                }
                $this->signals[$event->value][] = $event->id;
                break;
            case Event::TIMER:
            case Event::INTERVAL:
                $this->timerQueue->insert($event);
                $this->timers[$event->id] = $event->time;
                break;
        }
        $this->schedule();
    }

    private function unscheduleEvent(Event $event): void {
        if (!isset($this->events[$event->id])) {
            return;
        }
        unset($this->events[$event->id]);
        switch ($event->type) {
            case Event::READABLE:
                unset($this->readStreams[$event->id]);
                break;
            case Event::WRITABLE:
                unset($this->writeStreams[$event->id]);
                break;
            case Event::SIGNAL:
                foreach ($this->signals[$event->value] as $k => $eventId) {
                    if ($eventId === $event->id) {
                        unset($this->signals[$event->value][$k]);
                        break;
                    }
                }
                if (empty($this->signals[$event->value])) {
                    \pcntl_signal($event->value, \SIG_DFL);
                }
                break;
            case Event::TIMER:
            case Event::INTERVAL:
                unset($this->timers[$event->id]);
                break;
        }
    }

    public function cancel(int $eventId): void {
        if (!isset($this->events[$eventId])) {
            return;
        }
        $event = $this->events[$eventId];
        $this->unscheduleEvent($event);
    }

    public function suspend(int $eventId): ?Closure {
        if (!isset($this->events[$eventId])) {
            return null;
        }
        $event = $this->events[$eventId];
        $this->unscheduleEvent($event);
        return function() use ($event) {
            switch ($event->type) {
                case Event::TIMER:
                    $event->time = hrtime(true) / 1_000_000_000 + $event->value;
                    break;
                case Event::INTERVAL:
                    while ($event->time < $this->getTime()) {
                        $event->time += $event->value;
                    }
                    break;
            }
            $this->scheduleEvent($event);
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
