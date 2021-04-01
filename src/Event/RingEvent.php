<?php

namespace Yauhenko\GSM\Event;

class RingEvent extends Event {

	protected ?string $number;

	public function __construct(?string $number = null) {
		$this->number = $number;
	}

	public function getNumber(): ?string {
		return $this->number;
	}

}
