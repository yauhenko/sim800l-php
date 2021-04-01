<?php /** @noinspection PhpIncompatibleReturnTypeInspection */

namespace Yauhenko\GSM;

use DateTime;
use RuntimeException;
use DateTimeInterface;
use React\Promise\Promise;
use InvalidArgumentException;
use Yauhenko\GSM\Event\Event;
use React\ChildProcess\Process;
use Yauhenko\GSM\Event\RingEvent;
use React\EventLoop\LoopInterface;
use Yauhenko\GSM\Event\NewSmsEvent;
use Yauhenko\GSM\Event\HangUpEvent;
use Yauhenko\GSM\Event\CommonEvent;
use React\Stream\DuplexResourceStream;
use React\Stream\DuplexStreamInterface;
use React\Promise\ExtendedPromiseInterface;

class Sim800L {

	public const POWER_SAVING_MINIMAL = 0;
	public const POWER_SAVING_DISABLED = 1;
	public const POWER_SAVING_DISABLE_SIGNAL = 2;

	protected ?string $port;
	protected DuplexStreamInterface $stream;
	protected LoopInterface $loop;

	protected ?string $command = null;

	/** @var callable $resolve  */
	protected $resolve = null;

	/** @var callable $reject  */
	protected $reject = null;
	protected bool $debug = false;

	protected array $listeners = [];

	public static function factory(LoopInterface $loop, string $port): self {
		return (new self($loop))->open($port);
	}

	public function __construct(LoopInterface $loop) {
		$this->loop = $loop;
	}

	// Events

	public function addEventListener(string $event, callable $listener): self {
		$this->listeners[$event][] = $listener;
		return $this;
	}

	protected function dispatch(Event $event): self {
		$class = get_class($event);
		if ($this->debug) echo ' >>>>>>>>> EVENT ' . $class . PHP_EOL;
		if(key_exists($class, $this->listeners)) {
			foreach($this->listeners[$class] as $listener) {
				call_user_func($listener, $event);
			}
		}
		return $this;
	}

