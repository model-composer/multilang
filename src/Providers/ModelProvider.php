<?php namespace Model\Multilang\Providers;

use Model\Core\AbstractModelProvider;
use Model\Multilang\Ml;

class ModelProvider extends AbstractModelProvider
{
	public static function realign(): void
	{
		Ml::realign();
	}
}
