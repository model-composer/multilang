<?php namespace Model\Multilang;

use Model\ProvidersFinder\AbstractProvider;

abstract class AbstractMultilangProvider extends AbstractProvider
{
	public static function dictionary(): array
	{
		return [];
	}

	public static function tables(\Model\Db\DbConnection $db): array
	{
		return [];
	}
}
