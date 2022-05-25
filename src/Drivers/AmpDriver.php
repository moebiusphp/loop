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
            $exceptionHandler,
            \register_shutdown_function(...)
        );
    }

    public function getTime(): float {
        return $this->loop->now() / 1000;
    }

    public function tick(): void {
        $this->loop->defer($this->loop->stop(...));

        $t = hrtime(true);
        $this->loop->run();
        $this->runDeferred();

        if (!$this->hasImmediateWork()) {
            // AMP has no means to only run one tick without sleeping to OS, so we sleep for it some
            $ns = hrtime(true) - $t;
            $uWait = intval((10_000_000 - $ns) / 1000);
            usleep($uWait);
        }
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
