<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\{
    Event,
    EventHandle,
    DriverInterface
};

class AmpDriver extends AbstractDriver {

    private \Amp\Loop\Driver $loop;

    public function __construct(Closure $exceptionHandler) {
        $this->loop = \Amp\Loop::get();
        parent::__construct(
            $exceptionHandler
        );
        \register_shutdown_function($this->loop->run(...));
    }

    public function getTime(): float {
        return $this->loop->now() / 1000;
    }

    public function run(Closure $shouldResumeFunction=null): void {
        $this->stopped = false;
        if ($shouldResumeFunction) {
            $this->loop->defer($func = function() use ($shouldResumeFunction, &$func) {
                $this->runDeferred();
                if (!$shouldResumeFunction()) {
                    return;
                }
                $this->loop->defer($func);
            });
        }
        $this->loop->run();
    }

    public function stop(): void {
        $this->stopped = true;
        $this->loop->stop();
    }

    public function defer(Closure $callback): void {
        $this->loop->defer($this->wrap($callback));
    }

    public function scheduleOn(Event $event): void {
        switch ($event->type) {
            case Event::READABLE:
                $event->data = $this->loop->onReadable($event->value, function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
            case Event::WRITABLE:
                $event->data = $this->loop->onWritable($event->value, function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
            case Event::TIMER:
                $event->data = $this->loop->delay(intval($event->value * 1000), function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
            case Event::INTERVAL:
                $event->data = $this->loop->repeat(intval($event->value * 1000), function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
            case Event::SIGNAL:
                $event->data = $this->loop->onSignal($event->value, function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
        }
    }

    public function scheduleOff(Event $event): void {
        if ($event->data) {
            $this->loop->cancel($event->data);
            $event->data = null;
        }
    }

}
