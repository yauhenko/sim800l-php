<?php

namespace Yauhenko\GSM\Event;

class HangUpEvent extends Event {

	protected ?string $reason;

	public function __construct(?string $reason = null) {
		$this->reason = $reason;
	}

	public function getReason(): ?string {
		return $this->reason;
	}

}
