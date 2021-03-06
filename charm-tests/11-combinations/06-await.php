<?php
use Moebius\Loop;

$promise = new class {
    public $fulfill, $resolve;

    public function then($fulfill, $resolve) {
        $this->fulfill = $fulfill;
        $this->resolve = $resolve;
    }
};

echo "A";

Loop::delay(0.1, function() use ($promise) {
    ($promise->fulfill)("B");
});

echo Loop::await($promise);

echo "C\n";
