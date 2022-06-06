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
     * This is TRUE if we have started react and must ensure that it will
     * autorun on shutdown.
     */
    private bool $mustStartReact = false;

    /**
     * This is true if something external triggered react to run
     */
    private bool $reactExternallyRun = false;

    private bool $reactIgnore = false;

    public function __construct(Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
        $this->loop = \React\EventLoop\Loop::get();
        // Ensure that react won't run itself
        $this->loop->run();

        /**
         * React will in general schedule itself to run on shutdown,
         * but this does not happen if the loop was manually run.
         * We'll schedule a task with react to detect if it was manually
         * run so that we can run it on shutdown regardless. The
         * onShutdown() function will check if react was autorun and if
         * not, start the event loop for a final run.
         */
        $this->loop->futureTick($this->reactDetector(...));
        \register_shutdown_function($this->loop->run(...));
    }

    private function reactDetector() {
        if ($this->reactIgnore) {
            $this->loop->futureTick($this->reactDetector(...));
        } else {
            $this->reactExternallyRun = true;
        }
    }

    public function defer(Closure $callable): void {
        $this->reactUsed = true;
        $this->loop->futureTick($callable);
    }

    public function readable($resource, Closure $callback): Closure {
        $this->reactUsed = true;
        $this->loop->addReadStream($resource, $callback);
        return function() use ($resource) {
            $this->loop->removeReadStream($resource);
        };
    }

    public function writable($resource, Closure $callback): Closure {
        $this->reactUsed = true;
        $this->loop->addWriteStream($resource, $callback);
        return function() use ($resource) {
            $this->loop->removeWriteStream($resource);
        };
    }

    public function delay(float $time, Closure $callback): Closure {
        $this->reactUsed = true;
        $timer = $this->loop->addTimer($time, $callback);
        return function() use ($timer) {
            $this->loop->cancelTimer($timer);
        };
    }

    public function signal(int $signalNumber, Closure $callback): Closure {
        $this->reactUsed = true;
        $this->loop->addSignal($signalNumber, $callback);
        return function() use ($signalNumber, $callback) {
            $this->loop->removeSignal($signalNumber, $callback);
        };
    }

    public function getTime(): float {
        return hrtime(true) / 1_000_000_000;
    }

    public function run(): void {
        $this->mustStartReact = true;
        $this->reactIgnore = true;
        $this->loop->run();
    }

    public function stop(): void {
        $this->mustStartReact = true;
        $this->loop->stop();
    }

    private function onShutdown(): void {
        if ($this->mustStartReact && !$this->reactExternallyRun) {
            \register_shutdown_function($this->loop->run(...));
        }
    }

}
