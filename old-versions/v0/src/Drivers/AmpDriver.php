<?php
namespace Co\Loop\Drivers;

use Co\{
    Promise,
    PromiseInterface
};
use Amp\Loop as Amp;
use Co\Loop\DriverInterface;
use Co\Loop\AbstractDriver;

class AmpDriver extends AbstractDriver {

    private bool $ampScheduled = false;

    protected function schedule(): void {
        if ($this->scheduled) {
            return;
        }

        if (!$this->ampScheduled) {
            $this->ampScheduled = true;
            \register_shutdown_function(function() {
                Amp::run();
            });
        }

        $this->scheduled = true;
        Amp::defer($this->tick(...));
    }

    public function onTick(): void {
    }

    public function signal(int $signalNumber): PromiseInterface {
        $promise = new Promise();

        $eventHandler = function() use ($promise, $signalNumber) {
            $promise->fulfill($signalNumber);
        };
        $id = Amp::onSignal($signalNumber, $eventHandler);
        $resolveHandler = function() use ($id) {
            Amp::cancel($id);
            --$this->activityLevel;
        };
        $promise->then($resolveHandler, $resolveHandler);
        ++$this->activityLevel;
        $this->schedule();

        return $promise;
    }

    public function readable($stream): PromiseInterface {
        $promise = new Promise();

        $id = Amp::onReadable($stream, function() use ($promise, $stream) {
            $promise->fulfill($stream);
        });
        $resolveHandler = function() use ($id) {
            Amp::cancel($id);
            --$this->activityLevel;
        };
        $promise->then($resolveHandler, $resolveHandler);

        ++$this->activityLevel;
        $this->schedule();

        return $promise;
    }

    public function writable($stream): PromiseInterface {
        $promise = new Promise();

        $id = Amp::onWritable($stream, function() use ($promise, $stream) {
            $promise->fulfill($stream);
        });
        $resolveHandler = function() use ($id) {
            Amp::cancel($id);
            --$this->activityLevel;
        };
        $promise->then($resolveHandler, $resolveHandler);

        ++$this->activityLevel;
        $this->schedule();

        return $promise;
    }
}
