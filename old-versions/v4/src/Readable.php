<?php
namespace Moebius\Loop;

use Closure;
use Moebius\Loop;

class Readable extends AbstractWatcher {

    private Closure $unlistener;

    public function __construct($resource) {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new \TypeError("Expecting a stream resource");
        }
        parent::__construct(Loop::readable($resource, function() use ($resource) {
            $this->fulfill($resource);
        }));
    }

}
