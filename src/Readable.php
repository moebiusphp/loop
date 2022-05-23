<?php
namespace Moebius\Loop;

use Moebius\Loop;

class Readable extends AbstractWatcher {

    public function __construct(mixed $resource) {
        parent::__construct(Loop::readable($resource, $this->fulfill(...)));
    }

}
