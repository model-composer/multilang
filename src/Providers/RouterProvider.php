<?php namespace Model\Multilang\Providers;

use Model\Config\Config;
use Model\Multilang\Ml;
use Model\Router\AbstractRouterProvider;

class RouterProvider extends AbstractRouterProvider
{
	public static function preMatchUrl(string $url): string
	{
		$config = Config::get('multilang');
		if ($config['type'] === 'url') {
			$parts = explode('/', trim($url, '/'));
			if (isset($parts[0]) and in_array($parts[0], $config['langs'])) {
				$lang = array_shift($parts);
				Ml::setLang($lang);
			}

			$url = implode('/', $parts);
		}

		return $url;
	}

	public static function postGenerateUrl(string $url, array $options): string
	{
		$config = Config::get('multilang');
		if ($config['type'] === 'url') {
			$lang = $options['lang'] ?? Ml::getLang();
			if (in_array($lang, $config['langs']) and $lang !== $config['default']) {
				$url = ltrim($url, '/');
				return $lang . ($url ? '/' . ltrim($url, '/') : '');
			}
		}

		return $url;
	}
}
