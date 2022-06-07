<?php
namespace Moebius\Loop;

use Closure;

class EventLoop implements DriverInterface {

    const NOT_SCHEDULED = 0;
    const NEED_SCHEDULING = 1;
    const SCHEDULED = 2;

    protected array $deferred = [];
    protected int $defLow = 0, $defHigh = 0;

    protected array $microtasks = [];
    protected array $microtaskArgs = [];

    protected DriverInterface $parent;
    protected int $micLow = 0, $micHigh = 0;
    protected ?Closure $exceptionHandler = null;
    protected int $suspended = false;
    protected int $scheduled = self::NOT_SCHEDULED;

    public function __construct(DriverInterface $parent) {
        $this->parent = $parent;
    }

    private function schedule(): void {
        if ($this->scheduled === self::NOT_SCHEDULED) {
            if ($this->parent && !$this->suspended) {
                $this->parent->defer($this->tick(...));
                $this->scheduled = self::SCHEDULED;
            } else {
                $this->scheduled = self::NEED_SCHEDULING;
            }
        }
    }

    private function tick(): void {
        $this->scheduled = self::NOT_SCHEDULED;
        try {
            $defHigh = $this->defHigh;
            while (!$this->stopped && ($this->defLow < $defHigh || $this->micLow < $this->micHigh)) {
                if ($this->micLow === $this->micHigh) {
                    $callback = $this->deferred[$this->defLow];
                    unset($this->deferred[$this->defLow++]);
                    $callback();
                }
                while !$this->stopped && ($this->micLow < $this->micHigh) {
                    $callback = $this->microtasks[$this->micLow];
                    $arg = $this->microtasks[$this->micLow];
                    unset($this->microtasks[$this->micLow], $this->microtaskArgs[$this->micLow]);
                    ++$this->micLow;
                    $callback($arg);
                }
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        } finally {
            if ($this->defLow < $this->defHigh || $this->micLow < $this->micHigh) {
                $this->schedule();
            }
        }
    }

    public function getTime(): float {
        return $this->parent->getTime();
    }

    public function run(): void {
        $this->stopped = false;
        $this->parent->run();
    }

    public function stop(): void {
        $this->stopped = true;
    }

    public function defer(Closure $callback): void {
        $this->schedule();
        $this->deferred[$this->defHigh++] = $callback;
    }

    public function queueMicrotask(Closure $callback, mixed $argument=null): void {
        $this->schedule();
        $this->microtasks[$this->micHigh] = $callback;
        $this->microtaskHigh[$this->micHigh++] = $argument;
    }

    public function readable($resource, Closure $callback=null): Handler {
        return $this->parent->readable($resource, function($arg) use ($callback) {
            $this->defer(static function() use ($callback, $arg) {
                $callback($arg);
            });
        });
    }

    public function writable($resource, Closure $callback=null): Handler {
        return $this->parent->writable($resource, function($arg) use ($callback) {
            $this->defer(static function() use ($callback, $arg) {
                $callback($arg);
            });
        });
    }

    public function suspend(): Closure {
        if ($this->suspended) {
            throw new \LogicException("The loop is already suspended");
        }
        $done = false;
        $this->suspended = true;

        return function() use (&$done) {
            if (!$done) {
                $done = true;
                $this->suspended = true;
            }
        };
    }

}
