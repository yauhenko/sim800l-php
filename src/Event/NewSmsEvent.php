<?php

namespace Yauhenko\GSM\Event;

class NewSmsEvent extends Event {

	protected int $id;

	public function __construct(int $id) {
		$this->id = $id;
	}

	public function getId(): int {
		return $this->id;
	}

}
