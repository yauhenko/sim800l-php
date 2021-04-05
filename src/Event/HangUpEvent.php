<?php

namespace Yauhenko\GSM\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class HangUpEvent extends Event {

	protected ?string $reason;

	public function __construct(?string $reason = null) {
		$this->reason = $reason;
	}

	public function getReason(): ?string {
		return $this->reason;
	}

}
