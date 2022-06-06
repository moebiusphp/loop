<?php
namespace Moebius\Loop;

use Closure;
use Moebius\Loop;

class Signal extends AbstractWatcher {

    private Closure $unlistener;

    public function __construct(int $signalNumber) {
        parent::__construct(Loop::writable($resource, function() use ($signalNumber) {
            $this->fulfill($signalNumber);
        }));
    }

}
