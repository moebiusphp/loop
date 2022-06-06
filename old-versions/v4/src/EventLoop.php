<?php
namespace Moebius\Loop;

use Charm\Util\ClosureTool;
use Fiber;
use Closure;
use Moebius\Loop\Util\{
    Timer,
    TimerQueue,
    Listeners
};

class EventLoop {

    private ?DriverInterface $driver;

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

    private ?Listeners $readers = null;
    private ?Listeners $writers = null;

    private ?Listeners $signals = null;

    public function __construct(Closure $exceptionHandler) {
        $this->driver = Factory::getDriver();
        $this->exceptionHandler = $exceptionHandler;
        $this->timers = new TimerQueue();
        $this->readers = new Listeners($this->driver->readable(...), $this->defer(...), \get_resource_id(...));
        $this->writers = new Listeners($this->driver->writable(...), $this->defer(...), \get_resource_id(...));
        $this->signals = new Listeners($this->driver->signal(...), $this->defer(...), \intval(...));
        $this->keepAliveUntilEnd();
    }

    public function getState(): array {
        return [
            'deferred' => count($this->deferred),
            'defLow' => $this->deferredLow,
            'defHigh' => $this->deferredHigh,
            'microtasks' => count($this->microtasks),
            'timers' => !$this->timers?->isEmpty(),
            'readers' => !$this->readers?->isEmpty(),
            'writers' => !$this->writers?->isEmpty(),
            'signals' => !$this->signals?->isEmpty(),
        ];
    }

    private function hasWork(): bool {
        return
            !empty($this->deferred) ||
            !empty($this->microtasks) ||
            !$this->timers->isEmpty() ||
            !$this->readers->isEmpty() ||
            !$this->writers->isEmpty() ||
            !$this->signals->isEmpty();
    }

    /**
     * This function is designed to postpone garbage collection for the event loop. It will store a reference
     * to itself via a shutdown handler and renew that shutdown handler for as long as there are tasks scheduled.
     */
    private function keepAliveUntilEnd() {
        static $count = 100;
        if ($this->hasWork()) {
print_r($this->getState());
sleep(1);
            \register_shutdown_function($this->keepAliveUntilEnd(...));
        } elseif (--$count >= 0) {
            \register_shutdown_function($this->keepAliveUntilEnd(...));
        }
    }

    public function __destruct() {
        $this->driver->stop();
    }

    public function getTime(): float {
        return $this->driver->getTime();
    }

    private int $runDepth = 0;
    /**
     * Function runs the event loop until $this->stop
     */
    public function run(Closure $shouldContinueFunction=null) {
        ++$this->runDepth;
        if ($shouldContinueFunction) {
            $this->defer($again = function() use (&$again, $shouldContinueFunction) {
                if ($shouldContinueFunction()) {
                    $this->defer($again);
                } else {
                    $this->driver->stop();
                }
            });
        }
        $this->driver->run();
        --$this->runDepth;
    }

    /**
     * Schedule a job to run immediately
     */
    public function defer(Closure $callback): void {
        //echo "defer ".(new ClosureTool($callback))."\n";
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
echo "runDeferred()\n";
        $this->scheduled = false;
        $deferredHigh = $this->deferredHigh;
        try {
            $this->runMicrotasks();
            while (!$this->stopped && $this->deferredLow < $deferredHigh) {
                $closure = $this->deferred[$this->deferredLow];
                unset($this->deferred[$this->deferredLow++]);
                //echo " - running ".(new ClosureTool($closure))."\n";
                if ($this->microtaskLow < $this->microtaskHigh) {
                    $this->runMicrotasks();
                }
            }
        } catch (\Throwable $e) {
            //echo $e->getMessage()."\n";
            $this->stopped = true;
            ($this->exceptionHandler)($e);
        }
        if ($this->deferredLow < $this->deferredHigh) {
            if (!$this->scheduled) {
                $this->scheduled = true;
                $this->driver->defer($this->runDeferred(...));
            }
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
