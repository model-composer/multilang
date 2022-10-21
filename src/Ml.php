<?php namespace Model\Multilang;

use Model\Cache\Cache;
use Model\Config\Config;
use Model\ProvidersFinder\Providers;

class Ml
{
	private static string $lang;
	private static array $tablesCache = [];

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
		$config = self::getConfig();
		if (!in_array($lang, $config['langs']))
			throw new \Exception('Unsupported lang');

		self::$lang = $lang;
	}

	/**
	 * @return void
	 */
	private static function setDefaultLang(): void
	{
		$browserLang = null;
		if (isset($_SERVER, $_SERVER['HTTP_ACCEPT_LANGUAGE']))
			$browserLang = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));

		$config = self::getConfig();

		if ($browserLang and in_array($browserLang, $config['langs']))
			self::setLang($browserLang);
		else
			self::setLang($config['default']);
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
			self::$tablesCache[$db->getName()] = $cache->get('model.multilang.tables.' . $db->getName(), function (\Symfony\Contracts\Cache\ItemInterface $item) use ($db) {
				Cache::registerInvalidation('keys', ['model.multilang.tables.' . $db->getName()]);

				$config = self::getConfig();

				$tables = [];
				foreach (($config['tables'][$db->getName()] ?? []) as $table => $tableData) {
					if (is_numeric($table) and is_string($tableData)) {
						$table = $tableData;
						$tableData = [];
					}
					if (!isset($tableData['fields']))
						$tableData = ['fields' => $tableData];

					$tableData = array_merge([
						'parent_field' => 'parent',
						'lang_field' => 'lang',
						'table_suffix' => '_texts',
						'fields' => [],
					], $tableData);

					if (count($tableData['fields']) === 0) {
						try {
							$tableModel = $db->getParser()->getTable($table . $tableData['table_suffix']);
							foreach ($tableModel->columns as $columnName => $column) {
								if (in_array($columnName, $tableModel->primary) or $columnName === $tableData['parent_field'] or $columnName === $tableData['lang_field'])
									continue;
								$tableData['fields'][] = $columnName;
							}
						} catch (\Exception $e) {
						}
					}

					$tables[$table] = $tableData;
				}

				return $tables;
			});
		}

		return self::$tablesCache[$db->getName()];
	}

	/**
	 * Config retriever
	 *
	 * @return array
	 * @throws \Exception
	 */
	public static function getConfig(): array
	{
		return Config::get('multilang', [
			[
				'version' => '0.1.0',
				'migration' => function (array $config, string $env) {
					if ($config) // Already existing
						return $config;

					if (defined('INCLUDE_PATH') and file_exists(INCLUDE_PATH . 'app/config/Multilang/config.php')) {
						// ModEl 3 migration
						require(INCLUDE_PATH . 'app/config/Multilang/config.php');
						if (!isset($config['fallback']))
							$config['fallback'] = ['en'];
						$config['dictionary_storage'] = 'file';

						return $config;
					}

					return [
						'langs' => ['it', 'en'],
						'default' => 'it',
						'fallback' => ['en'],
						'type' => 'url',
						'hide-dictionary' => false, // TODO: serve ancora in ModEl 4?
						'tables' => [],
						'dictionary_storage' => 'db',
					];
				},
			],
			[
				'version' => '0.2.0',
				'migration' => function (array $config, string $env) {
					$config['tables'] = ['primary' => $config['tables']];
					return $config;
				},
			],
		]);
	}
}
