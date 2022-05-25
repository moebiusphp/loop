<?php
namespace Moebius\Loop;

use Moebius\Loop;
use Moebius\Promise\ProtoPromise;

class Timer extends ProtoPromise {

    public function __construct(float $time) {
        Loop::delay($time, function() use ($time) {
            $this->fulfill($time);
        });
    }

}
