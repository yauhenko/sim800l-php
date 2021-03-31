<?php

namespace Yauhenko\GSM;

use React\Promise\Promise;
use InvalidArgumentException;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexResourceStream;
use React\Stream\DuplexStreamInterface;

class Sim800L {

	public const EVENT_RING = 'ring';
	public const EVENT_SMS  = 'sms';

	protected ?string $port;
	protected DuplexStreamInterface $stream;
	protected LoopInterface $loop;

	protected ?string $command = null;
	protected mixed $resolve = null;
	protected mixed $reject = null;
	protected bool $debug = false;

	protected array $listeners = [];

	public static function factory(LoopInterface $loop, string $port): self {
		return (new self($loop))->open($port);
	}

	public function __construct(LoopInterface $loop) {
		$this->loop = $loop;
	}

	public function on(string $event, callable $listener): self {
		$this->listeners[$event][] = $listener;
		return $this;
	}

	protected function emit(string $event, mixed $payload): self {
		if(key_exists($event, $this->listeners)) {
			foreach($this->listeners[$event] as $listener) {
				call_user_func($listener, $payload);
			}
		}
		return $this;
	}

	public function open(string $port): self {
		$this->port = $port;
		$this->stream = new DuplexResourceStream(fopen($port, 'rw+'), $this->loop, 1);
		$this->stream->on('data', function(string $char) {
			static $buffer = '';
			static $lines = [];

			$result = $lines;

			if($char === "\n") {

				$line = trim($buffer);

				if($this->debug) echo " < {$line}\n";

				// skip ECHO
				if($line === $this->command) {
					$buffer = '';

				// OK
				} elseif($line === 'OK') {
					$this->command = null;
					$lines = [];
					$buffer = '';
					call_user_func($this->resolve, $result);

				// ERROR
				} elseif($line === 'ERROR') {
					$this->command = null;
					$lines = [];
					$buffer = '';
					call_user_func($this->reject, $result);

				} elseif($line === 'RING') {
					$this->emit(self::EVENT_RING, null);

				} elseif(preg_match('/^\+CLIP: "([0-9\+]+)"/', $line, $clip)) {
					$this->emit(self::EVENT_RING, $clip[1]);

				} elseif(preg_match('/^\+CMTI: "SM",([0-9]+)$/', $line, $sms)) {
					$this->emit(self::EVENT_SMS, (int)$sms[1]);

				} elseif($line === 'BUSY') {
					$buffer = '';

				} elseif($line === 'CONNECT') {
					$buffer = '';

				} elseif($line === 'NO ANSWER') {
					$buffer = '';

				} elseif($line === 'NO CARRIER') {
					$buffer = '';

				} elseif($line !== '') {
					$lines[] = $line;

				}

				$buffer = '';

			} else {
				$buffer .= $char;

			}

		});

		return $this;
	}

	public function init(int $rate = 9600): Promise {
		return
			$this->setBaudRate($rate)
			->then(fn() => $this->command('AT'))
			->then(fn() => $this->command('AT+CMGF=1'))
			->then(fn() => $this->command('AT+CSCS="UCS2"'))
			->then(fn() => $this->command('AT+CLIP=1'));
	}

	public function setBaudRate(int $rate): Promise {
		return $this->exec("stty -F {$this->port} {$rate}");
	}

	public function close(): self {
		$this->stream->close();
		return $this;
	}

	public function command(string $command): Promise {
		return new Promise(function(callable $resolve, callable $reject) use ($command) {
			if($this->command) {
				$reject('Another command in progreess: ' . $this->command);
			} else {
				$this->command = $command;
				$this->resolve = $resolve;
				$this->reject = $reject;
				if($this->debug) echo "> {$command}\n";
				$this->stream->write("{$command}\r");
			}
		});
	}

