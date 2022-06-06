<?php
namespace Moebius\Loop\Util;

use Closure;

final class Timer {

    public float $time;
    public ?Closure $callback;
    private $cancelledCounter;

    public function __construct(float $time, Closure $callback) {
        $this->time = $time;
        $this->callback = $callback;
    }

    public function setCounter(int &$cancelledCounter): void {
        $this->cancelledCounter = &$cancelledCounter;
    }

    public function cancel() {
        if ($this->callback === null) {
            throw new \LogicException("Timer was already cancelled");
        }
        $this->callback = null;
    }
}
