<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\{
    Event,
    EventHandle,
    DriverInterface
};

class EvDriver extends AbstractDriver {

    private \EvLoop $loop;

    public function __construct(Closure $exceptionHandler) {
        $this->loop = \EvLoop::defaultLoop();

        parent::__construct(
            $exceptionHandler
        );
        \register_shutdown_function($this->run(...));
    }

    public function run(Closure $shouldResumeFunction=null): void {
        $this->stopped = false;
        do {
            $this->loop->run(
                $shouldResumeFunction || $this->hasImmediateWork() ?
                    \Ev::RUN_NOWAIT :
                    \Ev::RUN_ONCE
            );

            $this->runDeferred();

            if ($shouldResumeFunction && !$shouldResumeFunction()) {
                return;
            }
        } while (!$this->stopped && ($this->hasImmediateWork() || $this->hasAsyncWork()));
    }

    public function getTime(): float {
        return $this->loop->now();
    }

    public function stop(): void {
        $this->stopped = true;
    }

    protected function scheduleOn(Event $event): void {
//echo "on ".$event->id." type=".$event->type."\n";
        switch ($event->type) {
            case Event::READABLE:
                if (!$event->data) {
                    $event->data = $this->loop->io($event->value, \Ev::READ, function() use ($event) {
                        $this->defer($event->trigger(...));
                    });
                } else {
                    $event->data->start();
                }
                break;
            case Event::WRITABLE:
                if (!$event->data) {
                    $event->data = $this->loop->io($event->value, \Ev::READ, function() use ($event) {
                        $this->defer($event(...));
                    });
                } else {
                    $event->data->start();
                }
                break;
            case Event::TIMER:
                if (!$event->data) {
                    $event->data = $this->loop->timer($event->value, 0, function() use ($event) {
                        $this->suspend($event->id);
                        $this->defer($event->trigger(...));
                    });
                } else {
                    $event->data->set($event->value, 0);
                    $event->data->again();
                }
                break;
            case Event::INTERVAL:
                if (!$event->data) {
                    $event->data = $this->loop->timer($event->value, $event->value, function() use ($event) {
                        $this->defer($event->trigger(...));
                    });
                } else {
                    $event->data->start();
                }
                break;
            case Event::SIGNAL:
                if (!$event->data) {
                    $event->data = $this->loop->signal($event->value, function() use ($event) {
                        $this->defer($event->trigger(...));
                    });
                } else {
                    $event->data->start();
                }
                break;
        }
    }

    protected function scheduleOff(Event $event): void {
//echo "off ".$event->id." type=".$event->type."\n";
        switch ($event->type) {
            case Event::READABLE:
                $event->data->stop();
                break;
            case Event::WRITABLE:
                $event->data->stop();
                break;
            case Event::TIMER:
                $event->data->stop();
                break;
            case Event::INTERVAL:
                $event->data->stop();
                break;
            case Event::SIGNAL:
                $event->data->stop();
                break;
        }
    }


}
