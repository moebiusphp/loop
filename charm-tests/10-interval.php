<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Loop;
use Moebius\Loop\Interval;

$start = microtime(true);
$timer = new Interval(0.2);

$timer->then($func = function() use ($timer, &$func) {
    echo "!";
    $timer->then($func);
});

Loop::delay(0.3, function() use ($timer) {
//echo "suspending timer\n";
    $timer->suspend();
});
Loop::delay(0.5, function() use ($timer) {
//echo "resuming timer\n";
    $timer->resume();
});
Loop::delay(0.7, function() use ($timer) {
//echo "cancelling timer\n";
    $timer->cancel();
});

//die();
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
