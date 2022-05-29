<?php
namespace Moebius\Loop\Drivers;

use Moebius\Loop;
use Closure, SplMinHeap;
use Moebius\Loop\{
    DriverInterface,
    EventHandle,
    Event
};

class StreamSelectDriver extends AbstractDriver {

    private float $time;
    private Closure $exceptionHandler;
    protected bool $stopped = false;
    private array $readStreams = [];
    private array $readListeners = [];
    private array $writeStreams = [];
    private array $writeListeners = [];
    private array $signals = [];
    private int $timerId = 0;
    private array $timers = [];
    private \SplMinHeap $timerQueue;

    public function __construct(Closure $exceptionHandler) {
        $this->time = hrtime(true) / 1_000_000_000;
        $this->exceptionHandler = $exceptionHandler;
        $this->timerQueue = new class extends \SplMinHeap {
            protected function compare($a, $b): int {
                if ($a->time > $b->time) return -1;
                elseif ($a->time < $b->time) return 1;
                return 0;
            }
        };
    }

    public function getTime(): float {
        return $this->time;
    }

    public function stop(): void {
        $this->stopped = true;
    }

    public function run(Closure $shouldContinueFunc=null): void {
        $this->stopped = false;
    }

    protected function scheduleOn(Event $e): void {
        switch($e->type) {
            case Event::READABLE:
                $rId = \get_resource_id($e->value);
                if (empty($this->readStreams[$rId])) {
                    $this->readStreams[$rId] = $e->value;
                }
                $this->readListeners[$rId][$e->id] = $e->id;
                break;
            case Event::WRITABLE:
                $rId = \get_resource_id($e->value);
                if (empty($this->writeStreams[$rId])) {
                    $this->writeStreams[$rId] = $e->value;
                }
                $this->writeListeners[$rId][$e->id] = $e->id;
                break;
            case Event::TIMER:
                $this->timerQueue->insert($e);
                break;
            case Event::INTERVAL:
                if (isset($this->timers[$e->id]) && $this->timers[$e->id]) {
                    return;
                }
                $e->time = $this->time + $e->value;
                $this->timerQueue->insert($e);
                break;
            case Event::SIGNAL:
                if (empty($this->signals[$e->id])) {
                    \pcntl_signal($e->value, $this->onSignal(...));
                }
                $this->signals[$e->value][$e->id] = $e->id;
                break;
        }
    }

    protected function scheduleOff(Event $e): void {
        switch($e->type) {
            case Event::READABLE:
                $rId = \get_resource_id($e->value);
                unset($this->readStreams[$rId][$e->id], $this->readListeners[$rId][$e->id]);
            case Event::WRITABLE:
            case Evemt::TIMER:
            case Event::INTERVAL:
            case Event::SIGNAL:
        }
    }
}
