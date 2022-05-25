<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Interval extends AbstractWatcher {

    public function __construct(float $interval) {
        parent::__construct(Loop::interval($interval, function() use ($interval) {
            $this->fulfill($interval);
        }));
    }
}
