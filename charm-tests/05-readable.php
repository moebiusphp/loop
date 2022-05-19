<?php
require(__DIR__.'/../vendor/autoload.php');

use Co\Loop;
use Co\Loop\Readable;

$fp = fopen(__FILE__, 'rn');

$readable = new Readable($fp);
$readable->then(function($fp) {
    echo "A";
}, function($e) {
    assert(!$e, "Rejected");
})->then(function() {
    echo "B";
});

Loop::await($readable);
echo "C\n";
die();
/*
$fn = tempnam(sys_get_temp_dir(), 'coco');

posix_mkfifo($fn.".fifo", 0600);
exec("(sleep 0.5; echo ABC > ".escapeshellarg($fn.".fifo").") > /dev/null &");
echo "fopen\n";
$fp = fopen($fn.".fifo", "rn");

$readable = new Readable($fp);
echo "await\n";
$returnedFP = Loop::await($readable);

assert($fp === $returnedFP, "Watcher didn't resolve with the resource");

echo fgetc($fp)."\n";

$readable = new Readable($fp);
while (!feof($fp)) {
    Loop::await($readable);
    echo fgetc($fp)."\n";
}
*/
