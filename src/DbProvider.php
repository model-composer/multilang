<?php namespace Model\Multilang;

use Model\Db\DbProviderInterface;

class DbProvider implements DbProviderInterface
{
	public static function getMigrationsPaths(): array
	{
		return [
			'vendor/model/multilang/migrations',
		];
	}
}
