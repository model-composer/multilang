<?php

use Phinx\Migration\AbstractMigration;

class Dictionary extends AbstractMigration
{
	public function change()
	{
		$this->table('model_dictionary_sections')
			->addColumn('name', 'string')
			->addColumn('acl', 'string')
			->create();

		$this->table('model_dictionary')
			->addColumn('section', 'string')
			->addColumn('word', 'string')
			->addColumn('lang', 'string')
			->addColumn('value', 'string')
			->create();
	}
}
