<?php namespace Model\Multilang;

use Model\Db\MultilangProviderInterface;

class MultilangProvider implements MultilangProviderInterface
{
	public static function dictionary(): array
	{
		return [
			'multilang' => [
				'accessLevel' => 'root',
				'words' => [
					'dictionary' => [
						'it' => 'Dizionario',
						'en' => 'Dictionary',
					],
					'label' => [
						'it' => 'Label',
						'en' => 'Label',
					],
					'insert' => [
						'it' => 'Inserisci',
						'en' => 'Insert',
					],
					'delete_confirmation' => [
						'it' => 'Sicuro di voler eliminare?',
						'en' => 'Are you sure?',
					],
					'new' => [
						'it' => 'Nuovo termine',
						'en' => 'New word',
					],
					'admin-lang' => [
						'it' => 'Lingua pannello:',
						'en' => 'Admin language:',
					],
				],
			],
		];
	}
}
