<?php

namespace Yauhenko\GSM\Event;

use Symfony\Contracts\EventDispatcher\Event;

abstract class CommonPortEvent extends Event {

	protected string $port;

	public function __construct(string $port) {
		$this->port = $port;
	}

	public function getPort(): string {
		return $this->port;
	}

}