	// Port

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
				} elseif($line === 'ERROR' || strpos($line, '+CME ERROR') === 0) {
					$line = trim(str_replace('+CME ERROR:', '', $line));
					$this->command = null;
					$lines = [];
					$buffer = '';
					call_user_func($this->reject, new RuntimeException($line));

				} elseif($line === 'RING') {
					$this->dispatch(new RingEvent);

				} elseif(preg_match('/^\+CLIP: "([0-9\+]+)"/', $line, $clip)) {
					$this->dispatch(new RingEvent($clip[1]));

				} elseif(preg_match('/^\+CMTI: "SM",([0-9]+)$/', $line, $sms)) {
					$this->dispatch(new NewSmsEvent((int)$sms[1]));

				} elseif($line === 'BUSY') {
					$this->dispatch(new HangUpEvent($line));
//
				} elseif($line === 'NO DIALTONE') {
					$this->dispatch(new HangUpEvent($line));
//
				} elseif($line === 'NO CARRIER') {
					$this->dispatch(new HangUpEvent($line));
//
				} elseif($line === 'NO ANSWER') {
					$this->dispatch(new HangUpEvent($line));

				} elseif($line === 'CONNECT') {
					$this->dispatch(new CommonEvent($line));

				} elseif($line === 'Call Ready') {
					$this->dispatch(new CommonEvent('CALL READY'));

				} elseif($line === 'SMS Ready') {
					$this->dispatch(new CommonEvent('SMS READY'));

				} elseif($line === 'NORMAL POWER DOWN') {
					$this->dispatch(new CommonEvent('POWER DOWN'));

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

	public function setBaudRate(int $rate): ExtendedPromiseInterface {
		return $this->exec("stty -F {$this->port} {$rate}");
	}

	public function close(): self {
		$this->stream->close();
		return $this;
	}

	// Main

	public function init(int $rate = 9600): ExtendedPromiseInterface {
		return
			$this->setBaudRate($rate)
			->then(fn() => $this->command('AT'))
			->then(fn() => $this->command('AT+CMEE=2'))
			->then(fn() => $this->command('AT+CMGF=1'))
			->then(fn() => $this->command('AT+CSCS="UCS2"'))
			->then(fn() => $this->command('AT+CLIP=1'));
	}

	// Info

	public function getModuleInfo(): ExtendedPromiseInterface {
		$about = [];
		return $this->command('AT+GMM')->then(function(array $res) use (&$about) {
			$about['name'] = $res[0];
		})->then(fn() => $this->command('AT+GMR'))->then(function(array $res) use (&$about) {
			$about['version'] = $res[0];
		})->then(function() use (&$about) {
			return $about;
		});
	}

	public function getOperator(): ExtendedPromiseInterface {
		return $this->command('AT+COPS?')->then(function(array $res) {
			$res = explode(':', $res[0]);
			$res = str_getcsv(trim($res[1]));
			return $res[2];
		});
	}

	public function getModuleStatus(): ExtendedPromiseInterface {
		return $this->command('AT+CPAS')->then(function(array $res) {
			if(preg_match('/^\+CPAS: ([0-4]+)$/', $res[0], $m)) {
				switch((int)$m[1]) {
					case 0: return 'ready';
					case 2: return 'unknown';
					case 3: return 'ring';
					case 4: return 'voice';
					default: return 'na';
				}
			} else {
				throw new RuntimeException('Unexpected response: ' . $res[0]);
			}
		});
	}

	public function getRegistrationStatus(): ExtendedPromiseInterface {
		return $this->command('AT+CREG?')->then(function(array $res) {
			if(preg_match('/^\+CREG: ([0-2]+),([0-4]+)$/', $res[0], $m)) {
				return [
					0 => 'unreg',
					1 => 'home',
					2 => 'search',
					3 => 'reject',
					4 => 'unknown',
					5 => 'roaming'
				][(int)$m[2]];
			} else {
				throw new RuntimeException('Unexpected response: ' . $res[0]);
			}
		});
	}

	public function getSignalLevel(): ExtendedPromiseInterface {
		return $this->command('AT+CSQ')->then(function(array $res) {
			if(preg_match('/^\+CSQ\: ([0-9]+),([0-9]+)$/', $res[0], $m)) {
				$value = (int)$m[1];
				if($value === 99) return 0;
				elseif($value >= 15) return 5;
				elseif($value >= 12) return 4;
				elseif($value >= 9) return 3;
				elseif($value >= 6) return 2;
				elseif($value >= 0) return 1;
				else return 0;
			} else {
				throw new RuntimeException('Unexpected response: ' . $res[0]);
			}
		});
	}

	// Calls

	public function dial(string $number): ExtendedPromiseInterface {
		return $this->command('ATD' . $number . ';')->then(fn() => true);
	}

	public function answer(): ExtendedPromiseInterface {
		return $this->command('ATA')->then(fn() => true);
	}

	public function hangUp(): ExtendedPromiseInterface {
		return $this->command('ATH0')->then(function() {
			$this->dispatch(new HangUpEvent());
		});
	}

	// SMS

	public function listSms(): ExtendedPromiseInterface {
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

	public function readSms(int $id): ExtendedPromiseInterface {
		return $this->command('AT+CMGR=' . $id)->then(function(array $res) use ($id) {
			[$head, $body] = $res;
			return $this->parseSms($head, $body, $id);
		});
	}

	public function deleteSms(int $id): ExtendedPromiseInterface {
		return $this->command('AT+CMGD=' . $id);
	}

	// IMEI

	public function getImei(): ExtendedPromiseInterface {
		return $this->command('AT+GSN')->then(fn($res) => $res[0]);
	}

	public function setEmei(string $imei): ExtendedPromiseInterface {
		return $this->command('AT+EGMR=1,7,"' . $imei . '"')->then(fn() => true);
	}

	// PIN

	public function isPinEnabled(): ExtendedPromiseInterface {
		return $this->command('AT+CPIN?')->then(function(array $res) {
			if($res[0] === '+CPIN: READY') return false;
			if($res[0] === '+CPIN: SIM PIN') return true;
			throw new RuntimeException('Unexpected response: ' . $res[0]);
		});
	}

	public function enterPin(string $pin): ExtendedPromiseInterface {
		return $this->command('AT+CPIN=' . $pin)->then(fn() => true);
	}

	public function enablePin(string $pin): ExtendedPromiseInterface {
		return $this->command('AT+CLCK="SC",1,"' . $pin . '"')->then(fn() => true);
	}

	public function disablePin(string $pin): ExtendedPromiseInterface {
		return $this->command('AT+CLCK="SC",0,"' . $pin . '"')->then(fn() => true);
	}

	// Settings

	public function setDateTime(?DateTimeInterface $dateTime = null): ExtendedPromiseInterface {
		if(!$dateTime) $dateTime = new DateTime;
		return $this->command('AT+CCLK="' . $dateTime->format('d/m/y,H:i:s+00') . '"');
	}

	public function setDefaultSettings(int $profileId = 0): ExtendedPromiseInterface {
		return $this->command('ATZ' . $profileId)->then(fn() => true);
	}

	public function resetSettings(): ExtendedPromiseInterface {
		return $this->command('AT&F')->then(fn() => true);
	}

	public function saveSettings(int $profileId = 0): ExtendedPromiseInterface {
		return $this->command('AT&W' . $profileId)->then(fn() => true);
	}

	// Power management

	public function reboot(): ExtendedPromiseInterface {
		return $this->command('AT+CFUN=1,1');
	}

	public function powerDown(bool $force = false): ExtendedPromiseInterface {
		return $this->command('AT+CPOWD=' . ($force ? 0 : 1));
	}

	public function setPowerSavingMode(int $mode, bool $reboot = false): ExtendedPromiseInterface {
		return $this->command('AT+CFUN=' . $mode . ',' . (int)$reboot);
	}

	// Other private tools

	protected function command(string $command): ExtendedPromiseInterface {
		return new Promise(function(callable $resolve, callable $reject) use ($command) {
			if($this->command) {
//				throw new RuntimeException('Another command in progreess: ' . $this->command);
				//$reject('Another command in progreess: ' . $this->command);
				call_user_func($reject, new RuntimeException('Another command in progreess: ' . $this->command));
			} else {
				$this->command = $command;
				$this->resolve = $resolve;
				$this->reject = $reject;
				if($this->debug) echo "> {$command}\n";
				$this->stream->write("{$command}\r");
			}
		});
	}

	protected function exec(string $command): ExtendedPromiseInterface {
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

	protected function decodeUCS2(string $str): string {
		if(!preg_match('/^[A-F0-9]*$/', $str)) return '<failed to decode>';
		preg_match_all('/.{4}/', strtolower($str), $chars);
		$chars = array_map(fn($s) => "\\u{$s}", $chars[0]);
		return json_decode('"' . implode('', $chars) . '"');
	}

	protected function parseSms(string $head, string $body, int $id = null): Sms {
		if(preg_match('/^\+CMG(L|R)\:/', $head, $m)) {
			$head = str_getcsv($head);
			if($m[1] === 'L') {
				$id = (int)explode(': ', $head[0])[1];
				$from = $this->decodeUCS2($head[2]);
				$date = $head[4];
			} else {
				$from = $this->decodeUCS2($head[1]);
				$date = $head[3];
			}
			$dt = explode(',', $date);
			$d = explode('/', $dt[0]);
			$dt[1] = explode('+', $dt[1]);
			$date = "20{$d[2]}-{$d[1]}-{$d[0]} {$dt[1][0]}";
			return Sms::factory([
				'id' => $id,
				'from' => $from,
				'date' => new DateTime($date),
				'message' => $this->decodeUCS2($body)
			]);
		} else {
			throw new InvalidArgumentException('Failed to parse sms');
		}
	}

}
