<?php
namespace Co\Loop;

use Co\Loop;

class Writable extends AbstractWatcher {

    public function __construct(mixed $resource) {
        parent::__construct(Loop::writable($resource, $this->fulfill(...)));
    }

}
