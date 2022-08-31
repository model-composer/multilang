<?php namespace Model\Multilang;

use Model\Core\ModelProviderInterface;

class ModelProvider implements ModelProviderInterface
{
	public static function realign(): void
	{
		Ml::realign();
	}

	public static function getDependencies(): array
	{
		return [];
	}
}
