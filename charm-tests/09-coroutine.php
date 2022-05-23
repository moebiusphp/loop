<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Loop;


$coroutine = new Co\Loop\Coroutine(function() {
    $bytes = file_get_contents(__FILE__);
    return strlen($bytes);
});

$coroutine->then(function($bytes) {
    echo "Bytes: $bytes\n";
});
