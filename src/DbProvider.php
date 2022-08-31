<?php namespace Model\Multilang;

use Model\Db\DbProviderInterface;

class DbProvider implements DbProviderInterface
{
	public static function getMigrationsPaths(): array
	{
		return [
			[
				'path' => 'vendor/model/multilang/migrations',
			],
		];
	}
}
