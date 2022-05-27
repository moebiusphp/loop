<?php
namespace Moebius\Loop\Util;

use Closure;

class ClosureTool {

    public $closure, $ref;

    public function __construct(Closure $closure) {
        $this->closure = $closure;
        $this->ref = new \ReflectionFunction($this->closure);
    }

    public function dump(): string {
        $name = $this->ref->getFileName().":".$this->ref->getStartLine()." ".$this->ref->getName()."\n";
        return $name;
    }
}
