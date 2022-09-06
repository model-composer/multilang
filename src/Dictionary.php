<?php namespace Model\Multilang;

use Model\Cache\Cache;
use Model\Db\Db;

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
		$config = Ml::getConfig();

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
	public static function set(string $section, string $word, array $values, string $acl = 'user'): void
	{
		$config = Ml::getConfig();

		switch ($config['dictionary_storage']) {
			case 'db':
				$db = Db::getConnection();
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
	}

	/**
	 * @param string $section
	 * @param string $word
	 * @return void
	 */
	public static function delete(string $section, string $word): void
	{
		$config = Ml::getConfig();

		switch ($config['dictionary_storage']) {
			case 'db':
				$db = Db::getConnection();
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
	}

	/**
	 * @return void
	 */
	public static function flushCache(): void
	{
		$cache = Cache::getCacheAdapter();
		$cache->deleteItem('model.multilang.dictionary');
		self::$dictionary = null;
	}

	/**
	 * Get full dictionary from cache
	 *
	 * @return array
	 * @throws \Exception
	 */
	public static function getFull(): array
	{
		if (self::$dictionary === null) {
			$cache = Cache::getCacheAdapter();

			self::$dictionary = $cache->get('model.multilang.dictionary', function (\Symfony\Contracts\Cache\ItemInterface $item) {
				Cache::registerInvalidation('keys', ['model.multilang.dictionary']);
				return self::retrieveFull();
			});
		}

		return self::$dictionary;
	}

	/**
	 * Builds full dictionary
	 *
	 * @return array
	 * @throws \Exception
	 */
	private static function retrieveFull(): array
	{
		$config = Ml::getConfig();

		return match ($config['dictionary_storage']) {
			'db' => self::retrieveFromDb($config),
			'file' => self::retrieveFromFile($config),
			default => throw new \Exception('Unknown dictionary storage type'),
		};
	}

	/**
	 * @param array $config
	 * @return array
	 */
	private static function retrieveFromDb(array $config): array
	{
		$db = Db::getConnection();

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
			$dictionary_file = self::retrieveFromFile($config);

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

			return self::retrieveFromDb($config);
		}

		return $dictionary;
	}

	/**
	 * @param array $config
	 * @return array
	 */
	private static function retrieveFromFile(array $config): array
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
		$dictionary = self::getFull();

		if (!isset($dictionary[$section]))
			return false;

		return ($dictionary[$section]['accessLevel'] === 'user' or (defined('DEBUG_MODE') and DEBUG_MODE));
	}
}
