<?php

declare(strict_types=1);

namespace Duon\Registry\Tests\Fixtures;

class UserSession
{
	public function __construct(
		public string $sessionId = '',
		public array $data = []
	) {
		if (empty($this->sessionId)) {
			$this->sessionId = bin2hex(random_bytes(16));
		}
	}

	public function set(string $key, mixed $value): void
	{
		$this->data[$key] = $value;
	}

	public function get(string $key): mixed
	{
		return $this->data[$key] ?? null;
	}
}