<?php
namespace Co\Loop\Drivers;

use Co\Loop;
use Co\Loop\DriverInterface;
use Co\Loop\WatcherInterface;

class EvDriver implements DriverInterface {

    /**
     * Are we scheduled to run another tick
     */
    private bool $scheduled = false;

    /**
     * The exception handler from the Loop
     */
    private \Closure $exceptionHandler;

    /**
     * Active event listeners
     */
    private array $watchers = [];

    /**
     * Next available watcher id
     */
    private int $watcherId = 0;

    private int $activeWatchers = 0;

    public function __construct(\Closure $exceptionHandler) {
        $this->exceptionHandler = $exceptionHandler;
    }

    /**
     * Make sure that a tick from the event loop
     * will be run.
     */
    public function schedule(): void {
        if (!$this->scheduled) {
            $this->scheduled = true;
            \register_shutdown_function(function() {
                $this->scheduled = false;
                Loop::tick();
            });
        }
    }

    /**
     * Run events
     */
    public function tick(?float $maxDelay): void {
        static $timer = null;
        try {
            if ($maxDelay === null) {
                // The loop does not care
                if ($this->activeWatchers > 0) {
                    \Ev::run(\Ev::RUN_ONCE);
                }
            } elseif ($maxDelay == 0) {
                // The loop has work to do now
                \Ev::run(\Ev::RUN_NOWAIT);
            } else {
                // The loop has work in $maxDelay seconds
                $maxDelay = max(0, $maxDelay);
                if ($timer === null) {
                    $timer = new \EvTimer($maxDelay, 0.0, static function() {});
                } else {
                    $timer->set($maxDelay, 0.0);
                }
                \Ev::run(\Ev::RUN_ONCE);
            }
        } catch (\Throwable $e) {
            ($this->exceptionHandler)($e);
            exit(1);
        }
        if (!$this->scheduled && $this->activeWatchers > 0) {
            $this->schedule();
        }
    }

    /**
     * Create a watcher for stream readable
     */
    public function readable(mixed $fd, \Closure $activator): int {
        $ev = \EvIo::createStopped($fd, \Ev::READ, function() use ($fd, $activator) {
            Loop::queueMicrotask($activator, $fd);
        });
        $this->watchers[$this->watcherId] = $ev;
        return $this->watcherId++;
    }

    /**
     * Create a watcher for stream readable
     */
    public function writable(mixed $fd, \Closure $activator): int {
        $ev = \EvIo::createStopped($fd, \Ev::WRITE, function() use ($fd, $activator) {
            Loop::queueMicrotask($activator, $fd);
        });
        $this->watchers[$this->watcherId] = $ev;
        return $this->watcherId++;
    }

    /**
     * Create a watcher for signals
     */
    public function signal(int $signalNumber, \Closure $activator): int {
        $ev = \EvSignal::createStopped($signalNumber, function($ev) use ($signalNumber, $activator) {
            Loop::queueMicrotask($activator, $signalNumber);
        });
        $this->watchers[$this->watcherId] = $ev;
        return $this->watcherId++;
    }

    /**
     * Activate a watcher
     */
    public function activate(int $watcherId): void {
        $watcher = $this->watchers[$watcherId] ?? null;
        if ($watcher && !$watcher->is_active) {
            $watcher->start();
            ++$this->activeWatchers;
        } else {
            throw new \LogicException("Watcher does not exist or is already active");
        }
    }

    /**
     * Suspend a watcher
     */
    public function suspend(int $watcherId): void {
        $watcher = $this->watchers[$watcherId] ?? null;
        if ($watcher && $watcher->is_active) {
            $watcher->stop();
            --$this->activeWatchers;
        } else {
            throw new \LogicException("Watcher does not exist or is already suspended");
        }
    }

    /**
     * Destroy a watcher
     */
    public function cancel(int $watcherId): void {
        $watcher = $this->watchers[$watcherId] ?? null;
        if ($watcher) {
            if ($watcher->is_active) {
                $watcher->stop();
                --$this->activeWatchers;
            }
            unset($this->watchers[$watcherId]);
        } else {
            throw new \LogicException("Watcher does not exist");
        }
    }
}
