<?php namespace Model\Multilang;

abstract class AbstractMultilangProvider
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
