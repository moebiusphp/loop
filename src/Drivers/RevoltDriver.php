<?php
namespace Co\Loop\Drivers;

use Co\{
    Promise,
    PromiseInterface
};
use Revolt\EventLoop as Revolt;
use Co\Loop\DriverInterface;
use Co\Loop\AbstractDriver;

class RevoltDriver extends AbstractDriver {

    private bool $registered = false;

    protected function schedule(): void {
        if ($this->scheduled) {
            return;
        }
        if (!$this->registered) {
            \register_shutdown_function(Revolt::run(...));
        }
        $this->scheduled = true;
        Revolt::defer($this->tick(...));
    }

    public function signal(int $signalNumber): PromiseInterface {
        $promise = new Promise();

        $eventHandler = static function() use ($promise, $signalNumber) {
            $promise->fulfill($signalNumber);
        };

        $id = Revolt::onSignal($signalNumber, $eventHandler);

        $resolveHandler = function() use ($id) {
            Revolt::cancel($id);
        };

        $promise->then($resolveHandler, $resolveHandler);

        return $promise;
    }

    public function readable($stream): PromiseInterface {
        $promise = new Promise();

        $eventHandler = static function() use ($promise, $stream) {
            $promise->fulfill($stream);
        };

        $id = Revolt::onReadable($stream, $eventHandler);

        $resolveHandler = static function() use ($id) {
            Revolt::cancel($id);
        };

        $promise->then($resolveHandler, $resolveHandler);

        return $promise;
    }

    public function writable($stream): PromiseInterface {
        $promise = new Promise();

        $eventHandler = static function() use ($promise, $stream) {
            $promise->fulfill($stream);
        };

        $id = Revolt::onWritable($stream, $eventHandler);

        $resolveHandler = static function() use ($id) {
            Revolt::cancel($id);
        };

        $promise->then($resolveHandler, $resolveHandler);

        return $promise;
    }
}
