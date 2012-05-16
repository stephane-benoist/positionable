# Positionable Plugin for CakePHP #

Version 2.0

The positionable plugin allows you to make positionable elements against a model.

## Usage ##

We have match and rest in a competition and we want to order both on them against a the competition.

To do that had the behavior to both Match and Rest models :

	class Match extends AppModel {
		public $actsAs = array(
			'MockPositionable' => array(
				'foreignKey' => 'competition_id',
				'model' => 'Competition'
			)
		);
	}

	class Rest extends AppModel {
		public $actsAs = array(
			'MockPositionable' => array(
				'foreignKey' => 'competition_id',
				'model' => 'Competition'
			)
		);
	}

Now if we add two matches and next one rest in the same competition, the matches will have position 1 and 2 and the rest will have the position 3.
If we move up the rest, the second match will automatically have position 3.

We want to display elements of a competition in order, we just have to do that :

	$myCompetitionId = 'my-competition';
	$matches = $this->Match->find('all', array(
		'conditions' => array('Match.competition_id' => myCompetitionId)
	);
	$rests = $this->Rest->find('all', array(
		'conditions' => array('Rest.competition_id' => myCompetitionId)
	);
	$competitionElements = array_merge($matches, $rests);
	$sortedCompetitionElements = $this->Match->sortByPosition($competitionElements);

## Model Requirement ##

For using this behavior a model MUST have a 'position' field and the settings defined foreign key field.

## Behavior Methods ##

`move($elementId, $newPosition)`: move an element to a new position, updating the positions of all others element positionable again the same model when needed.

`sortByPosition($elements)`: order all $elements by the position without distinction of model

## Requirement ##

* PHP version: PHP 5.2+
* CakePHP version: 2.x Stable

## License ##

Licensed under [The MIT License](http://www.opensource.org/licenses/mit-license.php)
