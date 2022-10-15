<?php namespace Model\Multilang;

use Model\Db\AbstractDbProvider;

class DbProvider extends AbstractDbProvider
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
