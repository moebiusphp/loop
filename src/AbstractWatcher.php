<?php
namespace Moebius\Loop;

use Closure;
use Moebius\Loop;
use Moebius\Promise;
use Moebius\Deferred;
use Moebius\PromiseInterface;

abstract class AbstractWatcher implements PromiseInterface {

    /**
     * Listeners for this watcher which will get notified when
     * the event is triggered.
     */
    protected array $onFulfilled = [];

    /**
     * Listeners for this watcher which will get notified when
     * the event is cancelled via the AbstractWatcher::reject().
     */
    protected array $onRejected = [];

    protected $value;
    protected ?Closure $startFunction;
    protected ?Closure $stopFunction = null;
    protected ?Closure $timeoutFunction = null;

    protected bool $dead = false;

    public function __construct(Closure $startFunction, $value, float $timeout=null) {
        $this->startFunction = $startFunction;
        $this->value = $value;
        if ($timeout !== null) {
            $this->timeoutFunction = Loop::delay($timeout, function() {
                if ($this->dead) {
                    return;
                };
                if ($this->stopFunction) {
                    $this->watcherStop();
                }
                $this->startFunction = null;
                $this->timeoutFunction = null;
                $this->reject(new TimeoutException());
                $this->dead = true;
            });
        }
    }

    public final function suspend() {
        $this->watcherStop();
    }

    public final function resume() {
        $this->watcherStart();
    }

    public final function cancel() {
        if ($this->dead) {
            throw new \LogicException("Trying to cancel a dead watcher");
        }
        if ($this->stopFunction) {
            $this->watcherStop();
        }
        if ($this->timeoutFunction) {
            ($this->timeoutFunction)();
            $this->timeoutFunction = null;
        }
        $this->value = null;
        $this->startFunction = null;
        $this->reject(new CancelledException());
    }

    public final function __destruct() {
        if (!$this->dead) {
            $this->cancel();
        }
    }

    public function isPending(): bool {
        if ($this->dead) {
            return false;
        }
        return true;
    }

    public function isFulfilled(): bool {
        return false;
    }

    public function isRejected(): bool {
        if ($this->dead) {
            return true;
        }
        return false;
    }

    /**
     * Attach listeners to this event promise.
     */
    public final function then(callable $onFulfill = null, callable $onReject = null, callable $void = null): PromiseInterface {
        if ($this->dead) {
            throw new \LogicException("Trying to watch a dead watcher");
        }
        if ($onFulfill === null && $onReject === null) {
            return $this;
        }
        if (!$this->stopFunction) {
            $this->watcherStart();
        }
        $promise = new Deferred();
        if ($onFulfill !== null) {
            $this->onFulfilled[] = static function(mixed $value) use ($onFulfill, $onReject, $promise) {
                try {
                    $result = $onFulfill($value);
                    Loop::queueMicrotask($promise->fulfill(...), $result);
                } catch (\Throwable $e) {
                    Loop::queueMicrotask($promise->reject(...), $e);
                }
            };
        }
        if ($onReject !== null) {
            $this->onRejected[] = static function(mixed $value) use ($onReject, $promise) {
                try {
                    $result = $onReject($value);
                    Loop::queueMicrotask($promise->fulfill(...), $result);
                } catch (\Throwable $e) {
                    Loop::queueMicrotask($promise->reject(...), $e);
                }
            };
        }

        return $promise;
    }

    protected final function fulfill(): void {
        if ($this->dead) {
            throw new \LogicExceptin("Rejecting a dead watcher");
        }
        foreach ($this->onFulfilled as $callback) {
            Loop::queueMicrotask($callback, $this->value);
        }
        $this->onFulfilled = [];
        $this->onRejected = [];
    }

    protected final function reject(mixed $reason): void {
        if ($this->dead) {
            throw new \LogicExceptin("Rejecting a dead watcher");
        }
        $this->dead = true;
        if ($this->stopFunction) {
            $this->watcherStop();
        }
        if ($this->timeoutFunction) {
            ($this->timeoutFunction)();
            $this->timeoutFunction = null;
        }
        $this->startFunction = null;
        foreach ($this->onRejected as $callback) {
            Loop::queueMicrotask($callback, $reason);
        }
        $this->onFulfilled = [];
        $this->onRejected = [];
    }

    private function watcherStop(): void {
        if (!$this->stopFunction) {
            throw new \LogicException("Watcher not started");
        }
        ($this->stopFunction)();
        $this->stopFunction = null;
    }

    private function watcherStart(): void {
        if (!$this->startFunction) {
            throw new \LogicException("Watcher has no start function");
        }
        $this->stopFunction = ($this->startFunction)($this->value, function() {
            $this->watcherStop();
            $this->fulfill();
        });
    }

}
