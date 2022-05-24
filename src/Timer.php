<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Timer extends AbstractWatcher {

    private float $timeout;

    public function __construct(float $time) {
        $this->timeout = Loop::getTime() + $time;
        $this->eh = Loop::delay($time, $this->eventHandler(...));
    }

    private function eventHandler(): void {
        $this->fulfill($this->timeout);
    }

}
