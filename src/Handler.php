<?php
namespace Moebius\Loop;

use Closure;
use Moebius\Promise\ProtoPromise;

final class Handler extends ProtoPromise {

    private Closure $cancelFunction;

    public static function create(Closure $cancelFunction): array {
        $handler = new static();
        $handler->cancelFunction = $cancelFunction;
        return [$handler, $handler->fulfill(...)];
    }

    private function __construct() {}

    public function cancel(): void {
        if (!$this->isPending()) {
            throw new \LogicException("Can't cancel a resolved handler");
        }
        ($this->cancelFunction)();
        $this->reject(new CancelledException());
    }

}
