<?php
namespace Moebius\Loop;

class RejectedException extends \Exception {

    public readonly mixed $value;

    public function __construct(mixed $value) {
        parent::__construct("Promise was rejected");
        $this->value = $value;
    }

}

