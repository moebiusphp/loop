<?php
namespace Moebius\Loop;

use Closure;
use Moebius\Loop;

class Timer extends AbstractWatcher {

    public function __construct(float $time) {
        parent::__construct(Loop::delay($time, function() use ($time) {
            $this->fulfill($time);
        }));
    }

}
