<?php
class PositionableElementFixture extends CakeTestFixture {

/**
 * Fields
 *
 * @var array
 */
	public $fields = array(
		'id' => array('type' => 'string', 'null' => false, 'default' => NULL, 'length' => 36, 'key' => 'primary'),
		'foreign_model_id' => array('type' => 'string', 'null' => false, 'default' => NULL, 'length' => 36, 'key' => 'index'),
		'position' => array('type' => 'integer', 'null' => false, 'default' => NULL, 'length' => 5),
		'indexes' => array('PRIMARY' => array('column' => 'id', 'unique' => 1),),
	);

/**
 * Records
 *
 * @var array
 */
	public $records = array(
		array(
			'id' => 'positionable-element-1',
			'foreign_model_id' => 'foreign-model-1',
			'position' => 1,
		),
		array(
			'id' => 'positionable-element-2',
			'foreign_model_id' => 'foreign-model-1',
			'position' => 2,
		),
	);

}