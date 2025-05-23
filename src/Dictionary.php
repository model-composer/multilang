<?php namespace Model\Multilang;

use Model\Cache\Cache;
use Model\Config\Config;
use Model\Db\DbConnection;

class Dictionary
{
	/** @var array|null Dictionary cache */
	private static ?array $dictionary = null;

	/**
	 * Gets a single word from the dictionary in the specified language
	 *
	 * @param string $word
	 * @param string|null $lang
	 * @return string
	 * @throws \Exception
	 */
	public static function get(string $word, ?string $lang = null): string
	{
		$config = Config::get('multilang');

		if ($lang === null)
			$lang = Ml::getLang();

		$word = explode('.', $word);
		if (count($word) > 2)
			throw new \Exception('There can\'t be more than one dot (.) character in dictionary word identifier');

		$dictionary = self::getFull();

		$word_arr = null;

		if (count($word) === 1) {
			foreach ($dictionary as $section) {
				if (isset($section['words'][$word[0]])) {
					$word_arr = $section['words'][$word[0]];
					break;
				}
			}
		} else {
			if (!isset($dictionary[$word[0]]))
				throw new \Exception('There is no dictionary section named "' . $word[0] . '"');

			if (isset($dictionary[$word[0]]['words'][$word[1]]))
				$word_arr = $dictionary[$word[0]]['words'][$word[1]];
		}

		if ($word_arr) {
			$possibleLangs = [
				$lang,
			];

			foreach ($config['fallback'] as $l) {
				if (!in_array($l, $possibleLangs))
					$possibleLangs[] = $l;
			}

			foreach ($possibleLangs as $l) {
				if ($word_arr[$l] ?? '')
					return $word_arr[$l];
			}
		}

		return '';
	}

	/**
	 * @param string $section
	 * @param string $word
	 * @param array $values
	 * @param string $acl
	 * @return void
	 */
	public static function set(string $section, string $word, array $values, string $acl = 'user', ?DbConnection $db = null): void
	{
		$config = Config::get('multilang');

		switch ($config['dictionary_storage']) {
			case 'db':
				$db ??= \Model\Db\Db::getConnection();

				$checkSection = $db->select('model_dictionary_sections', ['name' => $section]);
				if (!$checkSection) {
					$db->insert('model_dictionary_sections', [
						'name' => $section,
						'acl' => $acl,
					]);
				}

				foreach ($values as $lang => $value) {
					$checkWord = $db->select('model_dictionary', ['section' => $section, 'word' => $word, 'lang' => $lang]);
					if ($checkWord)
						$db->update('model_dictionary', $checkWord['id'], ['value' => $value]);
					else
						$db->insert('model_dictionary', ['section' => $section, 'word' => $word, 'lang' => $lang, 'value' => $value]);
				}
				break;

			case 'file':
				$dictionary = self::getFull();
				if (!isset($dictionary[$section])) {
					$dictionary[$section] = [
						'accessLevel' => $acl,
						'words' => [],
					];
				}

				$dictionary[$section]['words'][$word] = array_merge($dictionary[$section]['words'][$word] ?? [], $values);

				$filepath = self::getDictionaryFilePath($config);
				file_put_contents($filepath, "<?php\nreturn " . var_export($dictionary, true) . ";\n");
				break;
		}

		self::flushCache();

		if (class_exists('\\Model\\Events\\Events'))
			\Model\Events\Events::dispatch(new \Model\Multilang\Events\ChangedDictionary('set', $section, $word, $values));
	}

	/**
	 * @param string $section
	 * @param string $word
	 * @return void
	 */
	public static function delete(string $section, string $word): void
	{
		$config = Config::get('multilang');

		switch ($config['dictionary_storage']) {
			case 'db':
				$db = \Model\Db\Db::getConnection();
				$db->delete('model_dictionary', ['section' => $section, 'word' => $word]);
				break;

			case 'file':
				$dictionary = self::getFull();
				if (!isset($dictionary[$section]) or !isset($dictionary[$section]['words'][$word]))
					return;

				unset($dictionary[$section]['words'][$word]);
				$filepath = self::getDictionaryFilePath($config);
				file_put_contents($filepath, "<?php\nreturn " . var_export($dictionary, true) . ";\n");
				break;
		}

		self::flushCache();

		if (class_exists('\\Model\\Events\\Events'))
			\Model\Events\Events::dispatch(new \Model\Multilang\Events\ChangedDictionary('delete', $section, $word));
	}

