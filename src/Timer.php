<?php
namespace Moebius\Loop;

use Moebius\Loop;
use Moebius\Promise\ProtoPromise;

class Timer extends AbstractWatcher {

    public function __construct(float $time) {
        parent::__construct(Loop::delay(...), $time);
    }

}
