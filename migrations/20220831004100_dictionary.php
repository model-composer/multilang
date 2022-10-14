<?php

use Phinx\Migration\AbstractMigration;

class Dictionary extends AbstractMigration
{
	public function change()
	{
		$this->table('model_dictionary_sections')
			->addColumn('name', 'string', ['null' => false])
			->addColumn('acl', 'enum', ['null' => false, 'values' => ['user', 'root']])
			->addIndex('name', ['unique' => true])
			->create();

		$this->table('model_dictionary')
			->addColumn('section', 'string', ['null' => false])
			->addColumn('word', 'string', ['null' => false])
			->addColumn('lang', 'string', ['null' => false])
			->addColumn('value', 'string', ['null' => false])
			->create();
	}
}
