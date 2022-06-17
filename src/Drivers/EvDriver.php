<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\Handler;

class EvDriver extends NativeDriver {

    private int $timerCount = 0;

    public function __construct(Closure $exceptionHandler) {
        parent::__construct($exceptionHandler);
        $this->loop = \EvLoop::defaultLoop();
    }

    public function run(): void {
        $this->stopped = false;

        do {
            if (
                ($this->defLow < $this->defHigh) ||
                ($this->micLow < $this->micHigh)
            ) {
                $this->loop->run(\Ev::RUN_NOWAIT);
            } else {
                $this->loop->run(\Ev::RUN_ONCE);
            }

            try {
                $this->runMicrotasks();
                $this->runDeferred();
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stop();
            }

            if ($this->stopped) {
                break;
            }

        } while (
            ($this->defLow < $this->defHigh) ||
            ($this->micLow < $this->micHigh) ||
            !empty($this->readStreams) ||
            !empty($this->writeStreams) ||
            $this->timerCount > 0
        );
    }

    public function readable($resource, Closure $callback=null): Handler {
        $id = \get_resource_id($resource);
        $event = null;

        [$handler, $fulfill] = Handler::create(static function() use (&$event, $id) {
            unset($this->readStreams[$id]);
            $event->stop();
        });

        $event = $this->loop->io($resource, \Ev::READ, function() use ($resource, $fulfill, &$event, $id) {
            unset($this->readStreams[$id]);
            $event->stop();
            try {
                $this->runMicrotasks();
                $fulfill($resource);
                $this->runMicrotasks();
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stop();
            }
        });

        $this->readStreams[$id] = $resource;

        return $handler;
    }

    public function writable($resource, Closure $callback=null): Handler {
        $id = \get_resource_id($resource);
        $event = null;

        [$handler, $fulfill] = Handler::create(static function() use (&$event, $id) {
            unset($this->writeStreams[$id]);
            $event->stop();
        });
        $event = $this->loop->io($resource, \Ev::WRITE, function() use ($resource, $fulfill, &$event, $id) {
            unset($this->writeStreams[$id]);
            $event->stop();
            try {
                $this->runMicrotasks();
                $fulfill($resource);
                $this->runMicrotasks();
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stop();
            }
        });

        $this->writeStreams[$id] = $resource;

        return $handler;
    }

    public function delay(float $time, Closure $callback=null): Handler {
        ++$this->timerCount;
        $event = null;
        $cancelled = false;

        [$handler, $fulfill] = Handler::create(function() use (&$cancelled, &$event) {
            if (!$cancelled) {
                --$this->timerCount;
            }
            $event->stop();
            $cancelled = true;
        });
        $event = $this->loop->timer($time, 0.0, function() use ($fulfill, &$cancelled, &$event) {
            if ($cancelled) {
                return;
            }
            $cancelled = true;
            --$this->timerCount;
            try {
                $this->runMicrotasks();
                $fulfill($this->getTime());
                $this->runMicrotasks();
            } catch (\Throwable $e) {
                ($this->exceptionHandler)($e);
                $this->stop();
            }
        });

        return $handler;
    }

}
