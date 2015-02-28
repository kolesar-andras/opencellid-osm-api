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
				echo sprintf('<nd ref="%s" />', $node->id), "\n";
			}
			$this->print_tags($way->tags);
			echo '</way>', "\n";

		}
	}

	function outputRelations () {
		if (is_array($this->relations)) foreach ($this->relations as $rel) {
			if (isset($way->deleted)) continue;
			$attr = $way->attr;
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
}
