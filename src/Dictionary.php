<?php namespace Model\Multilang;

use Model\Cache\Cache;

class Dictionary
{
	/** @var array Dictionary cache */
	private static array $dictionary;

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
	 * Get full dictionary from cache
	 *
	 * @return array
	 * @throws \Exception
	 */
	public static function getFull(): array
	{
		if (!isset(self::$dictionary)) {
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
			'db' => self::retrieveFromDb(),
			'file' => self::retrieveFromFile($config),
			default => throw new \Exception('Unknown dictionary storage type'),
		};
	}

	/**
	 * @return array
	 */
	private static function retrieveFromDb(): array
	{
		// TODO
		return [];
	}

	/**
	 * @param array $config
	 * @return array
	 */
	private static function retrieveFromFile(array $config): array
	{
		$filepath = $config['filepath'] ?? 'config/multilang_dictionary.php';
		$filepath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR . $filepath;
		return require $filepath;
	}
}
