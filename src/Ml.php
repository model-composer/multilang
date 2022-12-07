<?php namespace Model\Multilang;

use Model\Cache\Cache;
use Model\Config\Config;
use Model\ProvidersFinder\Providers;

class Ml
{
	private static string $lang;
	private static array $tablesCache = [];

	/**
	 * @return array
	 * @throws \Exception
	 */
	public static function getLangs(): array
	{
		$config = Config::get('multilang');
		return $config['langs'];
	}

	/**
	 * @return string
	 */
	public static function getLang(): string
	{
		if (!isset(self::$lang))
			self::setDefaultLang();

		return self::$lang;
	}

	/**
	 * @param string $lang
	 */
	public static function setLang(string $lang): void
	{
		if (!in_array($lang, self::getLangs()))
			throw new \Exception('Unsupported lang');

		self::$lang = $lang;
	}

	/**
	 * @return void
	 */
	private static function setDefaultLang(): void
	{
		$config = Config::get('multilang');
		if ($config['default']) {
			self::setLang($config['default']);
			return;
		}

		$browserLang = null;
		if (isset($_SERVER, $_SERVER['HTTP_ACCEPT_LANGUAGE']))
			$browserLang = mb_strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));

		$langs = self::getLangs();
		if ($browserLang and in_array($browserLang, $langs))
			self::setLang($browserLang);
		else
			throw new \Exception('Unknown default lang');
	}

	/**
	 * @return void
	 */
	public static function realign(): void
	{
		if (defined('INCLUDE_PATH')) {
			// ModEl 3 migration
			$oldDictionaryFile = INCLUDE_PATH . 'app/config/Multilang/dictionary.php';
			$newDictionaryFile = INCLUDE_PATH . 'config/multilang_dictionary.php';
			if (file_exists($oldDictionaryFile) and !file_exists($newDictionaryFile)) {
				file_put_contents($oldDictionaryFile, str_replace('$this->dictionary =', 'return', file_get_contents($oldDictionaryFile)));
				rename($oldDictionaryFile, $newDictionaryFile);
			}
		}

		$dictionary = Dictionary::getFull();

		$packagesWithProvider = Providers::find('MultilangProvider');
		foreach ($packagesWithProvider as $package) {
			$packageDictionary = $package['provider']::dictionary();
			foreach ($packageDictionary as $sectionName => $section) {
				foreach ($section['words'] as $word => $values) {
					if (!isset($dictionary[$sectionName]['words'][$word]))
						Dictionary::set($sectionName, $word, $values, $section['accessLevel']);
				}
			}
		}
	}

	/**
	 * @param \Model\Db\DbConnection $db
	 * @return array
	 * @throws \Exception
	 */
	public static function getTables(\Model\Db\DbConnection $db): array
	{
		if (!isset(self::$tablesCache[$db->getName()])) {
			$cache = Cache::getCacheAdapter();
			self::$tablesCache[$db->getName()] = $cache->get('model.multilang.tablesconfig.' . $db->getName(), function (\Symfony\Contracts\Cache\ItemInterface $item) use ($db) {
				Cache::registerInvalidation('keys', ['model.multilang.tablesconfig.' . $db->getName()]);
				$item->expiresAfter(3600 * 24);

				$tables = self::getTablesConfig($db);

				foreach ($tables as $table => &$tableData) {
					if (count($tableData['fields']) === 0) {
						try {
							$tableModel = $db->getTable($table . $tableData['table_suffix']);
							foreach ($tableModel->columns as $columnName => $column) {
								if (in_array($columnName, $tableModel->primary) or $columnName === $tableData['parent_field'] or $columnName === $tableData['lang_field'])
									continue;
								$tableData['fields'][] = $columnName;
							}
						} catch (\Exception $e) {
						}
					}
				}

				return $tables;
			});
		}

		return self::$tablesCache[$db->getName()];
	}

	/**
	 * @param \Model\Db\DbConnection $db
	 * @return array
	 */
	public static function getTablesConfig(\Model\Db\DbConnection $db): array
	{
		$config = Config::get('multilang');

		$tablesFromConfig = $config['tables'][$db->getName()] ?? [];
		$packagesWithProvider = Providers::find('MultilangProvider');
		foreach ($packagesWithProvider as $package)
			$tablesFromConfig = array_merge($tablesFromConfig, $package['provider']::tables($db));

		$tables = [];
		foreach ($tablesFromConfig as $table => $tableData) {
			if (is_numeric($table) and is_string($tableData)) {
				$table = $tableData;
				$tableData = [];
			}
			if (!isset($tableData['fields']))
				$tableData = ['fields' => $tableData];

			$tables[$table] = array_merge([
				'parent_field' => 'parent',
				'lang_field' => 'lang',
				'table_suffix' => '_texts',
				'fields' => [],
			], $tableData);
		}

		return $tables;
	}

	/**
	 * @param string $table
	 * @return string|null
	 */
	public static function getTableFor(\Model\Db\DbConnection $db, string $table): ?string
	{
		$tables = self::getTables($db);
		return array_key_exists($table, $tables) ? $table . $tables[$table]['table_suffix'] : null;
	}

	/**
	 * @param string $table
	 * @return array|null
	 */
	public static function getTableOptionsFor(\Model\Db\DbConnection $db, string $table): ?array
	{
		$tables = self::getTables($db);
		return $tables[$table] ?? null;
	}
}
