<?php

namespace Yauhenko\GSM;

use DateTimeInterface;

class Sms {

	protected int $id;
	protected string $from;
	protected string $message;
	protected DateTimeInterface $date;

	private function __construct(array $data) {
		foreach($data as $key => $value) {
			if(property_exists(self::class, $key)) {
				$this->$key = $value;
			}
		}
	}

	public function getId(): int {
		return $this->id;
	}

	public function getFrom(): string {
		return $this->from;
	}

	public function getMessage(): string {
		return $this->message;
	}

	public function getDate(): DateTimeInterface {
		return $this->date;
	}

	public static function factory(array $data): self {
		return new self($data);
	}

}
