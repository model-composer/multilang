<?php namespace Model\Multilang;

use Model\Config\Config;

class Ml
{
	private static string $lang;

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
		]);
	}
}
