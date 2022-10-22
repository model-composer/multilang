<?php namespace Model\Multilang;

use Model\Core\AbstractModelProvider;

class ModelProvider extends AbstractModelProvider
{
	public static function realign(): void
	{
		Ml::realign();
	}
}
