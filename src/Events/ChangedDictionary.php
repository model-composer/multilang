<?php namespace Model\Multilang\Events;

use Model\Events\AbstractEvent;

class ChangedDictionary extends AbstractEvent
{
	public function __construct(public string $type, public string $section, public string $word, public ?array $values = null)
	{
	}

	public function getData(): array
	{
		return [
			'type' => $this->type,
			'section' => $this->section,
			'word' => $this->word,
			'values' => $this->values,
		];
	}
}
