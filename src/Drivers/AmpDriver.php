<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\DriverInterface;
use Moebius\Loop\Handler;
use Amp\Loop\Driver;

class AmpDriver extends ReactDriver {

    protected Driver $loop;

    public function __construct(Closure $exceptionHandler) {
        parent::__construct(function($e) use ($exceptionHandler) {
            $this->stop();
            $this->loop->defer(function() {
                $this->stopped = false;
            });
        });
        $this->loop = \Amp\Loop::get();
        \register_shutdown_function($this->run(...));
    }

    public function getTime(): float {
        return $this->loop->now() / 1000;
    }

    public function run(): void {
        $this->loop->run();
    }

    public function stop(): void {
        $this->stopped = true;
        $this->loop->stop();
    }

    public function defer(Closure $callback, mixed ...$args): void {
        $this->loop->defer($this->wrap($callback, ...$args));
    }

    public function delay(float $time, Closure $callback=null): Handler {
        $timer = null;
        [$handler, $fulfill] = Handler::create(function() use (&$timer) {
            $this->loop->cancel($timer);
        });
        $timer = $this->loop->delay(intval($time * 1000), $this->wrap($fulfill, $this->getTime() + $time));
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }

    public function readable($resource, Closure $callback=null): Handler {
        $id = \get_resource_id($resource);
        if (isset($this->readStreams[$id])) {
            throw new \LogicException("Already subscribed to this resource");
        }
        $eventId = null;
        $cancelFunction = function() use (&$eventId, $id) {
            unset($this->readStreams[$id]);
            $this->loop->cancel($eventId);
        };
        [$handler, $fulfill] = Handler::create($cancelFunction);
        $fulfill = $this->wrap($fulfill, $resource);
        $eventId = $this->loop->onReadable($resource, function() use ($resource, $fulfill, $cancelFunction) {
            $cancelFunction();
            $fulfill();
        });
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }

    public function writable($resource, Closure $callback=null): Handler {
        $id = \get_resource_id($resource);
        if (isset($this->writeStreams[$id])) {
            throw new \LogicException("Already subscribed to this resource");
        }
        $eventId = null;
        $cancelFunction = function() use (&$eventId, $id) {
            unset($this->writeStreams[$id]);
            $this->loop->cancel($eventId);
        };
        [$handler, $fulfill] = Handler::create($cancelFunction);
        $fulfill = $this->wrap($fulfill, $resource);
        $eventId = $this->loop->onWritable($resource, function() use ($resource, $fulfill, $cancelFunction) {
            $cancelFunction();
            $fulfill();
        });
        if ($callback) {
            $handler->then($callback);
        }
        return $handler;
    }
}
