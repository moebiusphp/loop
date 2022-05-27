<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Writable extends AbstractWatcher {

    public function __construct(mixed $resource, float $timeout=null) {
        parent::__construct(Loop::writable(...), $resource, $timeout);
    }

}