	public function getSmsList(): Promise {
		return $this->command('AT+CMGL="ALL"')->then(function(array $res) {
			$list = [];
			foreach($res as $idx => $line) {
				if(preg_match('/^\+CMGL\:/', $line)) {
					$list[] = $this->parseSms($line, $res[$idx + 1]);
				}
			}
			return $list;
		});
	}

	protected function parseSms(string $head, string $body, int $id = null): array {
		if(preg_match('/^\+CMG(L|R)\:/', $head, $m)) {
			$head = str_getcsv($head);
			if($m[1] === 'L') {
				$id = (int)explode(': ', $head[0])[1];
				$from = $this->decode($head[2]);
				$date = $head[4];
			} else {
				$from = $this->decode($head[1]);
				$date = $head[3];
			}
			$dt = explode(',', $date);
			$d = explode('/', $dt[0]);
			$dt[1] = explode('+', $dt[1]);
			$date = "20{$d[2]}-{$d[1]}-{$d[0]} {$dt[1][0]}";
			return [
				'id' => $id,
				'from' => $from,
				'date' => $date,
				'text' => $this->decode($body)
			];
		} else {
			throw new InvalidArgumentException('Failed to parse sms');
		}
	}

	public function getSms(int $id): Promise {
		return $this->command('AT+CMGR=' . $id)->then(function(array $res) use ($id) {
			[$head, $body] = $res;
			return $this->parseSms($head, $body, $id);
		});
	}

	public function deleteSms(int $id): Promise {
		return $this->command('AT+CMGD=' . $id);
	}

	protected function decode(string $str): string {
		if(!preg_match('/^[A-F0-9]*$/', $str)) return '<failed to decode>';
		preg_match_all('/.{4}/', strtolower($str), $chars);
		$chars = array_map(fn($s) => "\\u{$s}", $chars[0]);
		return json_decode('"' . implode('', $chars) . '"');
	}

	public function getImei(): Promise {
		return $this->command('AT+GSN')->then(fn($res) => $res[0]);
	}

//	public function setEmei(string $imei): self {
//		$this->command('AT+EGMR=1,7,"' . $imei . '"');
//		$this->command('AT&W');
//		return $this;
//	}

	protected function exec(string $command): Promise {
		return new Promise(function(callable $resolve, callable $reject) use ($command) {
			static $stdout = '', $stderr = '';
			$process = new Process($command);
			$process->start($this->loop);
			$process->stdout->on('data', function(string $chunk) use (&$stdout) {
				$stdout .= $chunk;
			});
			$process->stderr->on('data', function(string $chunk) use (&$stderr) {
				$stderr .= $chunk;
			});
			$process->on('exit', function($exitCode, $termSignal) use (&$stderr, &$stdout, $resolve, $reject) {
				if($exitCode === 0) {
					call_user_func($resolve, $stdout);
				} else {
					call_user_func($reject, $stderr, $exitCode, $termSignal);
				}
			});
		});
	}
//
//	public function getSignal(): int {
//		[$value] = $this->command('AT+CSQ');
//		if(!preg_match('/^\+CSQ\: ([0-9]+),([0-9]+)$/', $value, $m))
//			throw new Exception('Failed go parse signal: ' . $value);
//		$value = (int)$m[1];
//		if($value === 99) return 0;
//		elseif($value >= 15) return 5;
//		elseif($value >= 12) return 4;
//		elseif($value >= 9) return 3;
//		elseif($value >= 6) return 2;
//		elseif($value >= 0) return 1;
//		else return 0;
//	}

//	public function listen() {
//		while(true) {
//			$line = $this->readLine();
//			if($line === 'RING') {
//				echo 'INCOMING CALL' . PHP_EOL;
//			}
//			if(str_contains($line, '+CLIP:')) {
//				echo 'NUBPER: ' . $line . PHP_EOL;
//			}
//			if($line === 'NO CARRIER') {
//				echo 'HANG UP' . PHP_EOL;
//			}
//		}
//	}

}
