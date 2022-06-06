<?php
namespace Moebius\Loop;

use Closure;
use Moebius\Loop;
use Moebius\Promise\ProtoPromise;
use Moebius\Deferred;
use Moebius\PromiseInterface;

abstract class AbstractWatcher extends ProtoPromise {

    private ?Closure $cancelFunction;

    public function __construct(Closure $cancelFunction) {
        $this->cancelFunction = $cancelFunction;
    }

    public function cancel(\Throwable $reason) {
        if (!$this->cancelFunction) {
            throw new \LogicException("Promise has already been resolved");
        }
        ($this->cancelFunction)();
        $this->cancelFunction = null;
        $this->reject($reason);
    }

    protected function fulfill($value): void {
        if ($this->cancelFunction) {
            ($this->cancelFunction)();
            $this->cancelFunction = null;
        }
        parent::fulfill($value);
    }

    protected function reject($reason): void {
        if ($this->cancelFunction) {
            ($this->cancelFunction)();
            $this->cancelFunction = null;
        }
        parent::reject($reason);
    }

}
