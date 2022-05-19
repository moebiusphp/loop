<?php
namespace Co\Loop\Drivers;

use Closure;
use Co\Loop\EventHandle;
use Co\Loop\DriverInterface;

class AmpDriver implements DriverInterface {

    const READ = 0;
    const WRITE = 1;
    const SIGNAL = 2;
    const DELAY = 3;

    private Closure $exceptionHandler;

    private $loop;

    private bool $scheduled = false;
    private bool $stopped = false;

    private int $deferredCount = 0;

    private array $microtasks = [];
    private array $microtaskArg = [];
    private int $microtaskStart = 0;
    private int $microtaskEnd = 0;

    private int $eventId = 0;
    private array $args = [];
    private array $callbacks = [];
    private array $types = [];
    private array $handles = [];

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->loop = \Amp\Loop::get();
    }

    public function getTime(): float {
        return $this->loop->now() / 1000;
    }

    public function run(Closure $shouldResumeFunc=null): void {
        $this->stopped = false;

        if ($shouldResumeFunc === null) {
            $this->loop->run();
        } else {
            for (;;) {
                $this->loop->defer($this->loop->stop(...));
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

    public function defer(Closure $callback): void {
        ++$this->deferredCount;
        $this->loop->defer($this->wrap($callback, true));
        $this->schedule();
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
        $this->args[$eventId] = $time;
        $this->callbacks[$eventId] = $this->wrap($callback, false);
        $this->types[$eventId] = self::DELAY;
        $this->handles[$eventId] = $this->loop->delay(intval($time * 1000), function() use ($eventId) {
            if (isset($this->callbacks[$eventId])) {
                ($this->callbacks[$eventId])();
            }
            unset($this->callbacks[$eventId], $this->handles[$eventId]);
        });

        return EventHandle::for($this, $eventId);
    }

    public function readable($resource, Closure $callback): EventHandle {
        $eventId = $this->eventId++;
        $this->args[$eventId] = $resource;
        $this->callbacks[$eventId] = $this->wrap($callback, false, $resource);
        $this->types[$eventId] = self::READ;
        $this->handles[$eventId] = $this->loop->onReadable($resource, function() use ($eventId) {
            if (isset($this->callbacks[$eventId])) {
                ($this->callbacks[$eventId])();
            }
        });
        return EventHandle::for($this, $eventId);
    }

    public function writable($resource, Closure $callback): EventHandle {
        $eventId = $this->eventId++;
        $this->args[$eventId] = $resource;
        $this->callbacks[$eventId] = $this->wrap($callback, false, $resource);
        $this->types[$eventId] = self::WRITE;
        $this->handles[$eventId] = $this->loop->onWritable($resource, function() use ($eventId) {
            if (isset($this->callbacks[$eventId])) {
                ($this->callbacks[$eventId])();
            }
        });
        return EventHandle::for($this, $eventId);
    }

    public function signal($signalNumber, Closure $callback): EventHandle {
        $eventId = $this->eventId++;
        $this->args[$eventId] = $signalNumber;
        $this->callbacks[$eventId] = $this->wrap($callback, false, $signalNumber);
        $this->types[$eventId] = self::SIGNAL;
        $this->handles[$eventId] = $this->loop->onSignal($signalNumber, function() use ($eventId) {
            if (isset($this->callbacks[$eventId])) {
                ($this->callbacks[$eventId])();
            }
        });
        return EventHandle::for($this, $eventId);
    }

    public function cancel(int $eventId): void {
        if (isset($this->handles[$eventId])) {
            $this->loop->cancel($this->handles[$eventId]);
            unset($this->callbacks[$eventId], $this->handles[$eventId]);
        }
    }

    public function suspend(int $eventId): ?Closure {
        if (!isset($this->handles[$eventId])) {
            return null;
        }
        if ($this->types[$eventId] === self::DELAY) {
            return null;
        }
        $arg = $this->args[$eventId];
        $callback = $this->callbacks[$eventId];
        $type = $this->types[$eventId];
        $handle = $this->handles[$eventId];
        $this->loop->cancel($handle);
        return function() use ($eventId, $arg, $callback, $type) {
            $this->args[$eventId] = $arg;
            $this->callbacks[$eventId] = $callback;
            $this->types[$eventId] = $type;
            switch ($type) {
                case self::READ:
                    $this->handles[$eventId] = $this->loop->onReadable($arg, $callback);
                    break;
                case self::WRITE:
                    $this->handles[$eventId] = $this->loop->onWritable($arg, $callback);
                    break;
                case self::SIGNAL:
                    $this->handles[$eventId] = $this->loop->onSignal($arg, $callback);
                    break;
            }
        };
    }

    private function schedule(): void {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        \register_shutdown_function(function() {
            $this->scheduled = false;
            $this->loop->run();
        });
    }

    private function stop(): void {
        $this->stopped = true;
        $this->loop->stop();
    }

    private function wrap(Closure $callback, bool $updateCount, ...$args): Closure {
        return $wrapped = function() use ($callback, $updateCount, $args, &$wrapped) {
            if ($this->stopped) {
                $this->loop->defer($wrapped);
                return;
            }
            if ($updateCount) {
                --$this->deferredCount;
            }
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
