<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\{
    DriverInterface
};
use React\EventLoop\Loop;

class ReactDriver implements DriverInterface {

    private Closure $exceptionHandler;
    private bool $wasStopped = false;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        \register_shutdown_function($this->onShutdown(...));
    }

    public function defer(Closure $callable): void {
        Loop::futureTick($callable);
    }

    public function readable($resource, Closure $callback): Closure {
        Loop::addReadStream($resource, $callback);
        return static function() use ($resource) {
            Loop::removeReadStream($resource);
        };
    }

    public function writable($resource, Closure $callback): Closure {
        Loop::addWriteStream($resource, $callback);
        return static function() use ($resource) {
            Loop::removeWriteStream($resource);
        };
    }

    public function delay(float $time, Closure $callback): Closure {
        $timer = Loop::addTimer($time, $callback);
        return static function() use ($timer) {
            Loop::cancelTimer($timer);
        };
    }

    public function signal(int $signalNumber, Closure $callback): Closure {
        Loop::addSignal($signalNumber, $callback);
        return static function() use ($signalNumber, $callback) {
            Loop::removeSignal($signalNumber, $callback);
        };
    }

    public function getTime(): float {
        return hrtime(true) / 1_000_000_000;
    }

    public function run(): void {
        Loop::run();
        // when running the event loop, React will set its internal running state to true
        $this->wasStopped = true;
    }

    public function stop(): void {
        Loop::stop();
        // if we are forced to stop the event loop, React will set its internal running state to false
        $this->wasStopped = true;
    }

    private function onShutdown(): void {
        if ($this->wasStopped) {
            \register_shutdown_function(\React\EventLoop\Loop::run(...));
        }
    }

}
