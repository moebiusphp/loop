<?php
namespace Co\Loop;

use Co\Loop;

class Signal extends AbstractWatcher {

    public function __construct(int $signal) {
        parent::__construct(Loop::getDriver()->signal($resource, $this->fulfill(...)));
    }

}
