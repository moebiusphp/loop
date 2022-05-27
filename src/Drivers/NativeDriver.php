<?php
namespace Moebius\Loop\Drivers;

use Closure;
use SplMinHeap;
use LogicException;
use Moebius\Loop\Util\{
    TimerQueue,
    Timer
};
use Moebius\Loop\DriverInterface;

class NativeDriver implements DriverInterface {

    private bool $scheduled = false;
    protected array $deferred = [];
    protected float $time;
    protected bool $stopped = true;
    private array $readStreams = [];
    private array $readListeners = [];
    private array $writeStreams = [];
    private array $writeListeners = [];
    protected TimerQueue $timers;

    public function __construct() {
        $this->timers = new TimerQueue();
        $this->time = hrtime(true) / 1_000_000_000;
        \register_shutdown_function($this->run(...));
    }

    public function getTime(): float {
        return $this->time;
    }

    public function run(): void {
        $this->stopped = false;
        do {
//var_dump(empty($this->deferred), empty($this->readStreams), empty($this->writeStreams), "----");
            if (
                empty($this->deferred) &&
                empty($this->readStreams) &&
                empty($this->writeStreams) &&
                $this->timers->isEmpty()
            ) {
                // if the event loop has nothing to do, break out
                return;
            }

            // how much time can we spend polling IO streams or waiting for timers?
            if (!empty($this->deferred)) {
                $maxDelay = 0;
            } elseif (!$this->timers->isEmpty()) {
                $nextTick = max($this->timers->getNextTime(), $this->time + 0.25);
                $maxDelay = $nextTick - $this->time;
            } else {
                $maxDelay = 0.1;
            }

            if (empty($this->readStreams) && empty($this->writeStreams)) {
                if ($maxDelay > 0) {
                    usleep(intval($maxDelay * 1000000));
                }
            } else {
                $reads = [];
                $writes = [];
                $void = [];
                foreach ($this->readStreams as $id => $resource) {
                    if (!\is_resource($resource)) {
                        $this->deferred[] = $this->readListeners[$id];
                        unset($this->readStreams[$id], $this->readListeners[$id]);
                    } else {
                        $reads[] = $resource;
                    }
                }
                foreach ($this->writeStreams as $id => $resource) {
                    if (!\is_resource($resource)) {
                        $this->deferred[] = $this->writeListeners[$id];
                        unset($this->writeStreams[$id], $this->writeListeners[$id]);
                    } else {
                        $writes[] = $resource;
                    }
                }
                if (!empty($reads) || !empty($writes)) {
                    $count = \stream_select($reads, $writes, $void, 0, intval($maxDelay * 1000000));
                    foreach ($reads as $resource) {
                        $this->deferred[] = $this->readListeners[\get_resource_id($resource)];
                    }
                    foreach ($writes as $resource) {
                        $this->deferred[] = $this->writeListeners[\get_resource_id($resource)];
                    }
                }
            }

            $this->time = hrtime(true) / 1_000_000_000;

            $this->enqueueTimers();
            $this->runDeferred();

        } while (!$this->stopped);
    }

    public function stop(): void {
        $this->stopped = true;
    }

    public function defer(Closure $callback): void {
        $this->deferred[] = $callback;
        if (!$this->scheduled) {
            $this->schedule();
        }
    }

    public function readable($resource, Closure $callback): Closure {
        $this->schedule();
        $cancelled = false;
        $resourceId = \get_resource_id($resource);
        if (isset($this->readStreams[$resourceId])) {
            throw new LogicException("Can't create multiple read listeners for the same resource");
        }
        $this->readStreams[$resourceId] = $resource;
        $this->readListeners[$resourceId] = $callback;
        return function() use ($resourceId, &$cancelled) {
            if ($cancelled) {
                throw new LogicException("Already cancelled");
            }
            $cancelled = true;
            unset($this->readStreams[$resourceId], $this->readListeners[$resourceId]);
        };
    }

    public function writable($resource, Closure $callback): Closure {
        $this->schedule();
        $cancelled = false;
        $resourceId = \get_resource_id($resource);
        if (isset($this->writeStreams[$resourceId])) {
            throw new LogicException("Can't create multiple write listeners for the same resource");
        }
        $this->writeStreams[$resourceId] = $resource;
        $this->writeListeners[$resourceId] = $callback;
        return function() use ($resourceId, &$cancelled) {
            if ($cancelled) {
                throw new LogicException("Already cancelled");
            }
            $cancelled = true;
            unset($this->writeStreams[$resourceId], $this->writeListeners[$resourceId]);
        };
    }

    public function signal(int $signalNumber, Closure $callback): Closure {
        \pcntl_signal($signalNumber, $callback);
        return function() use ($signalNumber) {
            \pcntl_signal($signalNumber, \SIG_DFL);
        };
    }

    public function delay(float $delay, Closure $callback): Closure {
        $this->schedule();
        $timer = new Timer($delay, $callback);
        $this->timers->enqueue($timer);
        return $timer->cancel(...);
    }

    protected function enqueueTimers(): void {
        // enqueue any timer callbacks
        while (null !== ($time = $this->timers->getNextTime()) && $time < $this->time) {
            $timer = $this->timers->dequeue();
            $this->deferred[] = $timer->callback;
        }
    }

    protected function runDeferred(): void {
        $deferred = $this->deferred;
        $this->deferred = [];
        foreach ($deferred as $callback) {
            if ($this->stopped) {
                $this->deferred[] = $callback;
                continue;
            }
            try {
                $callback();
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stopped = true;
            }
        }
    }

    private function schedule(): void {
        if (!$this->scheduled) {
            $this->scheduled = true;
            \register_shutdown_function(function() {
                $this->scheduled = false;
                $this->run();
            });
        }
    }
}
