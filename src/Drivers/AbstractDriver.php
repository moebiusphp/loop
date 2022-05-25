<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\{
    Event,
    EventHandle,
    DriverInterface,
    InterruptedException
};

abstract class AbstractDriver implements DriverInterface {

    protected bool $stopped = false;
    protected bool $scheduled = false;

    private Closure $exceptionHandler;
    private Closure $shutdownFunction;
    private static int $eventId = 0;
    private array $events = [];

    private array $microtasks = [], $microtaskArgs = [];
    private int $microtaskLow = 0, $microtaskHigh = 0;

    private array $deferred = [];
    private int $deferredLow = 0, $deferredHigh = 0;

    abstract public function getTime(): float;

    abstract protected function tick(): void;

    abstract protected function scheduleOn(Event $event): void;

    abstract protected function scheduleOff(Event $event): void;

    public function __construct(Closure $exceptionHandler, Closure $shutdownFunction) {
        $this->exceptionHandler = $exceptionHandler;
        $this->shutdownFunction = $shutdownFunction;
    }

    public function defer(Closure $callback): void {
        $this->deferred[$this->deferredHigh++] = $callback;
        $this->schedule();
    }

    public function queueMicrotask(Closure $callback, mixed $argument=null): void {
        $this->microtasks[$this->microtaskHigh] = $callback;
        $this->microtaskArgs[$this->microtaskHigh++] = $argument;
        $this->schedule();
    }

    public final function run(Closure $shouldResumeFunction=null): void {
        if ($this->stopped) {
            return;
        }
        do {
//echo "stopped=".json_encode($this->stopped)." dlow=".$this->deferredLow." dhigh=".$this->deferredHigh." mlow=".$this->microtaskLow." mhigh=".$this->microtaskHigh." events=".count($this->events)."\n";
            $this->tick();
            if ($this->stopped || $shouldResumeFunction && !$shouldResumeFunction()) {
                break;
            }
        } while ($this->hasImmediateWork() || $this->hasAsyncWork());

        if ($this->stopped && $shouldResumeFunction) {
            throw new InterruptedException("Running of the event loop was interrupted");
        }

        if ($this->hasImmediateWork() || $this->hasAsyncWork()) {
            $this->schedule();
        }
    }

    /**
     * Schedule a callback to fire whenever a stream resource is determined to be readable
     * at the beginning of each tick of the event loop.
     *
     * The callback will continue ticking until the event is suspended or cancelled.
     */
    public final function readable($resource, Closure $callback): EventHandle {
        $event = Event::create(self::$eventId++, Event::READABLE, $resource, $callback);
        $this->events[$event->id] = $event;
        $this->scheduleOn($event);
        $this->schedule();
        return EventHandle::create($this, $event->id);
    }

    /**
     * Schedule a callback to fire whenever a stream resource is determined to be writable
     * at the beginning of each tick of the event loop.
     *
     * The callback will continue ticking until the event is suspended or cancelled.
     */
    public final function writable($resource, Closure $callback): EventHandle {
        $event = Event::create(self::$eventId++, Event::WRITABLE, $resource, $callback);
        $this->events[$event->id] = $event;
        $this->scheduleOn($event);
        $this->schedule();
        return EventHandle::create($this, $event->id);
    }

    /**
     * Schedule a callback to fire after a specific timeout in seconds.
     *
     * The callback must be suspended by calling $this->suspend($event->id) immediately after ticking.
     */
    public final function delay(float $delay, Closure $callback): EventHandle {
        $event = Event::create(self::$eventId++, Event::TIMER, $delay, $callback);
        $this->events[$event->id] = $event;
        $this->scheduleOn($event);
        $this->schedule();
        return EventHandle::create($this, $event->id);
    }

    /**
     * Schedule a callback to fire after $interval seconds and then to continue ticking
     * every $interval seconds.
     */
    public final function interval(float $interval, Closure $callback): EventHandle {
        $event = Event::create(self::$eventId++, Event::INTERVAL, $interval, function() use ($callback, &$event) {
            $this->defer(static function() use ($callback, $event) {
                $callback($event->value);
            });
        }, $this->getTime() + $interval);
        $this->events[$event->id] = $event;
        $this->scheduleOn($event);
        $this->schedule();
        return EventHandle::create($this, $event->id);
    }

    /**
     * Schedule a callback to fire whenever the process receives a POSIX signal. The
     * callback will run at the next tick following the signal being received.
     */
    public final function signal(int $signalNumber, Closure $callback): EventHandle {
        $event = Event::create(self::$eventId++, Event::SIGNAL, $signalNumber, $callback);
        $this->events[$event->id] = $event;
        $this->scheduleOn($event);
        $this->schedule();
        return EventHandle::create($this, $event->id);
    }

    public final function cancel(int $eventId): void {
        if (!isset($this->events[$eventId])) {
            return;
        }
        $this->scheduleOff($this->events[$eventId]);
        unset($this->events[$eventId]);
    }

    public final function suspend(int $eventId): ?Closure {
        if (!isset($this->events[$eventId])) {
            return null;
        }
        $event = $this->events[$eventId];
        $this->scheduleOff($event);
        unset($this->events[$eventId]);
        return function() use ($event) {
            $this->events[$event->id] = $event;
            $this->scheduleOn($event);
        };
    }


    protected final function schedule(): void {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        ($this->shutdownFunction)(function() {
            $this->scheduled = false;
            $this->run();
        });
    }

    protected function hasImmediateWork(): bool {
        $hasImmediateWork =
            $this->deferredLow < $this->deferredHigh ||
            $this->microtaskLow < $this->microtaskHigh;
        return $hasImmediateWork;
    }

    protected function hasAsyncWork(): bool {
        $hasAsyncWork = !empty($this->events);
        return $hasAsyncWork;
    }

    protected function handleException(\Throwable $e): void {
        ($this->exceptionHandler)($e);
    }

    protected function runDeferred(): void {
        $this->runMicrotasks();
        $deferredHigh = $this->deferredHigh;
        try {
            while (!$this->stopped && $this->deferredLow < $deferredHigh) {
                $callback = $this->deferred[$this->deferredLow];
                unset($this->deferred[$this->deferredLow++]);
                $callback();
                $this->runMicrotasks();
            }
        } catch (\Throwable $e) {
            $this->stop();
            $this->handleException($e);
            return;
        }
    }

    protected function runMicrotasks(): void {
        try {
            while (!$this->stopped && $this->microtaskLow < $this->microtaskHigh) {
                $callback = $this->microtasks[$this->microtaskLow];
                $arg = $this->microtaskArgs[$this->microtaskLow];
                unset($this->microtasks[$this->microtaskLow], $this->microtaskArgs[$this->microtaskLow]);
                ++$this->microtaskLow;
                $callback($arg);
            }
        } catch (\Throwable $e) {
            $this->stop();
            $this->handleException($e);
            return;
        }
    }
}
