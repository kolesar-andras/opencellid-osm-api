<?php

/**
 * osm kimenet
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2015.02.28
 *
 */

class OSM extends Map {

	var $bbox;
	var $extra;

	function bbox ($bounding) {

		// bounding box
		if ($bounding == '') {
			$this->bbox = null;

		} else {
			$bbox = explode(',', $bounding);
			if (count($bbox) != 4) throw new Exception('invalid bbox syntax');
			if ($bbox[0]>=$bbox[2] || $bbox[1]>=$bbox[3]) throw new Exception('invalid bbox');
			foreach ($bbox as $coord) if (!is_numeric($coord)) throw new Exception('invalid bbox');
			$this->bbox = $bbox;

		}

		return $this->bbox;

	}

	function inBBOX ($lat, $lon) {
		if ($lat < $this->bbox[1]) return false;
		if ($lat > $this->bbox[3]) return false;
		if ($lon < $this->bbox[0]) return false;
		if ($lon > $this->bbox[2]) return false;
		return true;
	}

	function header () {
		header('Content-type: text/xml; charset=utf-8');
		header('Content-disposition: attachment; filename=map.osm');
		echo "<?xml version='1.0' encoding='UTF-8'?>", "\n";
		echo "<osm version='0.6' upload='false' generator='OpenCellID API'>", "\n";
		if ($this->bbox) echo sprintf("  <bounds minlat='%1.7f' minlon='%1.7f' maxlat='%1.7f' maxlon='%1.7f' origin='OpenCellID API' />",
			$this->bbox[1], $this->bbox[0], $this->bbox[3], $this->bbox[2]), "\n";

	}

	function output () {
		$this->header();
		$this->outputData();
		$this->footer();
	}

	function outputData () {
		$this->outputNodes();
		$this->outputWays();
		$this->outputRelations();
	}

	function outputNodes () {
		if (is_array($this->nodes))
			foreach ($this->nodes as $node)
				$this->outputNode($node);
	}

	function outputNode ($node) {
		$latlon = sprintf('lat="%1.7f" lon="%1.7f" id="%s"', $node->lat, $node->lon, $node->id);
		if (!isset($node->tags)) {
			echo sprintf('<node %s %s />', $latlon, $this->attrs($node->attr)), "\n";
		} else {
			echo sprintf('<node %s %s>', $latlon, $this->attrs($node->attr)), "\n";
			$this->print_tags($node->tags);
			echo '</node>', "\n";
		}
	}

	function outputWays () {
		if (is_array($this->ways)) foreach ($this->ways as $way) {
			if (isset($way->deleted)) continue;
			$attr = $way->attr;
			$attr['id'] = $way->id;
			echo sprintf('<way %s >', $this->attrs($attr)), "\n";
			foreach ($way->nodes as $node) {
				// elfogadunk pusztán azonosítót is
				$nodeid = is_a($node, 'Node') ? $node->id : $node;
				echo sprintf('<nd ref="%s" />', $nodeid), "\n";
			}
			$this->print_tags($way->tags);
			echo '</way>', "\n";

		}
	}

	function outputRelations () {
		if (is_array($this->relations)) foreach ($this->relations as $rel) {
			if (isset($rel->deleted)) continue;
			$attr = $rel->attr;
			$attr['id'] = $rel->id;
			echo sprintf('<relation %s >', $this->attrs($attr)), "\n";
			foreach ($rel->members as $member) {
				echo sprintf('<member %s />', $this->attrs($member)), "\n";
			}
			$this->print_tags($rel->tags);
			echo '</relation>', "\n";
		}
	}

	function footer () {

		echo $this->extra;
		echo '</osm>', "\n";

	}

	function merge ($osm) {
		$osm = str_replace("\r\n", "\n", $osm);
		$lines = explode("\n", trim($osm));
		$lines = array_slice($lines, 4, count($lines)-5); // XXX hardcoded
		$this->extra .= implode("\n", $lines);
	}

	function attrs ($arr) {
		$attrs = array();
		if (is_array($arr)) foreach ($arr as $k => $v) {
			$attrs[] = sprintf('%s="%s"', $k, htmlspecialchars($v));
		}
		return implode(' ', $attrs);
	}

	function print_tags ($tags) {
		if (is_array($tags)) foreach ($tags as $k => $v) {
			if (trim(@$v) == '') continue;
			echo sprintf('<tag k="%s" v="%s" />', htmlspecialchars(trim($k)), htmlspecialchars(trim($v))), "\n";
		}
	}

	function addElement ($element) {
		switch ($element['type']) {
			case 'node':
				$this->nodes[] = $this->createNode($element);
				break;

			case 'way':
				$this->ways[] = $this->createWay($element);
				break;

			case 'relation':
				$this->relations[] = $this->createRelation($element);
				break;
		}
	}

	// overpass json szerkezetű adatból osm node-ot készítünk
	function createNode ($obj) {

		$node = new Node($obj['lat'], $obj['lon']);
		$node->tags = $obj['tags'];
		$node->id = $obj['id'];
		$node->attr = $obj;
		unset($node->attr['lat']);
		unset($node->attr['lon']);
		unset($node->attr['id']);
		unset($node->attr['tags']);

		return $node;

	}

	// overpass json szerkezetű adatból osm node-ot készítünk
	function createWay ($obj) {

		$way = new Way();
		$way->tags = $obj['tags'];
		$way->id = $obj['id'];
		$way->nodes = $obj['nodes'];
		$way->attr = $obj;
		unset($way->attr['id']);
		unset($way->attr['tags']);
		unset($way->attr['nodes']);
		return $way;

	}

	// overpass json szerkezetű adatból osm node-ot készítünk
	function createRelation ($obj) {

		$relation = new Relation();
		$relation->tags = $obj['tags'];
		$relation->id = $obj['id'];
		$relation->members = $obj['members'];
		$relation->attr = $obj;
		unset($relation->attr['id']);
		unset($relation->attr['tags']);
		unset($relation->attr['members']);
		return $relation;

	}

}
