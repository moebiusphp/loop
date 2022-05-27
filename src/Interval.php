<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Interval extends AbstractWatcher {

    public function __construct(float $interval, float $timeout=null) {
        $nextTime = Loop::getTime() + $interval;
        parent::__construct(static function($void, $callback) use (&$nextTime, $interval) {
            $now = Loop::getTime();
            while ($nextTime < $now) {
                $nextTime += $interval;
            }
            $delay = $nextTime - $now;
            return Loop::delay($delay, $callback);
        }, $interval, $timeout);
    }
}
