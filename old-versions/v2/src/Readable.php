<?php
namespace Co\Loop;

use Co\Loop;

class Readable extends AbstractWatcher {

    public function __construct(mixed $resource) {
        parent::__construct(Loop::getDriver()->readable($resource, $this->fulfill(...)));
    }

}
