<?php namespace Model\Multilang;

use Model\Config\Config;
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
		$config = Config::get('multilang');

		return $config['dictionary_storage'] === 'db' ? [
			[
				'path' => 'vendor/model/multilang/migrations',
			],
		] : [];
	}

	public static function alterInsert(DbConnection $db, array $queries): array
	{
		$mlTables = Ml::getTables($db);

		$new = [];
		foreach ($queries as $query) {
			if (isset($mlTables[$query['table']])) {
				$mlTableConfig = $mlTables[$query['table']];

				$mlTableName = $query['table'] . $mlTableConfig['table_suffix'];
				$mlTableModel = $db->getTable($mlTableName);

				$mlFields = [];
				foreach ($mlTableConfig['fields'] as $f) {
					if (isset($mlTableModel->columns[$f]) and $mlTableModel->columns[$f]['real'])
						$mlFields[] = $f;
				}

				foreach ($query['rows'] as $row) {
					$mainRow = [];

					$mlRows = [];
					foreach (Ml::getLangs() as $l) {
						$mlRows[$l] = [
							$mlTableConfig['lang_field'] => $l,
						];
					}

					foreach ($row as $k => $v) {
						if (in_array($k, $mlFields)) {
							if (is_array($v)) {
								foreach ($v as $l => $vl) {
									if (in_array($l, Ml::getLangs()))
										$mlRows[$l][$k] = $vl;
								}
							} else {
								foreach (Ml::getLangs() as $l)
									$mlRows[$l][$k] = $v;
							}
						} else {
							$mainRow[$k] = $v;
						}
					}

					$mainRowIdx = count($new);
					$new[] = [
						'table' => $query['table'],
						'rows' => [$mainRow],
						'options' => $query['options'],
					];

					$mlInsert = [
						'table' => $mlTableName,
						'rows' => [],
						'options' => array_merge($query['options'], [
							'replace_ids' => [
								[
									'from' => $mainRowIdx,
									'field' => $mlTableConfig['parent_field'],
								],
							],
						]),
					];

					foreach ($mlRows as $mlRow)
						$mlInsert['rows'][] = $mlRow;

					$new[] = $mlInsert;
				}
			} else {
				$new[] = $query;
			}
		}

		return $new;
	}

	public static function alterUpdate(DbConnection $db, array $queries): array
	{
		// TODO
		return $queries;
	}

	/**
	 * Add a join for the main table and for every other joined table, if they are marked as multilanguage
	 *
	 * @param DbConnection $db
	 * @param string $table
	 * @param array|int $where
	 * @param array $options
	 * @return array
	 */
	public static function alterSelect(DbConnection $db, string $table, array|int $where, array $options): array
	{
		if (!isset($options['lang']))
			$options['lang'] = Ml::getLang();

		$options['joins'] = $db->getBuilder()->normalizeJoins($options['alias'] ?? $table, $options['joins'] ?? []);

		$originalJoins = $options['joins'];
		$mainTableJoin = self::getJoinFor($db, $table, $options, $options['alias'] ?? $table);
		if ($mainTableJoin) {
			$alreadyExisting = $table === $mainTableJoin['table'];
			foreach ($originalJoins as $origJoin) {
				if ($origJoin['table'] === $mainTableJoin['table'])
					$alreadyExisting = true;
			}
			if (!$alreadyExisting)
				$options['joins'][] = $mainTableJoin;
		}

		foreach ($originalJoins as $join) {
			$langJoin = self::getJoinFor($db, $join['table'], $options, $join['alias'] ?? $join['table']);
			if ($langJoin) {
				$alreadyExisting = $table === $langJoin['table'];
				foreach ($originalJoins as $origJoin) {
					if ($origJoin['table'] === $langJoin['table'])
						$alreadyExisting = true;
				}
				if (!$alreadyExisting)
					$options['joins'][] = $langJoin;
			}
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
					$db->parseColumn($mlTableConfig['lang_field'], $alias . '_lang') . ' LIKE ' . $db->parseValue($options['lang']),
				],
				'fields' => $mlFields,
				'injected' => true,
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
		$config = Config::get('multilang');
		$mlTables = Ml::getTables($db);

		if (!$config['fallback'] or !isset($mlTables[$table]) or !($options['multilang_fallback'] ?? true))
			return $row;

		$mlTable = $mlTables[$table];

		$tableModel = $db->getParser()->getTable($table);
		if (!isset($row[$tableModel->primary[0]]))
			return $row;

		if (self::checkIfValidForFallback($row, $mlTable))
			return $row;

		// Remove all previously injected joins
		$newJoins = [];
		foreach (($options['joins'] ?? []) as $join) {
			if (!($join['injected'] ?? false))
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
		if (class_exists('\\Model\\LinkedTables\\LinkedTables')) {
			$linkedTables = \Model\LinkedTables\LinkedTables::getTables($db);

			$mlTables = \Model\Multilang\Ml::getTablesConfig($db);
			foreach ($mlTables as $mlTable => $mlTableOptions) {
				if ($mlTable . $mlTableOptions['table_suffix'] === $table and array_key_exists($mlTable, $linkedTables)) {
					$customTableModel = $db->getParser()->getTable($linkedTables[$mlTable] . $mlTableOptions['table_suffix']);
					$tableModel->loadColumns($customTableModel->columns, false);
					break;
				}
			}
		}

		return $tableModel;
	}
}
