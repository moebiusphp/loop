<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\DriverInterface;
use Moebius\Loop\Handler;
use React\EventLoop\Loop;

class ReactDriver implements DriverInterface {

    protected int $deferredHigh = 0;
    protected int $deferredLow = 0;
    protected int $pollOffset = -1;

    protected array $microtasks = [], $microtaskArgs = [];
    protected int $micLow = 0, $micHigh = 0;
    protected array $readStreams = [];
    protected array $writeStreams =[];
    protected bool $stopped = false;
    protected bool $scheduled = false;
    protected int $incompleted = 0;
/*
    private bool $shutdownDetected = false;
    protected bool $shutdownHandlerInstalled = false;
*/
    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->scheduled = true;
        \register_shutdown_function(self::shutdownRun(...));
/*
        \register_shutdown_function(function() {
            $this->shutdownDetected = true;
        });
*/
    }

    private function shutdownRun(): void {
        $this->scheduled = false;
        if ($this->incompleted > 0) {
            // Don't run the event loop if a callback was incompleted (die() was called)
            return;
        }
        $this->run();
    }

    public function getTime(): float {
        return \hrtime(true) / 1_000_000_000;
    }

    public function run(): void {
        $this->stopped = false;
        Loop::run();
    }

    public function stop(): void {
/*
        if (!$this->shutdownDetected && !$this->shutdownHandlerInstalled) {
            \register_shutdown_function($this->run(...));
        }
*/
        $this->stopped = true;
        Loop::stop();
    }

    public function await(object $promise): mixed {
        $state = null;
        $value = null;
        $promise->then(static function($result) use (&$state, &$value) {
            if ($state === null) {
                $state = true;
                $value = $result;
            }
        }, static function($result) use (&$state, &$value) {
            if ($state === null) {
                $state = false;
                $value = $result;
            }
        });
        $this->defer($again = function() use (&$state, &$poll, &$again) {
            if ($state !== null) {
                $this->stop();
            } else {
                $this->poll($again);
            }
        });
        $this->run();
        if ($state === true) {
            return $value;
        } elseif ($state === false) {
            throw $value;
        } else {
            throw new \LogicException("Promise never resolved, but event loop is empty");
        }
    }

    public function queueMicrotask(Closure $callback, mixed $argument=null): void {
        $this->microtasks[$this->micHigh] = $callback;
        $this->microtaskArgs[$this->micHigh++] = $argument;
    }

    public function defer(Closure $callable): void {
        if (!$this->scheduled) {
            \register_shutdown_function($this->shutdownRun(...));
        }
        $this->deferredHigh++;
        Loop::futureTick($this->wrap($callable, null, true));
    }

    public function poll(Closure $callable): void {
        $this->defer($callable);
    }

    public function delay(float $time, Closure $callback=null): Handler {
        if (!$this->scheduled) {
            \register_shutdown_function($this->shutdownRun(...));
        }
        $timer = null;
        [$handler, $fulfill] = Handler::create(static function() use (&$timer) {
            Loop::cancelTimer($timer);
        });
        $timer = Loop::addTimer($time, $this->wrap($fulfill, $this->getTime() + $time));
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }

    public function readable($resource, Closure $callback=null): Handler {
        if (!$this->scheduled) {
            \register_shutdown_function($this->shutdownRun(...));
        }
        $id = \get_resource_id($resource);
        if (isset($this->readStreams[$id])) {
            throw new \LogicException("Already subscribed to this resource");
        }
        $this->readStreams[$id] = $resource;
        $cancel = function() use ($id, $resource) {
            Loop::removeReadStream($resource);
            unset($this->readStreams[$id]);
        };
        [$handler, $fulfill] = Handler::create($cancel);
        $fulfill = $this->wrap($fulfill, $resource);

        Loop::addReadStream($resource, function() use ($cancel, $fulfill, $resource) {
            $cancel();
            $fulfill();
        });

        if ($callback) {
            $handler->then($callback);
        }

        return $handler;
    }

    public function writable($resource, Closure $callback=null): Handler {
        if (!$this->scheduled) {
            \register_shutdown_function($this->shutdownRun(...));
        }
        $id = \get_resource_id($resource);
        if (isset($this->writeStreams[$id])) {
            throw new \LogicException("Already subscribed to this resource");
        }
        $this->writeStreams[$id] = $resource;
        $cancel = function() use ($id, $resource, &$cancelled) {
            Loop::removeWriteStream($resource);
            unset($this->writeStreams[$id]);
        };
        [$handler, $fulfill] = Handler::create($cancel);
        $fulfill = $this->wrap($fulfill, $resource);

        Loop::addWriteStream($resource, function() use ($cancel, $fulfill, $resource) {
            $cancel();
            $fulfill();
        });

        if ($callback) {
            $handler->then($callback);
        }

        return $handler;
    }

    protected function wrap(Closure $callback, mixed $argument=null, bool $deferred=false): Closure {
        return function() use ($callback, $argument, $deferred) {
            ++$this->incompleted;
            if ($deferred) {
                ++$this->deferredLow;
            }

            try {
                if ($this->micLow < $this->micHigh) {
                    $this->runMicrotasks();
                }
                if ($this->stopped) {
                    --$this->incompleted;
                    return;
                }
                $callback($argument);
                $this->runMicrotasks();
                --$this->incompleted;
            } catch (\Throwable $e) {
                --$this->incompleted;
                ($this->exceptionHandler)($e);
                $this->stop();
            }
        };
    }

    protected function runMicrotasks(): void {
        while (!$this->stopped && $this->micLow < $this->micHigh) {
            $callback = $this->microtasks[$this->micLow];
            $argument = $this->microtaskArgs[$this->micLow];
            unset($this->microtasks[$this->micLow], $this->microtaskArgs[$this->micLow]);
            ++$this->micLow;
            $callback($argument);
        }
    }
}
