<?php
namespace Co\Loop\Drivers;

use Co\{
    Loop,
    Promise,
    PromiseInterface
};
use Co\Loop\DriverInterface;
use Co\Loop\AbstractDriver;

class StreamSelectDriver extends AbstractDriver {

    private array $readableStreams = [];
    private array $readablePromises = [];
    private array $writableStreams = [];
    private array $writablePromises = [];

    protected function schedule(): void {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        \register_shutdown_function($this->tick(...));
    }

    protected function tick(): void {
        parent::tick();

        if (count($this->readableStreams) > 0 || count($this->writableStreams) > 0) {
            $readableStreams = $this->readableStreams;
            $writableStreams = $this->writableStreams;
            $exceptionStreams = [];

            $count = \stream_select($readableStreams, $writableStreams, $exceptionStreams, 0, 0);
            if ($count > 0) {
                foreach ($readableStreams as $stream) {
                    $id = \get_resource_id($stream);
                    $promise = $this->readablePromises[$id];
                    unset($this->readableStreams[$id]);
                    unset($this->readablePromises[$id]);
                    $promise->fulfill($stream);
                }
                foreach ($writableStreams as $stream) {
                    $id = \get_resource_id($stream);
                    $promise = $this->writablePromises[$id];
                    unset($this->writableStreams[$id]);
                    unset($this->writablePromises[$id]);
                    $promise->fulfill($stream);
                }
            }
        }

        if (
            count($this->readableStreams) > 0 ||
            count($this->writableStreams) > 0
        ) {
            $this->schedule();
        }
    }

    public function readable($resource): PromiseInterface {
        $id = \get_resource_id($resource);
        if (isset($this->readableStreams[$id])) {
            return $this->readableStreams[$id];
        }
        $promise = new Promise();
        $onResolved = function() use ($id) {
            unset($this->readableStreams[$id], $this->readablePromises[$id]);
        };
        $promise->then($onResolved, $onResolved);
        $this->readableStreams[$id] = $resource;
        $this->readablePromises[$id] = $promise;
        $this->schedule();
        return $promise;
    }

    public function writable($resource): PromiseInterface {
        $id = \get_resource_id($resource);
        if (isset($this->writableStreams[$id])) {
            return $this->writablePromises[$id];
        }
        $promise = new Promise();
        $onResolved = function() use ($id) {
            unset($this->writableStreams[$id], $this->writablePromises[$id]);
        };
        $promise->then($onResolved, $onResolved);
        $this->writableStreams[$id] = $resource;
        $this->writablePromises[$id] = $promise;
        $this->schedule();
        return $promise;
    }

    public function signal(int $signalNumber): PromiseInterface {
        throw new \Exception("Not implemented");
    }
}
