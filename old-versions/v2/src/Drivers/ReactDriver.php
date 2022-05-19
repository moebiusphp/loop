<?php
namespace Co\Loop\Drivers;

use Co\Loop;
use Co\Loop\DriverInterface;
use Co\Loop\WatcherInterface;
use React\EventLoop\Loop as React;


class ReactDriver implements DriverInterface {

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
            \register_shutdown_function(React::run(...));
            React::futureTick(function() {
                $this->scheduled = false;
                Loop::tick();
            });
        }
    }

    /**
     * Run events
     */
    public function tick(?float $maxDelay): void {
        if ($maxDelay === null) {
            React::addTimer(0.1, function() {
                React::stop();
            });
            React::run();
        } else {
            React::addTimer($maxDelay, function() {
                React::stop();
            });
            React::run();
        }
    }

    /**
     * Create a watcher for stream readable
     */
    public function readable(mixed $fd, \Closure $activator): int {
        $this->watchers[$this->watcherId] = self::createWatcher(0, $fd, $activator, false);
        return $this->watcherId++;
    }

    /**
     * Create a watcher for stream readable
     */
    public function writable(mixed $fd, \Closure $activator): int {
        $this->watchers[$this->watcherId] = self::createWatcher(0, $fd, $activator, false);
        return $this->watcherId++;
    }

    /**
     * Create a watcher for signals
     */
    public function signal(int $signalNumber, \Closure $activator): int {
        $this->watchers[$this->watcherId] = self::createWatcher(2, $signalNumber, $activator, false);
        return $this->watcherId++;
    }

    /**
     * Activate a watcher
     */
    public function activate(int $watcherId): void {
        $watcher = $this->watchers[$watcherId] ?? null;
        if ($watcher && !$watcher->enabled) {
            switch ($watcher->type) {
                case 0:
                    $fd = $watcher->argument;
                    $fdId = \get_resource_id($fd);
                    if (!isset($this->readFds[$fdId])) {
                        $this->readFds[$fdId] = $fd;
                        React::addReadStream($fd, function() use ($fd, $fdId) {
                            foreach ($this->readWatchers[$fdId] as $watcher) {
                                Loop::queueMicrotask(function() use ($watcher, $fd) {
                                    if ($watcher->enabled) {
                                        ($watcher->activator)($fd);
                                    }
                                });
                            }
                        });
                    }
                    $this->readWatchers[$fdId][$watcherId] = $watcher;
                    $watcher->enabled = true;
                    break;
                case 1:
                    $fd = $watcher->argument;
                    $fdId = \get_resource_id($fd);
                    if (!isset($this->writeFds[$fdId])) {
                        $this->writeFds[$fdId] = $fd;
                        React::addWriteStream($fd, function() use ($fd, $fdId) {
                            foreach ($this->writeWatchers[$fdId] as $watcher) {
                                Loop::queueMicrotask(function() use ($watcher, $fd) {
                                    if ($watcher->enabled) {
                                        ($watcher->activator)($fd);
                                    }
                                });
                            }
                        });
                    }
                    $this->writeWatchers[$fdId][$watcherId] = $watcher;
                    $watcher->enabled = true;
                    break;
                case 2:
                    $signalNumber = $watcher->argument;
                    React::addSignal($signalNumber, $watcher->activator);
                    $watcher->enabled = true;
                    break;
            }
            $this->schedule();
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
                        React::removeReadStream($fd);
                    }
                    $watcher->enabled = false;
                    break;
                case 1:
                    $fd = $watcher->argument;
                    $fdId = \get_resource_id($fd);
                    unset($this->writeWatchers[$fdId][$watcherId]);
                    if (empty($this->writeWatchers[$fdId])) {
                        unset($this->writeWatchers[$fdId], $this->writeFds[$fdId]);
                        React::removeWriteStream($fd);
                    }
                    $watcher->enabled = false;
                    break;
                case 2:
                    $signalNumber = $watcher->argument;
                    React::removeSignal($signalNumber, $watcher->activator);
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
