<?php
namespace Co\Loop\Drivers;

use Co\{
    Promise,
    PromiseInterface
};
use React\EventLoop\Loop as React;
use Co\Loop\DriverInterface;
use Co\Loop\AbstractDriver;

class ReactDriver extends AbstractDriver {

    protected function schedule(): void {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        React::futureTick($this->tick(...));
    }

    public function onTick(): void {}

    public function signal(int $signalNumber): PromiseInterface {
        $promise = new Promise();
        $eventHandler = function() use ($promise, $signalNumber) {
            $promise->fulfill($signalNumber);
        };
        $resolveHandler = function() use ($signalNumber, $eventHandler) {
            React::removeSignal($signalNumber, $eventHandler);
        };
        $promise->then($resolveHandler, $resolveHandler);
        React::addSignal($signalNumber, function() use ($promise, $signalNumber) {
            $promise->fulfill($signalNumber);
        });
        return $promise;
    }

    public function readable($stream): PromiseInterface {
        $promise = new Promise();
        React::addReadStream($stream, function() use ($promise, $stream) {
            React::removeReadStream($stream);
            $promise->fulfill($stream);
        });
        $promise->then(null, function() use ($stream) {
            React::removeReadStream($stream);
        });
        return $promise;
    }

    public function writable($stream): PromiseInterface {
        $promise = new Promise();
        React::addWriteStream($stream, function() use ($promise, $stream) {
            React::removeWriteStream($stream);
            $promise->fulfill($stream);
        });
        $promise->then(null, function() use ($stream) {
            React::removeWriteStream($stream);
        });
        return $promise;
    }
}
