<?php
namespace Co\Loop\Drivers;

use Co\{
    Loop,
    Promise,
    PromiseInterface
};
use Co\Loop\DriverInterface;
use Co\Loop\AbstractDriver;
use Ev;
use EvIo;

class EvDriver extends AbstractDriver {

    public function __construct(\Closure $exceptionHandler) {
        if (!class_exists(Ev::class, false)) {
            throw new \Exception("This driver requires the Ev extension. Install it with `pecl install ev`.");
        }
        parent::__construct($exceptionHandler);
    }

    protected function schedule(): void {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        \register_shutdown_function($this->tick(...));
    }

    public function onTick(): void {
        Ev::run(Ev::RUN_NOWAIT);
    }

    public function readable($resource): PromiseInterface {
        $promise = new Promise();
        $event = new EvIo($resource, Ev::READ, function() use ($resource, $promise) {
            $promise->fulfill($resource);
        });
        $cancelHandler = function() use ($event) {
            --$this->activityLevel;
            $event->stop();
        };
        $promise->then($cancelHandler, $cancelHandler);
        $this->schedule();
        ++$this->activityLevel;
        return $promise;
    }

    public function writable($resource): PromiseInterface {
        $promise = new Promise();
        $event = new EvIo($resource, Ev::WRITE, function() use ($resource, $promise) {
            $promise->fulfill($resource);
        });
        $cancelHandler = function() use ($event) {
            --$this->activityLevel;
            $event->stop();
        };
        $promise->then($cancelHandler, $cancelHandler);
        $this->schedule();
        ++$this->activityLevel;
        return $promise;
    }

    public function signal(int $signalNumber): PromiseInterface {
        $promise = new Promise();
        $event = new EvSignal($signalNumber, function() use ($signalNumber, $promise) {
            $promise->fulfill($signalNumber);
        });
        $cancelHandler = function() use ($event) {
            --$this->activityLevel;
            $event->stop();
        };
        $promise->then($cancelHandler, $cancelHandler);
        $this->schedule();
        ++$this->activityLevel;
        return $promise;
    }
}
