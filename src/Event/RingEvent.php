<?php

namespace Yauhenko\GSM\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class RingEvent extends Event {

	protected ?string $number;

	public function __construct(?string $number = null) {
		$this->number = $number;
	}

	public function getNumber(): ?string {
		return $this->number;
	}

}
