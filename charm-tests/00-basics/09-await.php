<?php
use Moebius\Loop;

$promise = new Moebius\Deferred();

Loop::delay(0.1, function() use ($promise) {
    $promise->fulfill("OK\n");
});

echo Loop::await($promise);
