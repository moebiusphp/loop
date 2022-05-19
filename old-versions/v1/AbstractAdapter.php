<?php
namespace Co\Loop;

abstract class AbstractAdapter implements AdapterInterface {

    private bool $scheduled = false;
    private array $queue = [];

    /**
     * Schedule a task to run in a future tick or when the
     * normal synchronous application ends.
     */
    public function schedule(callable $callback): void {
        $this->queue[] = $callback;
        if (!$this->scheduled) {
            $this->scheduled = true;
            \register_shutdown_function($this->onShutdown(...));
        }
    }

    /**
     * Run one iteration of the event loop
     */
    public function tick(): void {
        while (!empty($this->queue)) {
            foreach ($this->queue as $id => $task) {
                unset($this->queue[$id]);
                try {
                    $task();
                } catch (\Throwable $e) {
                    Loop::handleException($e);
                }
            }
        }
    }

    abstract public function wait(float $maxDelay): void;

    private function onShutdown(): void {
        $this->scheduled = false;
        $this->tick();
    }

    /**
     * Watch for any of the core event types (readable, writable, signal)
     */
    abstract public function watch(int $event, mixed $parameter, callable $handler): int;

    /**
     * Stop watching an event listener
     */
    abstract public function unwatch(int $watcherId): void;

}
