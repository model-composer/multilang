<?php

use Phinx\Migration\AbstractMigration;

class ExtendedLength extends AbstractMigration
{
	public function change()
	{
		$this->table('model_dictionary')
			->changeColumn('value', 'text', ['null' => false])
			->update();
	}
}
