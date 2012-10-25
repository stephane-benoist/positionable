<?php
/**
 * Class allowing to deal with positioned element in a (potentially differant) model
 *
 * Elements has to have following fields :
 *	- foreign key given in settings;
 *  - 'position'.
 */
class PositionableBehavior extends ModelBehavior {

/**
 * Contains the models acting as positionable for each foreign model
 *
 * @var array List of all models acting as positionable indexed by foreign model
 */
	protected $_Models = array();

/**
 * Mandatory fields for using behavior
 *
 * @var array Mandatory fields
 */
	protected $mandatory = array('position');

/**
 * Settings
 */
	public $settings = array();

/**
 * Ephemere variable to pass data between callbacks.
 *
 * @var array
 */
	private $__save = array();

/**
 * Initiate validation rules on position.
 *
 * @param Model $Model instance of model
 * @return void
 */
	public function setup(&$Model, $settings = array()) {
		if ($this->_checkSettings($settings)) {
			$mandatory = $this->mandatory;
			$mandatory[] = $settings['foreignKey'];
			$forgottenFields = array_diff(
				$mandatory,
				array_intersect($mandatory, array_keys($Model->schema()))
			);
			if (!empty($forgottenFields)) {
				trigger_error(__d(
					'positionable',
					'The following fields are missing: %s',
					implode(', ', $forgottenFields)
				));
			}

			if (empty($this->settings[$Model->alias])) {
				$this->_addValidationRules($Model);
			}

			$this->settings[$Model->alias] = $settings;
		}
	}

/**
 * Validation rule - checks if the data passed is a positive number.
 *
 * @param Model $Model Model using the behavior
 * @param array $check Data to check
 * @return boolean True if the data is a positive number, false otherwise.
 */
	public function isNatural(&$Model, $check) {
		$value = array_shift(array_values($check));
		if ($value === strval(intval($value))) {
			$value = intval($value);
		}
		return is_int($value) && $value >= 0;
	}

/**
 * Validation rule - checks if the position is not already set for this truck.
 *
 * @param Model $Model Model using the behavior
 * @param array $check Data to check
 * @return boolean True if position is not already set, false otherwise
 */
	public function uniquePerForeignKey(&$Model, $check) {
		$foreignKey = $this->settings[$Model->alias]['foreignKey'];
		$matches = 0;

		if (isset($Model->data[$Model->alias][$foreignKey])) {
			$models = $this->_getPositionedModels($Model);

			foreach($models as $_BehaviorModel) {
				$conditions = array(
					$_BehaviorModel->alias . '.' .  $foreignKey => $Model->data[$Model->alias][$foreignKey],
					$_BehaviorModel->alias . '.' .  'position' => $Model->data[$Model->alias]['position'],
				);
				$aliasCondition = $conditions;
				if (!empty($Model->data[$Model->alias]['id'])) {
					$aliasCondition['NOT'] = array($Model->alias . '.id' => $Model->data[$Model->alias]['id']);
				}
				if ($_BehaviorModel->alias == $Model->alias) {
					$matches += $_BehaviorModel->find('count', array('conditions' => $aliasCondition));
				} else {
					$matches += $_BehaviorModel->find('count', array('conditions' => $conditions));
				}
			}
		}

		return $matches === 0;
	}

/**
 * Change the position of an element.
 *
 * @TODO Add transaction operation to avoid DB integrity fail.
 * @throws NotFoundException if the entry is not found
 * @param mixed $elementId The order id.
 * @param int $to The new position.
 * @param Model $Model Model using the behavior
 * @return boolean Success of the move
 */
	public function move(&$Model, $elementId, $to) {
		$models = $this->_Models = $this->_getPositionedModels($Model);
		$element = $Model->find('first', array(
			'conditions' => array($Model->alias . '.id' => $elementId),
			'contain' => array()
		));

		if (empty($element)) {
			throw new NotFoundException(__d('positionable', 'Invalid Element', true));
		}

		$from = $element[$Model->alias]['position'];
		$element[$Model->alias]['position'] = $to;

		$success = true;
		foreach ($models as $_BehaviorModel) {
			$conditions = array();
			$foreignKey = $this->settings[$Model->alias]['foreignKey'];
			$conditions[$_BehaviorModel->alias . '.' . $foreignKey] = $element[$Model->alias][$foreignKey];

			if($from < $to) {
				$conditions[$_BehaviorModel->alias . '.position BETWEEN ? AND ? '] = array($from + 1, $to);
				$success = $success && $_BehaviorModel->updateAll(
					array(
						$_BehaviorModel->alias . '.position' => $_BehaviorModel->alias . '.position - 1'
					),
					$conditions
				);
			} else {
				$conditions[$_BehaviorModel->alias . '.position BETWEEN ? AND ? '] = array($to, $from - 1);
				$success = $success && $_BehaviorModel->updateAll(
					array(
						$_BehaviorModel->alias . '.position' => $_BehaviorModel->alias . '.position + 1'
					),
					$conditions
				);
			}
		}

		$element[$Model->alias]['position'] = $to;
		$success = $success && $Model->save(
			$element,
			array('validate' => false, 'callbacks' => false),
			array('position')
		);
		return $success;
	}

/**
 * beforeSave is called before a model is saved.  Returning false from a beforeSave callback
 * will abort the save operation.
 *
 * Ensure that if data are really modificated by beforeValidate even when call through saveAssociated
 *
 * @param Model $model Model using this behavior
 * @return mixed False if the operation should abort. Any other result will continue.
 */
	public function beforeSave(&$Model) {
		$key = $this->settings[$Model->alias]['foreignKey'];
		$association = $this->settings[$Model->alias]['model'];
		if (!isset($Model->data[$Model->alias])) {
			$Model->data[$Model->alias] = $Model->data;
		}

		//@Todo test me in the case of empty($Model->{$association})
		if (empty($Model->data[$Model->alias][$key]) && !empty($Model->{$association})) {
			if (isset($Model->data[$Model->alias])) {
				$Model->data[$Model->alias][$key] = $Model->{$association}->id;
			} else {
				$Model->data = array_merge(array($key => $Model->{$association}->id), $Model->data, array($key => $Model->{$association}->id));
			}
		}

		if (empty($Model->data[$Model->alias]['position'])) {
			$maxPosition = $this->_getMaxPosition($Model);
			$data = $Model->data;
			if (!empty($Model->data[$Model->alias][$Model->primaryKey])) {
				$this->move($Model, $Model->data[$Model->alias][$Model->primaryKey], $maxPosition);
			}
			$data[$Model->alias]['position'] = $maxPosition;
			$Model->data = $data;
		}

		return parent::beforeSave($Model);
	}

/**
 * Called before every deletion operation.
 *	- Save the position of the deleted element in __save
 *
 * @param Model $Model Model using the behavior
 * @param boolean $cascade If true records that depend on this record will also be deleted
 * @return boolean True if the operation should continue, false if it should abort
 */
	public function beforeDelete(&$Model, $cascade = true) {
		$this->__save[$Model->alias] = $Model->read();
		return true;
	}

/**
 * Called after every deletion operation.
 *	- Update the position in trucks
 *
 * @param Model $Model Model using the behavior
 * @return void.
 */
	public function afterDelete(&$Model) {
		$models = $this->_getPositionedModels($Model);
		$foreignKey = $this->settings[$Model->alias]['foreignKey'];
		foreach ($models as $_BehaviorModel) {
			$_BehaviorModel->updateAll(
				array($_BehaviorModel->alias . '.position' => '(' . $_BehaviorModel->alias . '.position -1)'),
				array(
					$_BehaviorModel->alias . '.' . $foreignKey =>
						$this->__save[$Model->alias][$Model->alias][$foreignKey],
					$_BehaviorModel->alias . '.position >' =>
						$this->__save[$Model->alias][$Model->alias]['position']
				)
			);
		}
		unset($this->__save[$Model->alias]);
		parent::afterDelete(&$Model);
	}

/**
 * Sort an array of truck positioned element by position.
 * Modify the array.
 *
 * @param Model $Model Model using the behavior
 * @param array $elements The attributed orders to sort.
 * @return void
 */
	public function sortByPosition(&$Model, $elements = array()) {
		usort($elements, array($this, '__positionComparison'));
		return $elements;
	}

