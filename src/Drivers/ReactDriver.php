<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\{
    DriverInterface
};
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;


class ReactDriver implements DriverInterface {

    private LoopInterface $loop;
    private Closure $exceptionHandler;

    /**
     * If this is true when the react event loop is being activated
     * it means that React was autotriggered by itself.
     */
    private bool $enableAutorunDetector = true;
    private bool $reactWasAutorun = false;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->loop = \React\EventLoop\Factory::create();

        /**
         * React will in general schedule itself to run on shutdown,
         * but this does not happen if the loop was manually run.
         * We'll schedule a task with react to detect if it was manually
         * run so that we can run it on shutdown regardless. The
         * onShutdown() function will check if react was autorun and if
         * not, start the event loop for a final run.
         */
        $this->loop->futureTick($this->reactAutorunDetector(...));
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
        $this->enableAutorunDetector = false;
        $this->loop->run();
        $this->enableAutorunDetector = true;
    }

    public function stop(): void {
        $this->enableAutorunDetector = true;
        Loop::stop();
    }

    /**
     * This function is repeatedly scheduled with the React event loop to
     * detect if React self-started on shutdown.
     */
    private function reactAutorunDetector(): void {
        if ($this->enableAutorunDetector) {
            // The react event loop must have be initiated elsewhere
            $this->reactWasAutorun = true;
        } elseif (!$this->reactWasAutorun) {
            // Continue to wait for react to autorun
            $this->loop->futureTick($this->reactAutorunDetector(...));
        }
    }

    private function onShutdown(): void {
        if ($this->reactWasAutorun) {
            echo "shutdown detected and react has autorun\n";
        } else {
            echo "-- react did not run, so we must run it anyway\n";
            $this->enableAutorunDetector = false;
            $this->reactWasAutorun = true;
            \register_shutdown_function($this->loop->run(...));
        }
    }

}
