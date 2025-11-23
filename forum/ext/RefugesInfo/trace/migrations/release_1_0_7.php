<?php
namespace RefugesInfo\trace\migrations;

class release_1_0_7 extends \phpbb\db\migration\migration
{
	static public function depends_on()
	{
		return array('\RefugesInfo\trace\migrations\release_1_0_6');
	}

	public function update_schema()
	{
		include __DIR__.'/config.php';
		$table_name = array_key_first($config['tables']);

		return [
			'add_columns' => [
				$table_name => [
					'creator_id' => ['CHAR:32', NULL],
					'creator_name' => ['CHAR:128', NULL],
					'user_moderator' => ['UINT', NULL],
				],
			],
		];
	}
}
