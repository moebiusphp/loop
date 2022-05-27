<?php
namespace Moebius\Loop;

use Closure;

final class Event {

    // trigger every time resource becomes readable
    const READABLE = 0;
    // trigger every time resource becomes writable
    const WRITABLE = 1;
    // trigger every time signal is received
    const SIGNAL = 2;
    // trigger once at the time
    const TIMER = 3;
    // repeatedly trigger - next at $time
    const INTERVAL = 4;

    const ACTIVE = 0;
    const SUSPENDED = 1;
    const CANCELLED = 2;

    public $id;
    public $type;
    public $value;
    public $callback;
    public $state;
    public $data = null;
    public $time;

    private static $pool = [];
    private static $poolCount = 0;

    private function __construct(int $id, int $type, mixed $value, Closure $callback, float $time=null) {
        $this->id = $id;
        $this->type = $type;
        $this->value = $value;
        $this->callback = $callback;
        $this->state = self::ACTIVE;
        $this->time = $time;
    }

    public function __destruct() {
        if (self::$poolCount < 100) {
            $this->id = null;
            $this->type = null;
            $this->value = null;
            $this->callback = null;
            $this->time = null;
            $this->suspended = null;
            $this->data = null;
            self::$pool[self::$poolCount++] = $this;
        }
    }

    public function trigger() {
        ($this->callback)($this->value);
    }

    public static function create(int $id, int $type, mixed $value, Closure $callback, float $time=null): self {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
            $instance->__construct($id, $type, $value, $callback, $time);
            return $instance;
        } else {
            return new self($id, $type, $value, $callback, $time);
        }
    }

}
