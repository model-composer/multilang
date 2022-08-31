<?php namespace Model\Multilang;

use Model\Db\DbProviderInterface;

class DbProvider implements DbProviderInterface
{
	public static function getMigrationsPaths(): array
	{
		$config = Ml::getConfig();

		return $config['dictionary_storage'] === 'db' ? [
			[
				'path' => 'vendor/model/multilang/migrations',
			],
		] : [];
	}
}
