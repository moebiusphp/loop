<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Interval extends AbstractWatcher {

    private float $interval;
    private float $nextTime;

    public function __construct(float $interval) {
        $this->interval = $interval;
        $this->nextTime = Loop::getTime() + $interval;
        $eh = Loop::interval($interval, function() {
            $this->fulfill($this->interval);
        });
        parent::__construct($eh);
    }
}
