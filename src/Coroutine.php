<?php
namespace Moebius\Loop;

use Fiber, Closure, Throwable;
use Moebius\{
    Loop,
    Promise
};

class Coroutine implements \Moebius\Promise\PromiseInterface {

    public array $onFulfill = [];
    public array $onReject = [];

    private const PENDING = 0;
    private const FULFILLED = 1;
    private const REJECTED = 2;

    private Fiber $fiber;
    private int $state = 0;
    private mixed $value;

    public function __construct(Closure $callback, mixed ...$args) {
        try {
            $this->fiber = new Fiber($callback);
            $this->handle($this->fiber->start(...$args));
        } catch (Throwable $e) {
            $this->value = $e;
            $this->state = self::REJECTED;
            // No reason to call settle as no listeners can have been added
            // yet
        }
    }

    public function then(callable $onFulfill=null, callable $onReject=null, callable $void=null): Promise {
        if (!$onFulfill && !$onReject) {
            throw new \LogicException("Need at least one on-fulfill or on-reject handler");
        }
        $promise = new Promise();
        if ($onFulfill) {
            $this->onFulfill[] = static function($result) use ($onFulfill, $promise) {
                try {
                    $nextResult = $onFulfill($result);
                    $promise->fulfill($nextResult);
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            };
        }
        if ($onReject) {
            $this->onReject[] = static function($reason) use ($onReject, $promise) {
                try {
                    $nextResult = $onReject($result);
                    $promise->resolve($nextResult);
                } catch (\Throwable $e) {
                    $promise->reject($e);
                }
            };
        }
        if ($this->state !== self::PENDING) {
            $this->settle();
        }
        return $promise;
    }

    private function settle(): void {
        if ($this->state === self::FULFILLED) {
            $receivers = $this->onFulfill;
        } elseif ($this->state === self::REJECTED) {
            $receivers = $this->onReject;
        } else {
            throw new \LogicException("Don't call settle if the coroutine has not completed");
        }
        $this->onFulfill = [];
        $this->onReject = [];
        foreach ($receivers as $receiver) {
            Loop::queueMicrotask($receiver, $this->value);
        }

    }

    private function handle($intermediate): void {
        if ($this->fiber->isTerminated()) {
            $this->value = $this->fiber->getReturn();
            $this->state = self::FULFILLED;
            $this->settle();
        } elseif ($intermediate !== null && is_object($intermediate) && Promise::isPromise($intermediate)) {
            // Resume the fiber when the promise is resolved
            $intermediate->then(
                $this->resume(...),
                $this->throwException(...)
            );
        } else {
            Loop::defer($this->resume(...));
        }
    }

    private function resume($result): void {
        try {
            $this->handle($this->fiber->resume($result));
        } catch (Throwable $e) {
            $this->value = $e;
            $this->state = self::REJECTED;
            $this->settle();
        }
    }

    private function throwException($reason): void {
        if (!($reason instanceof Throwable)) {
            $reason = new RejectedException($reason);
        }
        try {
            $this->handle($this->fiber->throw($reason));
        } catch (Throwable $e) {
            $this->value = $e;
            $this->state = self::REJECTED;
            $this->settle();
        }
    }

    public function isPending(): bool {
        return $this->state === self::PENDING;
    }

    public function isFulfilled(): bool {
        return $this->state === self::FULFILLED;
    }

    public function isRejected(): bool {
        return $this->state === self::REJECTED;
    }

};
