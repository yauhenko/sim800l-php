<?php

namespace Yauhenko\GSM\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class SmsReceivedEvent extends Event {

	protected int $id;

	public function __construct(int $id) {
		$this->id = $id;
	}

	public function getId(): int {
		return $this->id;
	}

}
