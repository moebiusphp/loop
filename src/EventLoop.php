<?php
namespace Moebius\Loop;

use Closure;

class EventLoop implements ChildEventLoopInterface {

    /**
     * State if the event loop has no deferred functions and therefore does not
     * need to be scheduled to run.
     */
    protected const NOT_SCHEDULED = 0;

    /**
     * State if the event loop has deferred functions and needs to schedule with
     * the parent event loop, but is stopped.
     */
    protected const NEED_SCHEDULING = 1;

    /**
     * State when the event loop has deferred functinos and the deferred functions
     * will be run by the parent event loop.
     */
    protected const SCHEDULED = 2;

    /**
     * Array of callbacks with offsets from $this->defLow to $this->defHigh
     */
    protected array $deferred = [];
    protected array $deferredArgs = [];
    protected int $defLow = 0, $defHigh = 0;

    /**
     * Array of microtasks with offsets from $this->micLow to $this->micHigh
     */
    protected array $microtasks = [];

    /**
     * Array of microtask arguments for microtasks with the same offset in
     * $this->microtasks.
     */
    protected array $microtaskArgs = [];
    protected int $micLow = 0, $micHigh = 0;

    /**
     * Reference to the parent event-loop which handles the actual scheduling
     * and event detection for this event-loop.
     */
    protected DriverInterface $parent;

    protected RootEventLoopInterface $root;

    /**
     * Alternative exception handler for this child event loop.
     */
    protected ?Closure $exceptionHandler = null;

    /**
     * Is the event loop suspended/prevented from running (stopped)
     */
    protected bool $suspended = false;

    /**
     * The state of deferred callbacks
     */
    protected int $scheduled = self::NOT_SCHEDULED;

    /**
     * Callback for setting the main loop driver
     */
    protected Closure $swapMainLoop;

    public function __construct(DriverInterface $parent, Closure $swapMainLoop, Closure $exceptionHandler=null) {
        $this->parent = $parent;
        $root = $this->parent;
        while (!($root instanceof RootEventLoopInterface)) {
            $root = $root->getParent();
        }
        $this->root = $root;
        $this->swapMainLoop = $swapMainLoop;
        $this->exceptionHandler = $exceptionHandler;
    }

    public function getParent(): DriverInterface {
        return $this->parent;
    }

    private function schedule(): void {
        if ($this->scheduled === self::SCHEDULED) {
            return;
        } elseif ($this->suspended) {
            $this->scheduled = self::NEED_SCHEDULING;
        } else {
            $this->scheduled = self::SCHEDULED;
            $this->parent->defer($this->tick(...));
        }
    }

    private function tick(): void {
        if ($this->suspended) {
            throw new \LogicException("Tick invoked but this child loop is suspended and the scheduled state is ".$this->scheduled);
        }
        $this->scheduled = self::NOT_SCHEDULED;
        $previousLoop = ($this->swapMainLoop)($this);
        try {
            $defHigh = $this->defHigh;
            while (!$this->suspended && ($this->defLow < $defHigh || $this->micLow < $this->micHigh)) {
                if ($this->micLow === $this->micHigh) {
                    $callback = $this->deferred[$this->defLow];
                    $args = $this->deferredArgs[$this->defLow];
                    unset($this->deferred[$this->defLow], $this->deferredArgs[$this->defLow]);
                    ++$this->defLow;
                    $callback(...$args);
                }
                while (!$this->suspended && ($this->micLow < $this->micHigh)) {
                    $callback = $this->microtasks[$this->micLow];
                    $args = $this->microtasks[$this->micLow];
                    unset($this->microtasks[$this->micLow], $this->microtaskArgs[$this->micLow]);
                    ++$this->micLow;
                    $callback(...$args);
                }
            }
        } catch (\Throwable $e) {
            if ($this->exceptionHandler) {
                ($this->exceptionHandler)($e);
            } else {
                throw $e;
            }
        } finally {
            ($this->swapMainLoop)($previousLoop);
            if ($this->defLow < $this->defHigh || $this->micLow < $this->micHigh) {
                $this->schedule();
            }
        }
    }

    public function getTime(): float {
        return $this->parent->getTime();
    }

    public function poll(Closure $callback): void {
        $this->parent->poll($this->wrap($callback));
    }

    public function await(object $promise): mixed {
        $this->parent->await($promise);
    }

    public function run(): void {
        throw new \LogicException("Can't run a child event loop. The loop is probably already running.");
    }

    public function stop(): void {
        throw new \LogicException("Can't stop a child event loop. The main loop is probably running.");
    }

    public function defer(Closure $callback, mixed ...$args): void {
        $this->schedule();
        $this->deferred[$this->defHigh] = $callback;
        $this->deferredArgs[$this->defHigh++] = $args;
    }

    public function queueMicrotask(Closure $callback, mixed ...$args): void {
        $this->schedule();
        $this->microtasks[$this->micHigh] = $callback;
        $this->microtaskArgs[$this->micHigh++] = $args;
    }

    public function readable($resource, Closure $callback=null): Handler {
        $parentHandler = $this->root->readable($resource);

        [$handler, $fulfill] = Handler::create($parentHandler->cancel(...));

        $parentHandler->then(function($resource) use ($fulfill) {
            $this->defer($fulfill, $resource);
        }, $handler->cancel(...));

        return $handler;
    }

    public function writable($resource): Handler {
        $parentHandler = $this->root->writable($resource);

        [$handler, $fulfill] = Handler::create($parentHandler->cancel(...));

        $parentHandler->then(function($resource) use ($fulfill) {
            $this->defer($fulfill, $resource);
        }, $handler->cancel(...));

        return $handler;
    }

    public function delay(float $time): Handler {
        $parentHandler = $this->root->delay($time);
        [$handler, $fulfill] = Handler::create($parentHandler->cancel(...));

        $parentHandler->then(function($time) use ($fulfill) {
            $this->defer($fulfill, $time);
        }, $handler->cancel(...));

        return $handler;
    }

    public function suspend(): Closure {
        if ($this->suspended) {
            throw new \LogicException("The loop is already suspended");
        }
        $resumed = false;
        $this->suspended = true;

        return function() use (&$resumed) {
            // Let this callback only work once
            if ($resumed) {
                throw new \LogicException("The event loop has already been resumed by this callback");
            }
            $resumed = true;
            $this->suspended = false;
            if ($this->scheduled === self::NEED_SCHEDULING) {
                $this->schedule();
            }
        };
    }

    protected function wrap(Closure $callback, mixed ...$args): Closure {
        return function() {
            $previousLoop = ($this->swapMainLoop)($this);
            assert($previousLoop === $this->parent, "Callback not invoked from the parent event loop. Probably unneccesary wrapping of callback.");
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                if ($this->exceptionHandler) {
                    ($this->exceptionHandler)($e);
                } else {
                    throw $e;
                }
            } finally {
                ($this->swapMainLoop)($previousLoop);
            }
        };
    }

}
