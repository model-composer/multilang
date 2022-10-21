<?php namespace Model\Multilang;

use Model\Db\AbstractDbProvider;
use Model\Db\DbConnection;

class DbProvider extends AbstractDbProvider
{
	/**
	 * @return array|\string[][]
	 */
	public static function getMigrationsPaths(): array
	{
		$config = Ml::getConfig();

		return $config['dictionary_storage'] === 'db' ? [
			[
				'path' => 'vendor/model/multilang/migrations',
			],
		] : [];
	}

	/**
	 * @param DbConnection $db
	 * @param string $table
	 * @param array $where
	 * @param array $options
	 * @return array
	 */
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

	/**
	 * @param DbConnection $db
	 * @param string $table
	 * @param array $row
	 * @param array $options
	 * @return array
	 */
	public static function alterSelectResult(DbConnection $db, string $table, array $row, array $options): array
	{
		$config = Ml::getConfig();
		$mlTables = Ml::getTables($db);

		if (!$config['fallback'] or !isset($mlTables[$table]) or !($options['multilang_fallback'] ?? true))
			return $row;

		$mlTable = $mlTables[$table];

		$tableModel = $db->getParser()->getTable($table);
		if (!isset($row[$tableModel->primary[0]]))
			return $row;

		if (self::checkIfValidForFallback($row, $mlTable))
			return $row;

		foreach (($options['joins'] ?? []) as $idx => $join) {
			if (isset($join['alias']) and in_array($join['alias'], ['lang', 'custom', 'custom_lang']))
				unset($options['joins'][$idx]);
		}

		foreach ($config['fallback'] as $l) {
			if ($options['lang'] === $l)
				continue;

			$mlRow = $db->select($table, $row[$tableModel->primary[0]], array_merge($options, [
				'lang' => $l,
				'multilang_fallback' => false,
			]));
			if ($mlRow and self::checkIfValidForFallback($mlRow, $mlTable))
				return $mlRow;
		}

		return $row;
	}

	/**
	 * @param array $row
	 * @param array $mlTable
	 * @return bool
	 */
	private static function checkIfValidForFallback(array $row, array $mlTable): bool
	{
		$atLeastOne = false;
		foreach ($mlTable['fields'] as $f) {
			if (isset($row[$f]) and !empty($row[$f])) {
				$atLeastOne = true;
				break;
			}
		}
		return $atLeastOne;
	}
}
