<?php
namespace Co\Loop\Adapters;

use Co\Loop\AbstractAdapter;

class EvAdapter extends AbstractAdapter {

    private array $watchers = [];
    private static $nextId = 1;

    /**
     * Adapter wait function
     */
    public function wait(float $maxTime): void {
        if ($maxTime > 0) {
            // Register a timer to ensure the wait function will stop
            $timer = new \EvTimer($maxTime, 0.0, static function() {});
            \Ev::run(\Ev::RUN_ONCE);
        } else {
            \Ev::run(\Ev::RUN_NOWAIT);
        }
    }

    public function hasWatchers(): bool {
        return !empty($this->watchers);
    }


    /**
     * Watch for any of the essential event types. The event watcher MUST be unwatched()
     * to avoid memory leaks.
     */
    public function watch(int $eventId, mixed $parameter, callable $handler): int {
        $watchKey = 0;
        if (0 !== ($eventId & AdapterInterface::READABLE)) {
            $watchKey |= \Ev::READ;
        }
        if (0 !== ($eventId & AdapterInterface::WRITABLE)) {
            $watchKey |= \Ev::WRITE;
        }
        if ($watchKey !== 0) {
            // this is an read and/or write event listener
            if ($watchKey !== $eventId) {
                throw new \InvalidArgumentException("Invalid event identifier combination (event=$event)");
            }
            if (!is_resource($parameter)) {
                throw new \TypeError("Expecting a stream resource as parameter");
            }
            self::$watchers[self::$nextId] = new \EvIo($parameter, $watchKey, $handler);
            return self::$nextId++;
        } elseif ($eventId === AdapterInterface::SIGNAL) {
            self::$watchers[self::$nextId] = new \Evsignal($parameter, $handler);
            return self::$nextId++;
        } else {
            throw new \InvalidArgumentException("Unrecognized event identifier (event=$event)");
        }
    }

    /**
     * Stop watching an event listener
     */
    public function unwatch(int $watcherId): void {
        if (isset(self::$watchers[$watcherId])) {
            self::$watchers[$watcherId]->stop();
            unset(self::$watchers[$watcherId]);
        }
    }
}