	public function repairPositionning(Model &$Model, $foreignKeys = '\n') { //'\n' is an arbitrary value which is an improbable foreign key value
		$foreignKeyField = $this->settings[$Model->alias]['foreignKey'];
		$PositionnedModels = $this->_getPositionedModels($Model);

		if ($foreignKeys === '\n') {
			$foreignKeys = $this->_getDistinctForeignKeys($PositionnedModels, $foreignKeyField);
		}
		$foreignKeys = (array)$foreignKeys;
		$foreignKeys = array_unique($foreignKeys);

		$recordsToSave = array();
		foreach ($foreignKeys as $foreignKey) {
			$records = $this->_getRecordsForForeignKey($PositionnedModels, $foreignKeyField, $foreignKey);

			$recordsToSave = array_merge_recursive(
				$recordsToSave,
				$this->_getPositionRepairedRecords($PositionnedModels, $records)
			);
		}
		return $this->_savePositionRepairedRecords($PositionnedModels, $recordsToSave);
	}

	public function unsetPosition(Model $Model, $records) {
		foreach ($records as &$record) {
			$record['position'] = 0;
		}
		$save = $Model->saveMany($records, array('validate' => false, 'callbacks' => false));
		return $save;
	}

/**
 * Check if all needed informations are in settings
 *	- Check if foreignKey is in settings
 *
 * @return boolean True if the settings are correct, false otherwise
 */
	protected function _checkSettings($settings) {
		$correct = true;
		foreach (array('foreignKey', 'model') as $mandatorySettings) {
			$settingsGiven = array_key_exists($mandatorySettings, $settings) &&
				!empty($settings[$mandatorySettings]);
			if(!$settingsGiven) {
				trigger_error(__d('positionable', 'The foreignKey settings as to be set'));
			}
			$correct = $correct && $settingsGiven;
		}
		return $correct;
	}

/**
 * Add validation rules on position
 *
 * @param Model $Model Model using the behavior
 */
	protected function _addValidationRules(&$Model) {
		$postionRules = array(
			'isNatural' => array(
				'rule' => array('isNatural'),
				'message' => __d('positionable', 'Please enter a positive value to this field.'),
				'allowEmpty' => true,
				'required' => false,
				'last' => true,
			),
			'uniquePerForeignKey' => array(
				'rule' => array('uniquePerForeignKey'),
				'message' => __d('positionable', 'The position has to be unique per foreign key.'),
			),
		);
		if (empty ($Model->validate['position'])) {
			$Model->validate['position'] = $postionRules;
		} else {
			$Model->validate['position'] = array_merge_recursive(
				$Model->validate['position'],
				$postionRules
			);
		}
	}

/**
 * Return a list of all application models acting as truck positioned behavior
 *
 * @param Model $Model Model using the behavior
 * @return array List of models
 */
	protected function _getPositionedModels($Model) {
		$foreignModel = $this->settings[$Model->alias]['model'];
		if (empty($this->_Models[$foreignModel])) {
			$models = $this->_getModels();
			foreach ($models as $modelName) {
				$TempModel = ClassRegistry::init($modelName);
				if (isset($TempModel->Behaviors) && is_a($TempModel->Behaviors, 'BehaviorCollection')) {
					if($this->_isPositionableOn($TempModel, $foreignModel)) {
						$this->_Models[$foreignModel][] = $TempModel;
					}
				}
			}
		}
		return $this->_Models[$foreignModel];
	}

/**
 * Return the highest position for a model
 *
 * @param Model $Model Model using the behavior
 * @return int The highest position
 */
	protected function _getMaxPosition(&$Model) {
		$maxPositions = array(1);
		$foreignKey = $this->settings[$Model->alias]['foreignKey'];
		if (!empty($Model->data[$Model->alias][$foreignKey])) {
			$models = $this->_getPositionedModels($Model);
			foreach ($models as $_BehaviorModel) {
				$position = $_BehaviorModel->field(
					'max(' . $_BehaviorModel->alias . '.position) as max',
					array(
						$_BehaviorModel->alias . '.' . $foreignKey =>
							$Model->data[$Model->alias][$foreignKey]
					)
				);

				if (!empty($Model->data[$Model->alias][$Model->primaryKey])) {
					$position = intval($position) - 1;
				}

				$maxPositions[] = intval($position) + 1;
			}
		}
		return max($maxPositions);
	}

/**
 * Allows to know if a model is positionnable on another
 *
 * @param Model $Model Model which we want to know if it is positionable
 * @param string $foreignModelName Name of the model on which the first model has to be positionable
 * @return boolean True if the model is positionable, false otherwise
 */
	protected function _isPositionableOn($Model, $foreignModelName) {
		$behaviorName = substr(get_class($this), 0, -8);
		return $Model->Behaviors->enabled($behaviorName) &&
			!empty($this->settings[$Model->alias]) &&
			$this->settings[$Model->alias]['model'] == $foreignModelName;
	}

/**
 * Allows to get all app models
 *
 * @return mixed
 * @see App::objects
 */
	protected function _getModels() {
		return App::objects('model', null, false);
	}

/**
 * User defined sort : sort by position.
 *
 * @param AttributedOrder $a The first element to be compared
 * @param AttributedOrder $b The second element to be compared
 * @return int
 */
	private static function __positionComparison ($a, $b){
		if (!isset($a['position'])) {
			$alias = array_keys($a);
			$alias = $alias[0];
			$a = $a[$alias];
			$alias = array_keys($b);
			$alias = $alias[0];
			$b = $b[$alias];
		}
		return ($a['position'] < $b['position']) ? -1 : 1;
	}

