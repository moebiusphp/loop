<?php
namespace Co\Loop\Drivers;

use Closure;
use Co\Loop\{
    DriverInterface,
    EventHandle
};

class ReactDriver implements DriverInterface {

    const READ = 0;
    const WRITE = 1;
    const DELAY = 2;
    const SIGNAL = 3;

    private $exceptionHandler;
    private $loop;
    private $stopped = false;

    private array $microtasks = [];
    private array $microtaskArg = [];
    private int $microtaskStart = 0;
    private int $microtaskEnd = 0;

    // next available event id
    private int $eventId = 0;

    // fdId => ...eventId
    private array $readStreams = [];
    // fdId => ...eventId
    private array $writeStreams = [];

    // signalNumber => ...eventId
    private array $signals = [];

    // eventID => callback
    private array $events = [];
    // eventID => type
    private array $eventType = [];
    // eventID => parameter
    private array $eventArg = [];
    // eventID => reactObject
    private array $eventHandle = [];
    // counts number of tasks deferred
    private int $deferredCount = 0;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->loop = \React\EventLoop\Loop::get();
    }

    public function run(Closure $shouldResumeFunc=null): void {
        $this->stopped = false;
        if ($shouldResumeFunc === null) {
            $this->loop->run();
        } else {
            for (;;) {
                $this->loop->futureTick($this->loop->stop(...));
                $this->loop->run();
                if ($shouldResumeFunc()) {
                    /**
                     * React does not sleep when we have deferred
                     * tick functions. If we have no tasks, we'll
                     * sleep here instead.
                     */
                    if (
                        $this->deferredCount === 0 &&
                        $this->microtaskStart === $this->microtaskEnd
                    ) {
                        usleep(25000);
                    }
                } else {
                    break;
                }
            } while ($shouldResumeFunc());
        }
    }

    public function getTime(): float {
        return hrtime(true) / 1_000_000_000;
    }

    public function defer(Closure $callback): void {
        ++$this->deferredCount;
        $this->loop->futureTick($this->wrap($callback));
    }

    public function queueMicrotask(Closure $callback, $arg=null): void {
        $id = $this->microtaskEnd++;
        $this->microtasks[$id] = $callback;
        $this->microtaskArg[$id] = $arg;
        if ($this->deferredCount === 0) {
            // must ensure the microtasks are run
            $this->defer(static function() {});
        }
    }

    public function delay(float $time, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->events[$eventId] = $this->wrap($callback);
        $this->eventType[$eventId] = self::DELAY;
        $this->eventArg[$eventId] = $time;
        $this->eventHandle[$eventId] = $this->loop->addTimer($time, function() use ($eventId) {
            if (isset($this->events[$eventId])) {
                ++$this->deferredCount;
                $this->loop->futureTick($this->events[$eventId]);
            }
            unset(
                $this->events[$eventId],
                $this->eventType[$eventId],
                $this->eventArg[$eventId],
                $this->eventHandle[$eventId]
            );
        });

        return EventHandle::for($this, $eventId);
    }

    public function readable($resource, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->events[$eventId] = $this->wrap($callback, $resource);
        $this->eventType[$eventId] = self::READ;
        $this->eventArg[$eventId] = $resource;
        $this->eventHandle[$eventId] = $callback;
        $this->readableOn($resource, $eventId);

        return EventHandle::for($this, $eventId);
    }

    public function writable($resource, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->events[$eventId] = $this->wrap($callback, $resource);
        $this->eventType[$eventId] = self::READ;
        $this->eventArg[$eventId] = $resource;
        $this->eventHandle[$eventId] = $callback;
        $this->writableOn($resource, $eventId);

        return EventHandle::for($this, $eventId);
    }

    public function signal(int $signalNumber, Closure $callback): EventHandle {
        $eventId = $this->eventId++;

        $this->events[$eventId] = $this->wrap($callback, $signalNumber);
        $this->eventType[$eventId] = self::SIGNAL;
        $this->eventArg[$eventId] = $signalNumber;
        $this->eventHandle[$eventId] = null;
        $this->signalOn($signalNumber, $eventId);

        return EventHandle::for($this, $eventId);
    }

    public function cancel(int $eventId): void {
        if (!isset($this->events[$eventId])) {
            return;
        }
        if ($this->eventType[$eventId] === self::DELAY) {
            $this->loop->cancelTimer($this->eventHandle[$eventId]);
        } else {
            $this->eventOff($eventId);
        }
        unset(
            $this->events[$eventId],
            $this->eventType[$eventId],
            $this->eventArg[$eventId],
            $this->eventHandle[$eventId]
        );
    }

    public function suspend(int $eventId): ?Closure {
        if (!isset($this->events[$eventId])) {
            return null;
        }
        if ($this->eventType[$eventId] === self::DELAY) {
            return null;
        }

        $callback = $this->events[$eventId];
        $type = $this->eventType[$eventId];
        $arg = $this->eventArg[$eventId];
        $handle = $this->eventHandle[$eventId];

        $this->eventOff($eventId);

        unset(
            $this->events[$eventId],
            $this->eventType[$eventId],
            $this->eventArg[$eventId],
            $this->eventHandle[$eventId]
        );

        return function() use ($eventId, $callback, $type, $arg, $handle) {
            $this->events[$eventId] = $callback;
            $this->eventType[$eventId] = $type;
            $this->eventArg[$eventId] = $arg;
            $this->eventHandle[$eventId] = $handle;
            $this->eventOn($eventId);
        };
    }

    private function eventOn(int $eventId): void {
        switch ($this->eventType[$eventId]) {
            case self::READ:
                $this->readableOn($this->eventArg[$eventId], $eventId);
                break;
            case self::WRITE:
                $this->writableOn($this->eventArg[$eventId], $eventId);
                break;
            case self::SIGNAL:
                $this->signalOn($this->eventArg[$eventId], $eventId);
                break;
        }
    }

    private function eventOff(int $eventId): void {
        switch ($this->eventType[$eventId]) {
            case self::READ:
                $this->readableOff($this->eventArg[$eventId], $eventId);
                break;
            case self::WRITE:
                $this->writableOff($this->eventArg[$eventId], $eventId);
                break;
            case self::SIGNAL:
                $this->signalOff($this->eventArg[$eventId], $eventId);
                break;
        }
    }

    private function readableOn($resource, int $eventId): void {
        $fdId = \get_resource_id($resource);

        if (!isset($this->readStreams[$fdId])) {
            $this->loop->addReadStream($resource, function() use ($fdId, $resource) {
                if (!\is_resource($resource)) {
                    $this->loop->removeReadStream($resource);
                }
                if (!isset($this->readStreams[$fdId])) {
                    return;
                }
                foreach ($this->readStreams[$fdId] as $eventId) {
                    ++$this->deferredCount;
                    $this->loop->futureTick($this->events[$eventId]);
                }
            });
        }
        $this->readStreams[$fdId][$eventId] = $eventId;
    }

    private function readableOff($resource, int $eventId): void {
        $fdId = \get_resource_id($resource);
        unset($this->readStreams[$fdId][$eventId]);
        if (empty($this->readStreams[$fdId])) {
            unset($this->readStreams[$fdId]);
            $this->loop->removeReadStream($resource);
        }
    }

    private function writableOn($resource, int $eventId): void {
        $fdId = \get_resource_id($resource);

        if (!isset($this->writeStreams[$fdId])) {
            $this->loop->addWriteStream($resource, function() use ($fdId, $resource) {
                if (!\is_resource($resource)) {
                    $this->loop->removeWriteStream($resource);
                }
                if (!isset($this->writeStreams[$fdId])) {
                    return;
                }
                foreach ($this->writeStreams[$fdId] as $eventId) {
                    ++$this->deferredCount;
                    $this->loop->futureTick($this->events[$eventId]);
                }
            });
        }
        $this->writeStreams[$fdId][$eventId] = $eventId;
    }

    private function writableOff($resource, int $eventId): void {
        $fdId = \get_resource_id($resource);
        unset($this->writeStreams[$fdId][$eventId]);
        if (empty($this->writeStreams[$fdId])) {
            unset($this->writeStreams[$fdId]);
            $this->loop->removeWriteStream($resource);
        }
    }

    private function signalOn(int $signalNumber, int $eventId): void {
        $this->loop->addSignal($signalNumber, $this->events[$eventId]);
    }

    private function signalOff(int $signalNumber, int $eventId): void {
        $this->loop->removeSignal($signalNumber, $this->events[$eventId]);
    }

    private function wrap(Closure $callback, ...$args): Closure {
        return $wrapped = function() use ($callback, $args, &$wrapped) {
            if ($this->stopped) {
                $this->loop->futureTick($wrapped);
                return;
            }
            --$this->deferredCount;
            try {
                if ($this->microtaskStart < $this->microtaskEnd) {
                    $this->runMicrotasks();
                }
                $callback(...$args);
                if ($this->microtaskStart < $this->microtaskEnd) {
                    $this->runMicrotasks();
                }
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stop();
            }
        };
    }

    private function stop(): void {
        $this->stopped = true;
        $this->loop->stop();
    }

    private function runMicrotasks(): void {
        while (!empty($this->microtasks)) {
            foreach ($this->microtasks as $key => $callback) {
                if ($this->stopped) {
                    return;
                }
                $arg = $this->microtaskArg[$key];
                unset($this->microtasks[$key], $this->microtaskArg[$key]);
                try {
                    $callback($arg);
                } catch (\Throwable $e) {
                    ($this->exceptionHandler)($e);
                    $this->stop();
                    return;
                }
            }
        }
    }

}
