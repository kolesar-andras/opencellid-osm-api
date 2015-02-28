<?php

/**
 * OSM way
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2015.02.05
 *
 */

class Way extends Element {

	public $nodes = array();

	function getNodes () {
		return $this->nodes;
	}

	function addNode (Node $node) {
		$this->nodes[] = $node;
	}

}
