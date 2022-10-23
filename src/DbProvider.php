<?php namespace Model\Multilang;

use Model\Db\AbstractDbProvider;
use Model\Db\DbConnection;
use Model\DbParser\Table;

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
	 * Add a join for the main table and for every other joined table, if they are marked as multilanguage
	 *
	 * @param DbConnection $db
	 * @param string $table
	 * @param array $where
	 * @param array $options
	 * @return array
	 */
	public static function alterSelect(DbConnection $db, string $table, array $where, array $options): array
	{
		$options['joins'] = $db->getBuilder()->normalizeJoins($options['alias'] ?? $table, $options['joins'] ?? []);

		$originalJoins = $options['joins'];
		$mainTableJoin = self::getJoinFor($db, $table, $options, $options['alias'] ?? $table);
		if ($mainTableJoin)
			$options['joins'][] = $mainTableJoin;

		foreach ($originalJoins as $join) {
			$langJoin = self::getJoinFor($db, $join['table'], $options, $join['alias'] ?? $join['table']);
			if ($langJoin)
				$options['joins'][] = $langJoin;
		}

		return [$where, $options];
	}

	private static function getJoinFor(DbConnection $db, string $table, array $options, string $alias): ?array
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

			return [
				'type' => 'LEFT',
				'table' => $mlTableName,
				'alias' => $alias . '_lang',
				'on' => [
					$tableModel->primary[0] => $mlTableConfig['parent_field'],
					$db->parseColumn($mlTableConfig['lang_field'], $alias . '_lang') . ' LIKE ' . $db->parseValue($options['lang'] ?? Ml::getLang()),
				],
				'fields' => $mlFields,
			];
		}

		return null;
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

		// Remove all previously set multilang joins
		$newJoins = [];
		foreach (($options['joins'] ?? []) as $join) {
			$isMl = false;

			// Look for the main table
			if (isset($join['alias']) and $join['alias'] === ($options['alias'] ?? $table) . '_lang')
				$isMl = true;

			// Look for other joined tables
			foreach (($options['joins'] ?? []) as $subjoin) {
				if (isset($join['alias']) and $join['alias'] === ($subjoin['alias'] ?? $subjoin['table']) . '_lang') {
					$isMl = true;
					break;
				}
			}

			if (!$isMl)
				$newJoins[] = $join;
		}
		$options['joins'] = $newJoins;

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

	/**
	 * @param DbConnection $db
	 * @param string $table
	 * @param Table $tableModel
	 * @return Table
	 */
	public static function alterTableModel(DbConnection $db, string $table, Table $tableModel): Table
	{
		$mlTables = \Model\Multilang\Ml::getTablesConfig($db);
		foreach ($mlTables as $mlTable => $mlTableOptions) {
			if ($mlTable . $mlTableOptions['table_suffix'] === $table and array_key_exists($mlTable, $db->getConfig()['linked_tables'])) {
				$customTableModel = $db->getParser()->getTable($db->getConfig()['linked_tables'][$mlTable] . $mlTableOptions['table_suffix']);
				$tableModel->loadColumns($customTableModel->columns, false);
				break;
			}
		}

		return $tableModel;
	}
}
