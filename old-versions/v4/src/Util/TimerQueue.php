<?php
namespace Moebius\Loop\Util;

use SplMinHeap;

class TimerQueue {

    private \SplMinHeap $queue;

    public function __construct() {
        $this->queue = new class extends SplMinHeap {
            public function compare($a, $b) {
                if ($a->time > $b->time) return -1;
                elseif ($a->time < $b->time) return 1;
                return 0;
            }
        };
    }

    public function isEmpty(): bool {
        return !$this->peek();
    }

    public function enqueue(Timer $timer): void {
        $this->queue->insert($timer);
    }

    public function dequeue(): ?Timer {
        if ($this->peek()) {
            return $this->queue->extract();
        }
        return null;
    }

    public function getNextTime(): ?float {
        return $this->peek()?->time;
    }

    private function peek(): ?Timer {
        while (!$this->queue->isEmpty()) {
            $next = $this->queue->top();
            if ($next->callback) {
                return $next;
            }
            $this->queue->extract();
        }
        return null;
    }

}
