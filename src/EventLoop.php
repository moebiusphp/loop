<?php
namespace Moebius\Loop;

use Closure;
use Moebius\Loop\Util\{
    Timer,
    TimerQueue,
    Listeners
};

class EventLoop {

    private DriverInterface $driver;

    private bool $stopped = false;

    /**
     * True if $this->runDeferred(...) has been scheduled in the driver
     */
    private bool $scheduled = false;
    private array $deferred = [];
    private int $deferredLow = 0, $deferredHigh = 0;

    private array $microtasks = [];
    private array $microtaskArgs = [];
    private int $microtaskLow = 0, $microtaskHigh = 0;

    /**
     * Time if $this->runTimers(...) has been scheduled in the driver
     */
    private ?float $nextTimer = null;
    private TimerQueue $timers;
    private ?Closure $cancelTimer = null;

    private Listeners $readers;
    private Listeners $writers;

    private Listeners $signals;

    public function __construct(Closure $exceptionHandler) {
        $this->driver = Factory::getDriver();
        $this->exceptionHandler = $exceptionHandler;
        $this->timers = new TimerQueue();
        $this->readers = new Listeners($this->driver->readable(...), $this->defer(...), \get_resource_id(...));
        $this->writers = new Listeners($this->driver->writable(...), $this->defer(...), \get_resource_id(...));
        $this->signals = new Listeners($this->driver->signal(...), $this->defer(...), \intval(...));
    }

    public function getTime(): float {
        return $this->driver->getTime();
    }

    public function run(Closure $shouldResumeFunction=null) {
        if ($shouldResumeFunction !== null) {
            $this->defer($again = function() use ($shouldResumeFunction, &$again) {
                if (!$shouldResumeFunction()) {
                    $this->driver->stop();
                } else {
                    $this->defer($again);
                }
            });
            $this->driver->run();
        } else {
            $this->driver->run();
        }
    }

    /**
     * Schedule a job to run immediately
     */
    public function defer(Closure $callback): void {
        $this->deferred[$this->deferredHigh++] = $callback;
        if (!$this->scheduled) {
            $this->scheduled = true;
            $this->driver->defer($this->runDeferred(...));
        }
    }

    public function queueMicrotask(Closure $callback, mixed $argument=null): void {
        $this->microtasks[$this->microtaskHigh] = $callback;
        $this->microtaskArgs[$this->microtaskHigh] = $argument;
        ++$this->microtaskHigh;
        if (!$this->scheduled) {
            $this->scheduled = true;
            $this->driver->defer($this->runDeferred(...));
        }
    }

    public function delay(float $delay, Closure $callback): Closure {
        $time = $this->getTime() + $delay;
        $timer = new Timer($time, $callback);
        $this->timers->enqueue($timer);
        $this->scheduleTimers();
        return $timer->cancel(...);
    }

    public function interval(float $interval, Closure $callback): Closure {
        $stopper = null;
        $nextTime = $this->getTime() + $interval;

        $runner = function() use (&$nextTime, $interval, &$stopper, &$runner, $callback) {
            $now = $this->getTime();
            while ($nextTime < $now) {
                $nextTime += $interval;
            }
            $delay = $nextTime - $now;
            $stopper = $this->delay($delay, $runner);
            $this->queueMicrotask($callback, $nextTime);
        };

        $stopper = $this->delay($interval, $runner);

        return function() use (&$stopper) {
            $stopper();
        };
    }

    public function readable($resource, Closure $callback): Closure {
        return $this->readers->add($resource, $callback);
    }

    public function writable($resource, Closure $callback): Closure {
        return $this->writers->add($resource, $callback);
    }

    public function signal(int $signalNumber, Closure $callback): Closure {
        return $this->signals->add($signalNumber, $callback);
    }

    private function runDeferred(): void {
        $this->scheduled = false;
        $deferredHigh = $this->deferredHigh;
        try {
            $this->runMicrotasks();
            while (!$this->stopped && $this->deferredLow < $deferredHigh) {
                ($this->deferred[$this->deferredLow++])();
                if ($this->microtaskLow < $this->microtaskHigh) {
                    $this->runMicrotasks();
                }
            }
        } catch (\Throwable $e) {
            $this->stopped = true;
            ($this->exceptionHandler)($e);
        }
    }

    private function runMicrotasks(): void {
        while (!$this->stopped && $this->microtaskLow < $this->microtaskHigh) {
            $task = $this->microtasks[$this->microtaskLow];
            $arg = $this->microtaskArgs[$this->microtaskLow];
            unset($this->microtasks[$this->microtaskLow], $this->microtaskArgs[$this->microtaskLow]);
            ++$this->microtaskLow;
            $task($arg);
        }
    }

    private function runTimers(): void {
        $this->nextTimer = null;
        $this->cancelTimer = null;
        $loopTime = $this->getTime();
        while (!$this->timers->isEmpty() && $this->timers->getNextTime() < $loopTime) {
            $timer = $this->timers->dequeue();
            $this->defer($timer->callback);
        }
        if (!$this->timers->isEmpty()) {
            $this->scheduleTimers();
        }
    }

    private function scheduleTimers(): void {
        $nextTimer = $this->timers->getNextTime();
        if ($this->nextTimer === null || $this->nextTimer > $nextTimer) {
            if ($this->cancelTimer) {
                ($this->cancelTimer)();
            }
            if ($nextTimer === null) {
                return;
            }
            $this->nextTimer = $nextTimer;
            $delay = $this->nextTimer - $this->getTime();
            $this->cancelTimer = $this->driver->delay($delay, $this->runTimers(...));
        }
    }
}
