<?php
require(__DIR__.'/../vendor/autoload.php');

use Co\Loop;

$fn = tempnam(sys_get_temp_dir(), 'coco');

posix_mkfifo($fn.".fifo", 0600);
exec("(sleep 0.5; echo B > ".escapeshellarg($fn.".fifo").") > /dev/null &");

$fp = fopen($fn.".fifo", "rn");

Loop::readable($fp)->then(function($fp) use ($fn) {
    echo stream_get_contents($fp);
    unlink($fn);
    unlink($fn.".fifo");
});
Loop::delay(0.75)->then(function() {
    echo "C\n";
});
Loop::delay(0.25)->then(function() {
    echo "A\n";
});
