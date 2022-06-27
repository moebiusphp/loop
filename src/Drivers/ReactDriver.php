<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\RootEventLoopInterface;
use Moebius\Loop\Handler;
use React\EventLoop\Loop as ReactLoop;
use React\EventLoop\LoopInterface as ReactLoopInterface;

class ReactDriver implements RootEventLoopInterface {

    protected int $deferredHigh = 0;
    protected int $deferredLow = 0;

    protected array $microtasks = [], $microtaskArgs = [];
    protected int $micLow = 0, $micHigh = 0;
    protected array $readStreams = [];
    protected array $writeStreams =[];
    protected int $running = 0;
    protected bool $scheduled = false;
    protected int $incompleted = 0;
    private ReactLoopInterface $loop;
    protected int $wrapCount = 0;
/*
    private bool $shutdownDetected = false;
    protected bool $shutdownHandlerInstalled = false;
*/
    public function __construct(Closure $exceptionHandler) {
        $this->loop = ReactLoop::get();
        $this->exceptionHandler = $exceptionHandler;
        $this->scheduled = true;
        \register_shutdown_function($this->shutdownRun(...));
    }

    public function __destruct() {
        $this->run();
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
        ++$this->running;
        $running = $this->running;
        $this->loop->run();
        if ($running === $this->running) {
            --$this->running;
        }
    }

    public function stop(): void {
        if (0 === $this->running) {
            throw new \RuntimeException("ReactDriver: stop() without run()");
        }
        --$this->running;
        if (0 === $this->running) {
            $this->loop->stop();
        }
    }

    public function await(object $promise, ?float $timeLimit): mixed {
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
                $this->defer($again);
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
            }, static function($e) {});
        };
        $this->run();
        if ($state === true) {
            if ($timeLimiter && $value !== $promise) {
                $timeLimiter->cancel();
            }
            return $value;
        } elseif ($state === false) {
            throw $value;
        } else {
            throw new \LogicException("Promise never resolved, but event loop is empty");
        }
    }

    public function queueMicrotask(Closure $callback, mixed ...$args): void {
        if ($this->micHigh === $this->micLow) {
            $this->defer(static function() {});
        }
        $this->microtasks[$this->micHigh] = $callback;
        $this->microtaskArgs[$this->micHigh++] = $args;
    }

    public function defer(Closure $callable, mixed ...$args): void {
        if (!$this->scheduled) {
            $this->scheduled = true;
            \register_shutdown_function($this->shutdownRun(...));
        }
        $this->loop->futureTick($this->wrap($callable, ...$args));
    }

    public function delay(float $time, Closure $callback=null): Handler {
        if (!$this->scheduled) {
            $this->scheduled = true;
            \register_shutdown_function($this->shutdownRun(...));
        }
        $timer = null;
        [$handler, $fulfill] = Handler::create(static function() use (&$timer) {
            $this->loop->cancelTimer($timer);
        });
        $timer = $this->loop->addTimer($time, $this->wrap($fulfill, $this->getTime() + $time));
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }

    public function readable($resource, Closure $callback=null): Handler {
        if (!$this->scheduled) {
            $this->scheduled = true;
            \register_shutdown_function($this->shutdownRun(...));
        }
        $id = \get_resource_id($resource);
        if (isset($this->readStreams[$id])) {
            throw new \LogicException("Already subscribed to this resource");
        }
        $this->readStreams[$id] = $resource;
        $cancel = function() use ($id, $resource) {
            $this->loop->removeReadStream($resource);
            unset($this->readStreams[$id]);
        };
        [$handler, $fulfill] = Handler::create($cancel);
        $fulfill = $this->wrap($fulfill, $resource);

        $this->loop->addReadStream($resource, function() use ($cancel, $fulfill, $resource) {
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
            $this->scheduled = true;
            \register_shutdown_function($this->shutdownRun(...));
        }
        $id = \get_resource_id($resource);
        if (isset($this->writeStreams[$id])) {
            throw new \LogicException("Already subscribed to this resource");
        }
        $this->writeStreams[$id] = $resource;
        $cancel = function() use ($id, $resource, &$cancelled) {
            $this->loop->removeWriteStream($resource);
            unset($this->writeStreams[$id]);
        };
        [$handler, $fulfill] = Handler::create($cancel);
        $fulfill = $this->wrap($fulfill, $resource);

        $this->loop->addWriteStream($resource, function() use ($cancel, $fulfill, $resource) {
            $cancel();
            $fulfill();
        });

        if ($callback) {
            $handler->then($callback);
        }

        return $handler;
    }

    protected function wrap(Closure $callback, mixed ...$args): Closure {
        ++$this->wrapCount;
        return function() use ($callback, $args) {
            --$this->wrapCount;
            ++$this->incompleted;
            try {
                if ($this->micLow < $this->micHigh) {
                    $this->runMicrotasks();
                }
                $callback(...$args);
                $this->runMicrotasks();
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stop();
            } finally {
                --$this->incompleted;
            }
        };
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
