<?php

use Yauhenko\GSM\Sms;
use Yauhenko\GSM\Sim800L;
use React\EventLoop\Factory as EventLoop;
use Yauhenko\GSM\Event\RingEvent;
use Yauhenko\GSM\Event\NewSmsEvent;
use Yauhenko\GSM\Event\HangUpEvent;

include __DIR__ . '/vendor/autoload.php';

$loop = EventLoop::create();

$sim = Sim800L::factory($loop, '/dev/ttyUSB0');

$error = function(Throwable $e) {
	echo "= ERROR " . $e->getMessage() . PHP_EOL;
};

$sim->addEventListener(RingEvent::class, function(RingEvent $event) {
	if(!$event->getNumber()) return;
	echo 'RING! FROM ' . $event->getNumber() . PHP_EOL;
});

$sim->addEventListener(NewSmsEvent::class, function(NewSmsEvent $event) use ($error, $sim) {
	echo 'SMS! ' . $event->getId() . PHP_EOL;
	$sim->readSms($event->getId())->then(function(Sms $sms) {
		print_r($sms);
	})->then(fn() => $sim->deleteSms($event->getId()))->otherwise($error);
});

$sim->addEventListener(HangUpEvent::class, function() {
	echo " Hang Up \n";
});

$sim->init()->then(function() use ($loop, $error, $sim) {
	echo '= INIT OK' . PHP_EOL;

//	$sim->getModuleInfo()->then(function($r)  {
//
//		print_r($r);
//
//
//	})->otherwise($error);

//
//
//	$sim->listSms()->then(function(array $messages) {
//		foreach($messages as $message) {
//			print_r($message);
//		}
//	})->otherwise($error);

//	$serial->getSmsList()->then(function(array $list) {
//		echo '= SMS LIST:' . PHP_EOL;
//		print_r($list);
//	});

})->otherwise($error);


$loop->run();