	protected function _getDistinctForeignKeys($Models, $foreignKeyField) {
		$foreignKeys = array();
		foreach ($Models as $Model) {
			$records = $Model->find('all', array(
				'fields' => $foreignKeyField
			));

			$foreignKeys = array_merge(
				$foreignKeys,
				(array)Hash::extract($records, '{n}.' . $Model->alias . '.' .$foreignKeyField)
			);
		}
		return $foreignKeys;
	}

	protected function _getRecordsForForeignKey($Models, $foreignKeyField, $foreignKeyValue) {
		$records = array();
		foreach ($Models as $Model) {
			$records = array_merge($records, $Model->find('all', array(
				'conditions' => array(
					$Model->escapeField($foreignKeyField) => $foreignKeyValue,
					'NOT' => array(
						$Model->escapeField('position') => 0
					)
				),
				'recursive' => -1
			)));
		}
		return $Model->sortByPosition($records);
	}

	protected function _getPositionRepairedRecords($Models, $records) {
		$repositionnedRecords = array();
		$modelAliases = Hash::extract($Models, '{n}.alias');
		for ($position = 0; $position < count($records); $position++) {
			foreach ($records[$position] as $modelAlias => $modelData) {
				if (in_array($modelAlias, $modelAliases)) {
					$modelData['position'] = $position + 1;
					if (!array_key_exists($modelAlias, $repositionnedRecords)) {
						$repositionnedRecords[$modelAlias] = array();
					}
					$repositionnedRecords[$modelAlias][] = $modelData;
				}
			}
		}
		return $repositionnedRecords;
	}

	protected function _savePositionRepairedRecords($Models, $recordsToSave) {
		$success = true;
		foreach (array('unsetPosition', 'saveMany') as $callback) {
			foreach ($Models as $Model) {
				if (!empty($recordsToSave[$Model->alias])) {
					$records = $recordsToSave[$Model->alias];
					if ($callback == 'saveMany') {
						$Model->validateMany($records);
					}
					$success = $success && $Model->{$callback}($records, array('callbacks' => false, 'validate' => false));
				}
			}
		}
		return $success;
	}
}