<?php
namespace Co\Loop;

use Co\Loop;
use Co\Promise;
use Co\PromiseInterface;

abstract class AbstractWatcher implements PromiseInterface {

    /**
     * Listeners for this watcher which will get notified when
     * the event is triggered.
     */
    private array $onFulfilled = [];

    /**
     * Listeners for this watcher which will get notified when
     * the event is cancelled via the AbstractWatcher::reject().
     */
    private array $onRejected = [];

    private int $watcherId;
    private bool $activated = false;

    public function __construct(int $watcherId) {
        $this->watcherId = $watcherId;
    }

    public final function __destruct() {
        Loop::getDriver()->cancel($this->watcherId);
    }

    public function isPending(): bool {
        return true;
    }

    public function isFulfilled(): bool {
        return false;
    }

    public function isRejected(): bool {
        return false;
    }

    /**
     * Attach listeners to this event promise.
     */
    public final function then(callable $onFulfill = null, callable $onReject = null, callable $void = null): PromiseInterface {
        if ($onFulfill === null && $onReject === null) {
            return $this;
        }
        if (!$this->activated) {
            Loop::getDriver()->activate($this->watcherId);
            $this->activated = true;
        }
        $promise = new Promise();
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

    protected final function fulfill(mixed $value): void {
        $this->activated = false;
        Loop::getDriver()->suspend($this->watcherId);
        foreach ($this->onFulfilled as $callback) {
            Loop::queueMicrotask($callback, $value);
        }
        $this->onFulfilled = [];
        $this->onRejected = [];
    }

    protected final function reject(mixed $reason): void {
        $this->activated = false;
        Loop::getDriver()->suspend($this->watcherId);
        foreach ($this->onRejected as $callback) {
            Loop::queueMicrotask($callback, $reason);
        }
        $this->onFulfilled = [];
        $this->onRejected = [];
    }

}
