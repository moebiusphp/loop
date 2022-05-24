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

    public $id;
    public $type;
    public $value;
    public $callback;
    public $time;
    public $suspended;

    public function __construct(int $id, int $type, mixed $value, Closure $callback, float $time=null) {
        $this->id = $id;
        $this->type = $type;
        $this->value = $value;
        $this->callback = $callback;
        $this->time = $time;
        $this->suspended = false;
    }

}
