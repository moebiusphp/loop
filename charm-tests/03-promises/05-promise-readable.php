<?php
use Moebius\Loop;
use Moebius\Loop\Readable;

$fp = fopen(__FILE__, 'rn');

$readable = new Readable($fp);

$readable->then(function($fp) {
    echo "A";
}, function($e) {
    assert(!$e, "Rejected");
})->then(function() {
    echo "B";
});

$readable->then(function() {
    echo "C\n";
});
