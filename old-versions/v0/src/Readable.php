<?php
namespace Co\Loop;

use Co\Loop;

class Readable extends Watcher {

    private $resource;

    public function __construct(mixed $resource) {
        $this->resource = $resource;
    }

}
