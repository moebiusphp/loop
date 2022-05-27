<?php
namespace Moebius\Loop\Drivers;

use Closure, EvLoop, Ev, WeakMap;
use Moebius\Loop\Util\ClosureTool;

class EvDriver extends NativeDriver {

    private EvLoop $loop;
    private int $managedEvents = 0;
    private WeakMap $watchers;

    public function __construct() {
        parent::__construct();
        $this->watchers = new WeakMap();
        $this->loop = EvLoop::defaultLoop();
    }

    public function run(): void {
        $this->stopped = false;
        do {
            // echo "deferred=".count($this->deferred)." watchers=".$this->watchers->count()." timers=".json_encode(!$this->timers->isEmpty())."\n";
            if (
                empty($this->deferred) &&
                $this->watchers->count() === 0 &&
                $this->timers->isEmpty()
            ) {
                return;
            }
            // how much time can we spend polling IO streams or waiting for timers?
            if (!empty($this->deferred)) {
                $maxDelay = 0;
            } elseif (!$this->timers->isEmpty()) {
                $nextTick = max($this->timers->getNextTime(), $this->time + 0.25);
                $maxDelay = $nextTick - $this->time;
            } else {
                $maxDelay = 0.1;
            }
            if ($maxDelay > 0) {
                $this->loop->run(Ev::RUN_ONCE);
            } else {
                $this->loop->run(Ev::RUN_NOWAIT);
            }
            $this->time = hrtime(true) / 1_000_000_000;

            $this->enqueueTimers();
            $this->runDeferred();
        } while (!$this->stopped);
    }

    public function readable($resource, Closure $callback): Closure {
        $watcher = $this->loop->io($resource, Ev::READ, $callback);
        $this->watchers[$watcher] = true;
        return static function() use (&$watcher) {
            $watcher->stop();
            $watcher = null;
        };
    }

    public function writable($resource, Closure $callback): Closure {
        $watcher = $this->loop->io($resource, Ev::WRITE, $callback)->stop(...);
        $this->watchers[$watcher] = true;
        return static function() use (&$watcher) {
            $watcher->stop();
            $watcher = null;
        };
    }

    public function signal(int $sigNum, Closure $callback): Closure {
        $watcher = $this->loop->signal($sigNum, $callback)->stop(...);
        $this->watchers[$watcher] = $watcher->stop(...);
        return static function() use (&$watcher) {
            $watcher->stop();
            $watcher = null;
        };
    }

}
