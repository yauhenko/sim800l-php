<?php

namespace Yauhenko\GSM\Event;

final class CommonEvent extends Event {

	protected string $event;

	/** @var mixed $payload */
	protected $payload;

	/**
	 * CommonEvent constructor
	 *
	 * @param string $event
	 * @param mixed $payload
	 */
	public function __construct(string $event, $payload = null) {
		$this->event = $event;
		$this->payload = $payload;
	}

	public function getEvent(): string {
		return $this->event;
	}

	/**
	 * @return mixed
	 */
	public function getPayload() {
		return $this->payload;
	}

}
