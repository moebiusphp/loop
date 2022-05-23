<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Interval extends AbstractWatcher {

    private float $interval;
    private float $nextTime;

    public function __construct(float $interval) {
        $this->interval = $interval;
        $this->nextTime = Loop::getTime() + $interval;
        parent::__construct(Loop::delay($interval, $this->eventHandler(...)));
    }

    private function eventHandler(): void {
        $this->fulfill($this->nextTime);
        $this->eh->cancel();
        $now = Loop::getTime();
        while ($this->nextTime < $now) {
            $this->nextTime += $this->interval;
        }
        $delay = $this->nextTime - $now;
        $this->eh = Loop::delay($delay, $this->eventHandler(...));
    }

}
