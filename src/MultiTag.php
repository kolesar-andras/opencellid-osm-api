<?php

class MultiTag {

	private $tags; // associative array for key/value pairs
	var $indexkey; // key for index
	var $index; // a value in indexkey
	var $fillvalue = 'fixme'; // placeholder for missing values
	var $indexes; // array of index values
	var $position; // 0-based position of index at indexkey

	function __construct ($tags=null, $indexkey=null, $index=null) {
		$this->tags = $tags;
		$this->indexkey = $indexkey;
		$this->index = $index;
		$this->indexes = $this->getIndexes();
		$this->position = $this->getPosition();
	}

	function getIndexes () {
		return preg_split('/; */', $this->tags[$this->indexkey]);
	}

	function getPosition () {
		$position = array_search($this->index, $this->indexes);
		if ($position === false)
			throw New Exception('index not found');
		return $position;
	}

	function getValues ($key) {

		if (isset($this->tags[$key])) {
			$values = explode('; ', $this->tags[$key]);
			if (count($this->indexes) != count($values)) {
				throw new Exception(
					sprintf('count mismatch (%s != %s)',
						$this->indexkey,
						$key
					)
				);
			}
			return $values;
		}
	}

	function getValue ($key) {

		$values = $this->getValues($key);
		return @$values[$this->position];

	}

	function setValue ($key, $value) {

		$values = $this->getValues($key);
		if (!count($values))
			$values = array_fill(0, count($this->indexes), $this->fillvalue);

		$values[$this->position] = $value;
		$this->tags[$key] = implode('; ', $values);

	}

	function compareValue ($key, $value) {

		$current = $this->getValue($key);
		if ($current === null) return;
		if ($current == $this->fillvalue) return;
		if ($current == 'none') return;
		if ($current != $value)
			throw new Exception(
				sprintf($key . ' mismatch (%s=%s: %s != %s)',
				$this->indexkey, $this->index, $current, $value)
			);
	}

	function setCompareValue ($key, $value) {

		$this->compareValue($key, $value);
		$this->setValue($key, $value);

	}

	function getTags () {
		return $this->tags;
	}

}
