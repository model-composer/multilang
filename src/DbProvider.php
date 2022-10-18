<?php namespace Model\Multilang;

use Model\Db\AbstractDbProvider;
use Model\Db\DbConnection;

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

	public static function alterSelect(DbConnection $db, string $table, array $where, array $options): array
	{
		$mlTables = Ml::getTables($db);

		if (isset($mlTables[$table])) {
			$mlTableConfig = $mlTables[$table];

			$tableModel = $db->getParser()->getTable($table);

			$mlTableName = $table . $mlTableConfig['table_suffix'];
			$mlTableModel = $db->getParser()->getTable($mlTableName);

			$mlFields = [];
			foreach ($mlTableConfig['fields'] as $f) {
				if (isset($mlTableModel->columns[$f]) and $mlTableModel->columns[$f]['real'])
					$mlFields[] = $f;
			}

			if (!isset($options['joins']))
				$options['joins'] = [];

			$options['joins'][] = [
				'type' => 'LEFT',
				'table' => $mlTableName,
				'alias' => 'lang',
				'on' => [
					$tableModel->primary[0] => $mlTableConfig['parent_field'],
					$db->parseColumn($mlTableConfig['lang_field'], 'lang') . ' LIKE ' . $db->parseValue($options['lang'] ?? Ml::getLang()),
				],
				'fields' => $mlFields,
			];
		}

		return [$where, $options];
	}
}
