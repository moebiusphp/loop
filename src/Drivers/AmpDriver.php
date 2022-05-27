<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\{
    DriverInterface
};
use Amp\Loop;

class AmpDriver implements DriverInterface {

    private bool $scheduled = false;

    public function __construct() {
    }

    private function scheduledRun() {
        $this->scheduled = false;
        Loop::run();
    }

    public function defer(Closure $callable): void {
        $this->schedule();
        Loop::defer($callable);
    }

    public function readable($resource, Closure $callback): Closure {
        $this->schedule();
        $id = Loop::onReadable($resource, $callback);
        return static function() use ($id) {
            Loop::cancel($id);
        };
    }

    public function writable($resource, Closure $callback): Closure {
        $this->schedule();
        $id = Loop::onWritable($resource, $callback);
        return static function() use ($id) {
            Loop::cancel($id);
        };
    }

    public function delay(float $time, Closure $callback): Closure {
        $this->schedule();
        $id = Loop::delay(max(0, intval($time * 1000)), $callback);
        return static function() use ($id) {
            Loop::cancel($id);
        };
    }

    public function signal(int $signalNumber, Closure $callback): Closure {
        $id = Loop::onSignal($signalNumber, $callback);
        return static function() use ($id) {
            Loop::cancel($id);
        };
    }

    public function getTime(): float {
        return Loop::now() / 1000;
    }

    public function run(): void {
        Loop::run();
    }

    public function stop(): void {
        Loop::stop();
    }

    private function schedule(): void {
        if (!$this->scheduled) {
            \register_shutdown_function($this->scheduledRun(...));
            $this->scheduled = true;
        }
    }

}
