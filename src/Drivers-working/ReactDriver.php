<?php
namespace Moebius\Loop\Drivers;

use Closure;
use Moebius\Loop\{
    Event,
    EventHandle,
    DriverInterface
};
use React\EventLoop\Loop;

class ReactDriver extends AbstractDriver {

    private array $readStreams = [];
    private array $writeStreams = [];

    public function __construct(Closure $exceptionHandler) {
        parent::__construct(
            $exceptionHandler,
            Loop::futureTick(...)
        );
    }

    public function getTime(): float {
        return hrtime(true) / 1_000_000_000;
    }

    public function run(Closure $shouldResumeFunction=null): void {
        $this->stopped = false;
        if ($shouldResumeFunction) {
            Loop::futureTick($func = function() use ($shouldResumeFunction, &$func) {
                $this->runDeferred();
                if (!$shouldResumeFunction()) {
                    return;
                }
                Loop::futureTick($func);
            });
        }
        Loop::run();
    }

    public function stop(): void {
        $this->stopped = true;
        Loop::stop();
    }

    public function defer(Closure $callback): void {
        Loop::futureTick($this->wrap($callback));
    }

    public function scheduleOn(Event $event): void {
//echo "on with ".$event->id." type=".$event->type."\n";
        switch ($event->type) {
            case Event::READABLE:
                $id = \get_resource_id($event->value);
                $fp = $event->value;
                if (!isset($this->readStreams[$id])) {
                    Loop::addReadStream($event->value, function() use ($id, $fp) {
                        if (!empty($this->readStreams[$id])) {
                            Loop::removeReadStream($fp);
                        }
                        foreach ($this->readStreams[$id] as $event) {
                            $this->defer($event->trigger(...));
                        }
                    });
                }
                $this->readStreams[$id][$event->id] = $event;
                break;
            case Event::WRITABLE:
                $id = \get_resource_id($event->value);
                $fp = $event->value;
                if (!isset($this->writeStreams[$id])) {
                    Loop::addWriteStream($event->value, function() use ($id, $fp) {
                        if (!empty($this->writeStreams[$id])) {
                            Loop::removeWriteStream($fp);
                        }
                        foreach ($this->writeStreams[$id] as $event) {
                            $this->defer($event->trigger(...));
                        }
                    });
                }
                $this->writeStreams[$id][$event->id] = $event;
                break;
            case Event::TIMER:
                $event->data = Loop::addTimer($event->value, function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
            case Event::INTERVAL:
                $event->data = Loop::addPeriodicTimer($event->value, function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
            case Event::SIGNAL:
                Loop::addSignal($event->value, $event->data = function() use ($event) {
                    $this->defer($event->trigger(...));
                });
                break;
        }
    }

    public function scheduleOff(Event $event): void {
//echo "off with ".$event->id." type=".$event->type."\n";
        switch ($event->type) {
            case Event::READABLE:
                $fp = $event->value;
                $id = \get_resource_id($fp);
                unset($this->readStreams[$id][$event->id]);
                if (empty($this->readStreams[$id])) {
                    unset($this->readStreams[$id]);
                    Loop::removeReadStream($event->value);
                }
                break;
            case Event::WRITABLE:
                $fp = $event->value;
                $id = \get_resource_id($fp);
                unset($this->writeStreams[$id][$event->id]);
                if (empty($this->writeStreams[$id])) {
                    unset($this->writeStreams[$id]);
                    Loop::removeWriteStream($event->value);
                }
                break;
            case Event::TIMER:
                Loop::cancelTimer($event->data);
                $event->data = null;
                break;
            case Event::INTERVAL:
                Loop::cancelTimer($event->data);
                $event->data = null;
                break;
            case Event::SIGNAL:
                Loop::removeSignal($event->value, $event->data);
                $event->data = null;
                break;
        }
    }

}
