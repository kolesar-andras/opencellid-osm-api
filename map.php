<?php

/**
 * OpenCellID mérések letöltése osm fájlként
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2015.02.28
 *
 */

include_once('include/postgresql.conf.php');

ini_set('display_errors', 1);
ini_set('max_execution_time', 1800);
ini_set('memory_limit', '512M');
mb_internal_encoding('UTF-8');

$operators = array(
	'01' => 'Telenor',
	'30' => 'Telekom',
	'70' => 'Vodafone',
);

try {

	$pg = pg_connect(PG_CONNECTION_STRING);

	$osm = new OSM;
	$osm->bbox(@$_REQUEST['bbox']);

	if ($osm->bbox) {
		$where = sprintf("WHERE g && ST_Envelope(ST_GeomFromText(
			'LINESTRING(%1.7f %1.7f, %1.7f %1.7f)'))",
			$osm->bbox[0], $osm->bbox[1], $osm->bbox[2], $osm->bbox[3]);
	} else {
		$where = '';
	}

	$sql = sprintf("SELECT * FROM measurements
		INNER JOIN (
			SELECT DISTINCT mcc, mnc, site
			FROM measurements
			%s
			) AS cellids
		ON measurements.mcc=cellids.mcc
		AND measurements.mnc=cellids.mnc
		AND measurements.site=cellids.site
		ORDER BY measured", $where);

	$result = pg_query($sql);

	$cells = array();
	$locations = array();

	while ($row = pg_fetch_assoc($result)) {

		$tags = $row;
		unset($tags['g']);
		unset($tags['lat']);
		unset($tags['lon']);
		unset($tags['id']);
		unset($tags['created']);
		unset($tags['signal']);
		unset($tags['site']);

		$tags['measured'] = gmdate('Y-m-d\TH:i:s\Z', substr($row['measured'], 0, -3));
		$tags['rssi'] = rssi($row['signal']);
		$cid = $tags['cellid'] & 65535;
		$rnc = (int) floor($tags['cellid'] / 65536);
		if (is_numeric($tags['rnc'])) {
			if ($tags['rnc'] <> $rnc) {
				$tags['warning:rnc'] = 'rnc does not match cellid';
			}
		} else if ($rnc > 0) {
			$tags['rnc'] = $rnc;
		}

		if (is_numeric($tags['cid'])) {
			if ($tags['cid'] <> $cid) {
				$tags['warning:cid'] = 'cid does not match cellid';
			}
		} else if ($rnc > 0) { // nem elírás, csak akkor ha van rnc
			$tags['cid'] = $cid;
		}

		$cid = isset($tags['cid']) ? $tags['cid'] : $tags['cellid'];
		$lat = $row['lat'];
		$lon = $row['lon'];

		$node = new Node($lat, $lon);
		$node->tags = $tags;
		$node->id = sprintf('9%012d', $row['id']);
		$node->attr = array('version' => '1');
		$osm->nodes[] = $node;

		$id = sprintf('%03d %02d %05d', $tags['mcc'], $tags['mnc'], $cid);
		$weight = $tags['rssi'] + 60;
		if ($weight>0) {
			@$cells[$id]['lat'] += $lat * $weight;
			@$cells[$id]['lon'] += $lon * $weight;
			@$cells[$id]['weight'] += $weight;
			@$cells[$id]['count'] ++;
			$cells[$id]['tags'] = $node->tags;
			if (!isset($cells[$id]['rssi']) || $cells[$id]['rssi'] < $node->tags['rssi']) $cells[$id]['rssi'] = $node->tags['rssi'];
		}

	}

	$i = 0;
	foreach ($cells as $id=> $cell) {
		if ($cell['count']<10) continue;

		$lat = $cell['lat'] / $cell['weight'];
		$lon = $cell['lon'] / $cell['weight'];

		$node = new Node($lat, $lon);
		$node->tags = array(
			'[count]' => $cell['count'],
			'rssi' => $cell['rssi'],
			'mcc' => $cell['tags']['mcc'],
			'mnc' => $cell['tags']['mnc'],
			'lac' => $cell['tags']['lac'],
			'cellid' => $cell['tags']['cellid'],
			'rnc' => $cell['tags']['rnc'],
			'cid' => $cell['tags']['cid'],
		);
		$node->id = '9' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';

		$cid = isset($node->tags['cid']) ? $node->tags['cid'] : $node->tags['cellid'];
		$cid = floor($cid/10)*10;
		$id = sprintf('%03d %02d %05d', $node->tags['mcc'], $node->tags['mnc'], $cid);

		$weight = 1;
		@$locations[$id]['lat'] += $lat * $weight;
		@$locations[$id]['lon'] += $lon * $weight;
		@$locations[$id]['weight'] += $weight;
		@$locations[$id]['count'] ++;
		@$locations[$id]['nodes'][] = $node;


	}

	foreach ($locations as $id => $location) {
		if (count($location['nodes']) == 1) continue;

		$lat = $location['lat'] / $location['weight'];
		$lon = $location['lon'] / $location['weight'];

		$node = new Node($lat, $lon);
		$node->id = '8' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';
		$osm->nodes[] = $node;

		$cellids = array();
		foreach ($location['nodes'] as $cell) {
			$osm->nodes[] = $cell;

			$way = new Way;
			$way->id = $cell->id;
			$way->attr['version'] = '9999';
			$way->addNode($node);
			$way->addNode($cell);
			$way->tags = $cell->tags;
			$osm->ways[] = $way;

			if (isset($cell->tags['cid'])) {
				$node->tags['umts:cellid'][] = $cell->tags['cid'];
				$node->tags['umts:LAC'] = $cell->tags['lac'];
				$node->tags['umts:RNC'] = $cell->tags['rnc'];
			} else {
				$node->tags['gsm:cellid'][] = $cell->tags['cellid'];
				$node->tags['gsm:LAC'] = $cell->tags['lac'];
			}

			$node->tags['MCC'] = $cell->tags['mcc'];
			$node->tags['MNC'] = sprintf('%02d', $cell->tags['mnc']);
			$node->tags['operator'] = $operators[$node->tags['MNC']];

			$comm = array();
			if (isset($node->tags['gsm:cellid'])) $comm[] = 'gsm';
			if (isset($node->tags['umts:cellid'])) $comm[] = 'umts';
			$node->tags['communication:mobile_phone'] = $comm;

		}

		foreach ($node->tags as $k => $v) {
			if (is_array($v)) {
				sort($v, SORT_NUMERIC);
				$node->tags[$k] = implode(';', $v);
			}
		}
	}

	// $osm->merge(Overpass::query($osm->bbox));
	$osm->output();

} catch (Exception $e) {

	header('HTTP/1.1 400 Bad Request');
	echo $e->getMessage();

}

function rssi ($signal) {
	if ($signal > 60) return -$signal;
	if ($signal < 0) return $signal;
	return 2*$signal-113;
}

function cellid ($node1, $node2) {
	if ($node1->tags['cellid']>$node2->tags['cellid']) return 1;
	if ($node1->tags['cellid']<$node2->tags['cellid']) return -1;
	return 0;
}
