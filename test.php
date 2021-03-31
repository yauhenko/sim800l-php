<?php

use Yauhenko\GSM\Sim800L;

include __DIR__ . '/vendor/autoload.php';


$loop = React\EventLoop\Factory::create();

$serial = Sim800L::factory($loop, '/dev/ttyUSB0');


$error = function(...$args) {
	echo "= ERRORKA!" . print_r($args, 1) . PHP_EOL;
	var_dump($args);
};

$serial->on('ring', function(?string $number) {
	if(!$number) return;
	echo 'RING! FROM ' . $number . PHP_EOL;
});

$serial->on('sms', function(int $id) use ($error, $serial) {
	echo 'SMS! ' . $id . PHP_EOL;
	$serial->getSms($id)->then(function(array $sms) {
		print_r($sms);
	})->then(fn() => $serial->deleteSms($id))->otherwise($error);
});

$serial->init()->then(function() use ($error, $serial) {
	echo '= INIT OK' . PHP_EOL;

//	$serial->getSms(16)->then(function(array $sms) {
//		print_r($sms);
//	})
//		->otherwise($error);

//	$serial->getSmsList()->then(function(array $list) {
//		echo '= SMS LIST:' . PHP_EOL;
//		print_r($list);
//	});

})->otherwise($error);




//
//

//
//$serial->getImei()->then(function(string $imei) {
//	echo "  IMEI OK: {$imei}\n";
//});

//echo $serial->getSignal();
//$serial->listen();


//$loop = React\EventLoop\Factory::create();
//
//$loop->run();



$loop->run();
