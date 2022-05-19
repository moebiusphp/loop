<?php
namespace Co\Loop;

use Co\Loop;

class Signal extends AbstractWatcher {

    public function __construct(int $signalNumber) {
        parent::__construct(Loop::signal($signalNumber, $this->fulfill(...)));
    }

}
