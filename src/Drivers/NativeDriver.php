<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\RootEventLoopInterface;
use Moebius\Loop\Handler;
use Moebius\Loop\Util\TimerQueue;
use Moebius\Loop\Util\Timer;
use Charm\FallbackLogger;

class NativeDriver implements RootEventLoopInterface {

    protected Closure $exceptionHandler;
    protected TimerQueue $timers;

    protected array $deferred = [];
    protected array $deferredArgs = [];
    protected int $defLow = 0, $defHigh = 0;

    protected array $microtasks = [], $microtaskArgs = [];
    protected int $micLow = 0, $micHigh = 0;

    protected array $readStreams = [];
    protected array $readListeners = [];

    protected array $writeStreams = [];
    protected array $writeListeners = [];

    protected bool $stopped = false;
    protected ?\Psr\Log\LoggerInterface $debug = null;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->timers = new TimerQueue();
        $this->debug = \getenv("MOEBIUS_DEBUG") ? FallbackLogger::get() : null;
        \register_shutdown_function($this->run(...));
    }

    public function getTime(): float {
        return hrtime(true) / 1_000_000_000;
    }

    public function await(object $promise, ?float $timeLimit): mixed {
        $state = null;
        $value = null;
        $promise->then(static function($result) use (&$state, &$value) {
            if ($state === null) {
                $state = true;
                $value = $result;
            }
        }, static function($reason) use (&$state, &$value) {
            if ($state === null) {
                $state = false;
                $value = $reason;
            }
        });

        $timeLimiter = null;

        if ($timeLimit !== null) {
            $timeLimiter = $this->delay($timeLimit);
            $timeLimiter->then(static function() use (&$state, &$value, $promise) {
                if ($state === null) {
                    $state = true;
                    $value = $promise;
                }
            }, static function() {
                // this function prevents logging of cancelled promises
            });
        }

        $this->defer($checker = function() use (&$state, &$checker, $promise) {
            if ($state === null) {
                // keep polling until the promise is resolved
                $this->defer($checker);
            } else {
                // stop the loop, we've got a result
                $this->stop();
            }
        });

        $this->run();

        if ($state === true) {
            if ($value !== $promise && $timeLimiter) {
                $timeLimiter->cancel();
            }
            return $value;
        } elseif ($state === false) {
            throw $value;
        } else {
            throw new \LogicException("Promise never resolved, but event loop is empty");
        }
    }

    public function run(): void {
        $this->stopped = false;

        $tickTime = 0;

        while (
            ($this->defLow < $this->defHigh) ||
            ($this->micLow < $this->micHigh) ||
            !empty($this->readStreams) ||
            !empty($this->writeStreams) ||
            !$this->timers->isEmpty()
        ) {
            if (
                ($this->defLow < $this->defHigh) ||
                ($this->micLow < $this->micHigh)
            ) {
                $availableTime = 0;
            } else {
                $nextTime = $this->timers->getNextTime() ?? $this->getTime() + 0.25;
                $availableTime = max(0, min(0.1, $nextTime - $this->getTime()));
            }

            $this->debug?->debug("NativeDriver: availableTime={availableTime} deferred={deferred} microtasks={microtasks} reads={reads} writes={writes} timers={timers}", [
                'availableTime' => $availableTime,
                'deferred' => $this->defHigh - $this->defLow,
                'microtasks' => $this->micHigh - $this->micLow,
                'reads' => count($this->readStreams),
                'writes' => count($this->writeStreams),
                'timers' => $this->timers->isEmpty() ? 0 : 1,
            ]);

            if (!empty($this->readStreams) || !empty($this->writeStreams)) {
                $read = $this->readStreams;
                $write = $this->writeStreams;
                $void = [];
                $count = \stream_select($read, $write, $void, 0, 1_000_000 * $availableTime);
                if ($count > 0) {
                    foreach ($read as $resource) {
                        $id = \get_resource_id($resource);
                        $this->queueMicrotask($this->readListeners[$id], $resource);
                        unset($this->readStreams[$id], $this->readListeners[$id]);
                    }
                    foreach ($write as $resource) {
                        $id = \get_resource_id($resource);
                        $this->queueMicrotask($this->writeListeners[$id], $resource);
                        unset($this->writeStreams[$id], $this->writeListeners[$id]);
                    }
                }
            } elseif ($tickTime < 5000) {
                // if a tick takes less than 5 microseconds, it generally means no real
                // work was done
                usleep($availableTime * 1_000_000);
            }
            $tickStart = hrtime(true);

            try {
                $this->runMicrotasks();
                $this->runTimers();
                $this->runDeferred();
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stop();
            }

            $tickTime = hrtime(true) - $tickStart;

            if ($this->stopped) {
                break;
            }
        }
    }

    public function stop(): void {
        $this->stopped = true;
    }

    public function defer(Closure $callback, mixed ...$args): void {
        $this->deferred[$this->defHigh] = $callback;
        $this->deferredArgs[$this->defHigh++] = $args;
    }

    public function queueMicrotask(Closure $callback, mixed ...$args): void {
        $this->microtasks[$this->micHigh] = $callback;
        $this->microtaskArgs[$this->micHigh++] = $args;
    }

    public function delay(float $time): Handler {
        $cancelFunction = null;

        [$handler, $fulfill] = Handler::create(static function() use (&$cancelFunction) {
            $cancelFunction();
        });

        $timer = Timer::create($this->getTime() + $time, $fulfill);
        $cancelFunction = $timer->cancel(...);

        $this->timers->enqueue($timer);

        return $handler;
    }

    public function readable($resource): Handler {
        $id = \get_resource_id($resource);
        if (isset($this->readStreams[$id])) {
            throw new \LogicException("Already read-watching this stream resource");
        }

        [$handler, $fulfill] = Handler::create(function() use ($id) {
            unset($this->readStreams[$id], $this->readListeners[$id]);
        });

        $this->readStreams[$id] = $resource;
        $this->readListeners[$id] = $fulfill;

        return $handler;
    }

    public function writable($resource): Handler {
        $id = \get_resource_id($resource);
        if (isset($this->writeStreams[$id])) {
            throw new \LogicException("Already write-watching this stream resource");
        }

        [$handler, $fulfill] = Handler::create(function() use ($id) {
            unset($this->writeStreams[$id], $this->writeListeners[$id]);
        });

        $this->writeStreams[$id] = $resource;
        $this->writeListeners[$id] = $fulfill;

        return $handler;
    }

    protected function runTimers(): void {
        $time = $this->getTime();
        while (!$this->timers->isEmpty() && $this->timers->getNextTime() < $time) {
            $timer = $this->timers->dequeue();
            $this->defer($timer->callback, $timer->time);
//            ($timer->callback)($timer->time);
//            $this->runMicrotasks();
        }
    }

    protected function runDeferred(): void {
        $defHigh = $this->defHigh;
        while ($this->defLow < $defHigh) {
            $callback = $this->deferred[$this->defLow];
            $args = $this->deferredArgs[$this->defLow];
            unset($this->deferred[$this->defLow], $this->deferredArgs[$this->defLow]);
            ++$this->defLow;
            $callback(...$args);
            $this->runMicrotasks();
        }
    }

    protected function runMicrotasks(): void {
        while ($this->micLow < $this->micHigh) {
            $callback = $this->microtasks[$this->micLow];
            $args = $this->microtaskArgs[$this->micLow];
            unset($this->microtasks[$this->micLow], $this->microtaskArgs[$this->micLow]);
            ++$this->micLow;
            $callback(...$args);
        }
    }

}
