<?php
namespace Moebius\Loop;

final class EventHandle {
    private static $pool = [];
    private static $poolCount = 0;

    private $id;
    private $driver;
    private $resumeFunction = null;

    public function __construct(DriverInterface $driver, $eventId) {
        $this->id = $eventId;
        $this->driver = $driver;
    }

    public function __destruct() {
        if (self::$poolCount >= 1000) {
            return;
        }
        $this->id = null;
        $this->driver = null;
        $this->resumeFunction = null;
        self::$pool[self::$poolCount++] = $this;
    }

    public function isSuspended(): bool {
        return $this->resumeFunction !== null;
    }

    public function isCancelled(): bool {
        return $this->id === null;
    }

    public function cancel(): void {
        if ($this->isCancelled()) {
            throw new \LogicException("EventHandle has already been cancelled");
        }
        if ($this->resumeFunction) {
            // suspended so no need to cancel, just remove the resume func
            $this->resumeFunction = null;
        } else {
            $this->driver->cancel($this->id);
        }
        $this->id = null;
    }

    public function suspend(): void {
        if (null === $this->id) {
            throw new \LogicException("EventHandle has been cancelled");
        }
        if ($this->resumeFunction) {
            // already suspended
            return;
        } else {
            $this->resumeFunction = $this->driver->suspend($this->id);
            if ($this->resumeFunction === null) {
                throw new \LogicException("Unable to suspend event handle id ".$this->id);
            }
        }
    }

    public function resume(): void {
        if (null === $this->id) {
            throw new \LogicException("EventHandle has been cancelled");
        }
        if ($this->resumeFunction !== null) {
            ($this->resumeFunction)();
            $this->resumeFunction = null;
        }
    }

    public function __invoke() {
        $this->cancel();
    }

    public static function create(DriverInterface $driver, int $eventId): self {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
            $instance->__construct($driver, $eventId);
            return $instance;
        } else {
            return new self($driver, $eventId);
        }
    }
}
