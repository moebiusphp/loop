<?php
namespace Moebius\Loop\Util;

use Closure;

final class Timer {

    public float $time;
    public ?Closure $callback;

    public static function create(float $time, Closure $callback) {
        $timer = new self();
        $timer->time = $time;
        $timer->callback = $callback;
        return $timer;
    }

    private function __construct() {}

    public function cancel(): void {
        if (!$this->callback) {
            throw new \LogicException("Timer already cancelled");
        }
        $this->callback = null;
    }
}
