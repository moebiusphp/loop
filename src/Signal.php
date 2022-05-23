<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Signal extends AbstractWatcher {

    public function __construct(int $signalNumber) {
        parent::__construct(Loop::signal($signalNumber, $this->fulfill(...)));
    }

}
