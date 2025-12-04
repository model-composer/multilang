<?php namespace Model\Multilang\Providers;

use Model\Config\AbstractConfigProvider;

class ConfigProvider extends AbstractConfigProvider
{
	public static function migrations(): array
	{
		return [
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
			[
				'version' => '0.2.26',
				'migration' => function (array $config, string $env) {
					$config['default'] = null;
					return $config;
				},
			],
			[
				'version' => '0.3.12',
				'migration' => function (array $config, string $env) {
					$config['cache_dictionary'] = 'model.multilang.dictionary';
					return $config;
				},
			],
			[
				'version' => '0.4.8',
				'migration' => function (array $config, string $env) {
					$config['force_default'] = false;
					return $config;
				},
			],
		];
	}
}
