<?php
namespace Co\Loop\Drivers;

use Co\Loop;
use Co\Loop\DriverInterface;
use Co\Loop\WatcherInterface;

class StreamSelectDriver implements DriverInterface {

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
    private array $readFds = [];
    private array $readWatchers = [];
    private array $writeFds = [];
    private array $writeWatchers = [];
    private array $signals = [];
    private int $watcherId = 0;

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
            if (!empty($this->readFds) || !empty($this->writeFds)) {
                $readStreams = array_values($this->readFds);
                $writeStreams = array_values($this->writeFds);
                $void = [];
                if ($maxDelay === null) {
                    $seconds = 0;
                    $uSeconds = 250000;
                } else {
                    $maxDelay = max(0, $maxDelay);
                    $seconds = intval($maxDelay);
                    $uSeconds = intval(($maxDelay - $seconds) * 1000000);
                }
                $count = \stream_select($readStreams, $writeStreams, $void, $seconds, $uSeconds);
                if ($count !== false && $count > 0) {
                    foreach ($readStreams as $fd) {
                        $fdId = \get_resource_id($fd);
                        foreach ($this->readWatchers[$fdId] as $watcher) {
                            Loop::queueMicrotask($watcher->activator, $fd);
                        }
                    }
                    foreach ($writeStreams as $fd) {
                        $fdId = \get_resource_id($fd);
                        foreach ($this->writeWatchers[$fdId] as $watcher) {
                            Loop::queueMicrotask($watcher->activator, $fd);
                        }
                    }
                }
            } elseif ($maxDelay !== null) {
                $maxDelay = max(0, $maxDelay);
                $uSeconds = intval($maxDelay * 1000000);
                usleep($uSeconds);
            } else {
                if (empty($this->signals)) {
                    usleep(0);
                } else {
                    usleep(250000);
                }
            }
        } catch (\Throwable $e) {
            ($this->exceptionHandler)($e);
            exit(1);
        }
        if (!$this->scheduled && (
            !empty($this->signals) ||
            !empty($this->readFds) ||
            !empty($this->writeFds)
        )) {
            $this->schedule();
        }
    }

    /**
     * Create a watcher for stream readable
     */
    public function readable(mixed $fd, \Closure $activator): int {
        $watcherId = $this->watcherId++;
        $this->watchers[$watcherId] = self::createWatcher(0, $fd, $activator, false);
        return $watcherId;
    }

    /**
     * Create a watcher for stream readable
     */
    public function writable(mixed $fd, \Closure $activator): int {
        $watcherId = $this->watcherId++;
        $this->watchers[$watcherId] = self::createWatcher(0, $fd, $activator, false);
        return $watcherId;
    }

    /**
     * Create a watcher for signals
     */
    public function signal(int $signalNumber, \Closure $activator): int {
        $watcherId = $this->watcherId++;
        $this->watchers[$watcherId] = self::createWatcher(2, $signalNumber, $activator, false);
        return $watcherId;
    }

    /**
     * Activate a watcher
     */
    public function activate(int $watcherId): void {
        $watcher = $this->watchers[$watcherId] ?? null;
        if ($watcher && !$watcher->enabled) {
            switch ($watcher->type) {
                case 0:
                    $fdId = \get_resource_id($watcher->argument);
                    if (!isset($this->readFds[$fdId])) {
                        $this->readFds[$fdId] = $watcher->argument;
                    }
                    $this->readWatchers[$fdId][$watcherId] = $watcher;
                    $watcher->enabled = true;
                    break;
                case 1:
                    $fdId = \get_resource_id($watcher->argument);
                    if (!isset($this->writeFds[$fdId])) {
                        $this->writeFds[$fdId] = $watcher->argument;
                    }
                    $this->writeWatchers[$fdId][$watcherId] = $watcher;
                    $watcher->enabled = true;
                    break;
                case 2:
                    $signalNumber = $watcher->argument;
                    if (!isset($this->signals[$signalNumber])) {
                        \pcntl_signal($signalNumber, function() use ($signalNumber) {
                            foreach ($this->signals[$watcher->argument] as $watcher) {
                                Loop::queueMicrotask($watcher->activator, $signalNumber);
                            }
                        });
                    }
                    $this->signals[$signalNumber][$watcherId] = $watcher;
                    $watcher->enabled = true;
                    break;
            }
        } else {
            throw new \LogicException("Watcher does not exist or is already active");
        }
    }

    /**
     * Suspend a watcher
     */
    public function suspend(int $watcherId): void {
        $watcher = $this->watchers[$watcherId] ?? null;
        if ($watcher && $watcher->enabled) {
            switch ($watcher->type) {
                case 0:
                    $fd = $watcher->argument;
                    $fdId = \get_resource_id($fd);
                    unset($this->readWatchers[$fdId][$watcherId]);
                    if (empty($this->readWatchers[$fdId])) {
                        unset($this->readWatchers[$fdId], $this->readFds[$fdId]);
                    }
                    $watcher->enabled = false;
                    break;
                case 1:
                    $fd = $watcher->argument;
                    $fdId = \get_resource_id($fd);
                    unset($this->writeWatchers[$fdId][$watcherId]);
                    if (empty($this->writeWatchers[$fdId])) {
                        unset($this->writeWatchers[$fdId], $this->writeFds[$fdId]);
                    }
                    $watcher->enabled = false;
                    break;
                case 2:
                    $signalNumber = $watcher->argument;
                    unset($this->signals[$signalNumber][$watcherId]);
                    if (empty($this->signals[$signalNumber])) {
                        \pcntl_signal($signalNumber, \SIG_DFL);
                        unset($this->signals[$signalNumber]);
                    }
                    $watcher->enabled = false;
                    break;
            }
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
            if ($watcher->enabled) {
                $this->suspend($watcherId);
            }
            unset($this->watchers[$watcherId]);
        } else {
            throw new \LogicException("Watcher does not exist");
        }
    }

    private static function createWatcher(int $type, mixed $argument, \Closure $activator, bool $enabled): object {
        return new class($type, $argument, $activator, $enabled) {
            public function __construct(
                public int $type,
                public mixed $argument,
                public \Closure $activator,
                public bool $enabled
            ) {}
        };
    }
}