	/**
	 * @return void
	 */
	public static function flushCache(): void
	{
		$cacheKey = self::getCacheKey();
		if ($cacheKey) {
			$cache = Cache::getCacheAdapter();
			$cache->deleteItem($cacheKey);
		}
		self::$dictionary = null;
	}

	/**
	 * Get cache key for dictionary
	 *
	 * @param array $options
	 * @return string|null
	 */
	private static function getCacheKey(array $options = []): ?string
	{
		$config = Config::get('multilang');

		if ($config['cache_dictionary']) {
			if ($config['dictionary_storage'] === 'db') {
				$db = $options['db'] ?? \Model\Db\Db::getConnection();
				$config['cache_dictionary'] .= '.' . $db->getName();
			}

			return $config['cache_dictionary'];
		}

		return null;
	}

	/**
	 * Get full dictionary from cache
	 *
	 * @param array $options
	 * @return array
	 * @throws \Exception
	 */
	public static function getFull(array $options = []): array
	{
		if (!($options['use_cache'] ?? true))
			return self::retrieveFull($options);

		if (self::$dictionary === null) {
			$cacheKey = self::getCacheKey($options);

			if ($cacheKey) {
				$cache = Cache::getCacheAdapter();

				self::$dictionary = $cache->get($cacheKey, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($options) {
					$item->expiresAfter(3600 * 24);
					return self::retrieveFull($options);
				});
			} else {
				self::$dictionary = self::retrieveFull($options);
			}
		}

		return self::$dictionary;
	}

	/**
	 * Builds full dictionary
	 *
	 * @param array $options
	 * @return array
	 * @throws \Exception
	 */
	private static function retrieveFull(array $options = []): array
	{
		$config = Config::get('multilang');

		return match ($config['dictionary_storage']) {
			'db' => self::retrieveFromDb($config, $options),
			'file' => self::retrieveFromFile($config, $options),
			default => throw new \Exception('Unknown dictionary storage type'),
		};
	}

	/**
	 * @param array $config
	 * @param array $options
	 * @return array
	 */
	private static function retrieveFromDb(array $config, array $options = []): array
	{
		$db = $options['db'] ?? \Model\Db\Db::getConnection();

		$dictionary = [];
		foreach ($db->selectAll('model_dictionary_sections') as $section) {
			$dictionary[$section['name']] = [
				'words' => [],
				'accessLevel' => $section['acl'],
			];
		}

		foreach ($db->selectAll('model_dictionary') as $word) {
			if (!isset($dictionary[$word['section']]))
				continue;

			$dictionary[$word['section']]['words'][$word['word']][$word['lang']] = $word['value'];
		}

		// If we recently switched from file to db storage, we have to populate the db
		if (empty($dictionary) and file_exists(self::getDictionaryFilePath($config))) {
			$dictionary_file = self::retrieveFromFile($config, $options);

			foreach ($dictionary_file as $sectionName => $section) {
				$db->insert('model_dictionary_sections', [
					'name' => $sectionName,
					'acl' => $section['accessLevel'],
				]);

				foreach ($section['words'] as $wordKey => $langs) {
					foreach ($langs as $lang => $word) {
						$db->insert('model_dictionary', [
							'section' => $sectionName,
							'word' => $wordKey,
							'lang' => $lang,
							'value' => $word,
						]);
					}
				}
			}

			unlink(self::getDictionaryFilePath($config));
			return self::retrieveFromDb($config, $options);
		}

		return $dictionary;
	}

	/**
	 * @param array $config
	 * @param array $options
	 * @return array
	 */
	private static function retrieveFromFile(array $config, array $options = []): array
	{
		$filepath = self::getDictionaryFilePath($config);
		return require $filepath;
	}

	/**
	 * @param array $config
	 * @return string
	 */
	private static function getDictionaryFilePath(array $config): string
	{
		$filepath = $config['filepath'] ?? 'config/multilang_dictionary.php';
		return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . $filepath;
	}

	/**
	 * @param string $section
	 * @return bool
	 */
	public static function isUserAuthorized(string $section): bool
	{
		$dictionary = self::getFull(['use_cache' => false]);

		if (!isset($dictionary[$section]))
			return false;

		return ($dictionary[$section]['accessLevel'] === 'user' or (defined('DEBUG_MODE') and DEBUG_MODE));
	}
}
