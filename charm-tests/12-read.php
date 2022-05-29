<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Loop;

// create a file to test with
$t = tempnam(sys_get_temp_dir(), 'moebius-loop-test-12-');
$data = '';
for ($i = 0; strlen($data) < 200000; $i++) {
    $data .= chr($i % 256);
}
$data = substr(base64_encode($data), 0, 200000);
file_put_contents($t, $data);

$fp = fopen($t, 'r');
$readBytes = 0;
Loop::read($fp, function($chunk) use ($fp, &$readBytes) {
    $readBytes += strlen($chunk);
    fwrite(STDERR, "Has read $readBytes bytes\n");
    if ($readBytes === 200000) {
        echo "All data received\n";
    }
    if(feof($fp)) {
        echo "EOF\n";
    }
});


unlink($t);
