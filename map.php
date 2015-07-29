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
ini_set('memory_limit', '1024M');
mb_internal_encoding('UTF-8');

$operators = array(
	'01' => 'Telenor',
	'30' => 'Telekom',
	'70' => 'Vodafone',
);

$nets = array(
	2 => 'gsm',
	3 => 'umts',
	4 => 'lte',
);

try {
	$osm = new OSM;
	$osm->bbox(@$_REQUEST['bbox']);
	$osm->header();

	$cellids = array();
	$cellids_by_node = array();

	if (!isset($params['noosm'])) {
		$overpass = Overpass::query($osm->bbox);
		$osm->merge($overpass);

		$xml = simplexml_load_string($overpass);
		foreach ($xml->node as $node) {
			$n = $node->attributes();
			$tags = array();
			foreach ($node->tag as $tag) {
				$t = $tag->attributes();
				$tags[(string) $t['k']] = (string) $t['v'];
			}

			foreach ($nets as $net) {
				$key = $net . ':cellid';
				if (isset($tags[$key]) &&
					isset($tags['MCC']) &&
					isset($tags['MNC'])) {

					$ops = explode(' ', $tags[$key]);
					$mcc = $tags['MCC'];
					$mncs =  explode(';', $tags['MNC']);
					if (count($ops) == count($mncs)) {
						foreach ($mncs as $i => $mnc) {
							$mnc = sprintf('%02d', trim($mnc));
							$cidlist = $ops[$i];
							$cids = explode(';', $cidlist);
							foreach ($cids as $cid) {
								$cid = trim($cid);
								if ($cid == '') continue;
								$cellids[$mcc][$mnc][$net][$cid] = (string) $n['id'];
								$cellids_by_node[(string) $n['id']][$mcc][$mnc][$net][] = $cid;
							}
						}
					}
				}
			}
		}
	}

	$pg = pg_connect(PG_CONNECTION_STRING);

	$where = array();
	if ($osm->bbox)
		$where[] = sprintf("g && ST_Envelope(ST_GeomFromText(
			'LINESTRING(%1.7f %1.7f, %1.7f %1.7f)'))",
			$osm->bbox[0], $osm->bbox[1], $osm->bbox[2], $osm->bbox[3]);

	if (is_numeric($params['mnc']))
		$where[] = sprintf('mnc=%d', $params['mnc']);

	if (!count($where)) $where[] = '1=1';

	$sql = sprintf("SELECT * FROM measurements
		INNER JOIN (
			SELECT DISTINCT mcc, mnc, site
			FROM measurements
			WHERE %s
			) AS cellids
		ON measurements.mcc=cellids.mcc
		AND measurements.mnc=cellids.mnc
		AND measurements.site=cellids.site
		-- AND measurements.rssi>-100
		ORDER BY measured", implode(' AND ', $where));

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
		unset($tags['cell']);

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
		$tags['net'] = cellnet($tags);
		$net = array_search($tags['net'], $nets); // ez így szám lesz
		$lat = $row['lat'];
		$lon = $row['lon'];

		$node = new Node($lat, $lon);
		$node->tags = $tags;
		$node->id = sprintf('9%012d', $row['id']);
		$node->attr = array('version' => '1');

		$id = sprintf('%03d %02d %05d %d', $tags['mcc'], $tags['mnc'], $cid, $net);
		$weight = $tags['rssi'] + 90;
		if ($weight < 1) $weight = 1;
		if ($weight>0) {
			@$cells[$id]['lat'] += $lat * $weight;
			@$cells[$id]['lon'] += $lon * $weight;
			@$cells[$id]['weight'] += $weight;
			@$cells[$id]['count'] ++;
			$cells[$id]['tags'] = $node->tags;
			if (!isset($cells[$id]['rssi']) || $cells[$id]['rssi'] < $node->tags['rssi']) $cells[$id]['rssi'] = $node->tags['rssi'];
		}

		// $osm->nodes[] = $node;
		if (!isset($params['noraw'])) $osm->outputNode($node);
		unset($node);

	}

	$i = 0;
	foreach ($cells as $id=> $cell) {
		if ($cell['count']<10) continue;

		$lat = $cell['lat'] / $cell['weight'];
		$lon = $cell['lon'] / $cell['weight'];

		if ($osm->bbox) {
			// ha nagyon távoli rokont talált, azt hagyjuk (Telekom)
			// ha a súylpont távolabb van befoglaló közepétől, mint a befoglaló átlója
			// vagy 20 kilométer
			$clon = ($osm->bbox[0]+$osm->bbox[2])/2;
			$clat = ($osm->bbox[1]+$osm->bbox[3])/2;

			$bsize = distance($osm->bbox[1], $osm->bbox[0], $osm->bbox[3], $osm->bbox[2]);
			if ($bsize<20000) $bsize = 20000;
			if (distance($lat, $lon, $clat, $clon) > $bsize) continue;
		}

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
			'net' => $cell['tags']['net'],
		);
		$node->id = '9' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';

		$cid = isset($node->tags['cid']) ? $node->tags['cid'] : $node->tags['cellid'];
		$cid = floor($cid/10)*10;
		$id = sprintf('%03d %02d %05d', $node->tags['mcc'], $node->tags['mnc'], $cid);

		$weight = $cell['rssi'] + 90;
		if ($weight < 1) $weight = 1;
		@$locations[$id]['lat'] += $lat * $weight;
		@$locations[$id]['lon'] += $lon * $weight;
		@$locations[$id]['weight'] += $weight;
		@$locations[$id]['count'] ++;
		@$locations[$id]['nodes'][] = $node;

	}

	$multiple_nodeids = array();
	foreach ($locations as $id => $location) {
		if (count($location['nodes']) == 1) continue;

		$lat = $location['lat'] / $location['weight'];
		$lon = $location['lon'] / $location['weight'];

		$node = new Node($lat, $lon);
		$node->id = '8' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';

		$nodeid = null;
		foreach ($location['nodes'] as $cell) {
			$osm->nodes[] = $cell;

			$node->tags['MCC'] = $cell->tags['mcc'];
			$node->tags['MNC'] = sprintf('%02d', $cell->tags['mnc']);
			$node->tags['operator'] = $operators[$node->tags['MNC']];
			$net = cellnet($cell->tags);
			$key = cellkey($net);

			$_nodeid = null;
			$_nodeid = @$cellids
				[$node->tags['MCC']]
				[$node->tags['MNC']]
				[$net]
				[$cell->tags[$key]];

			$node->tags[$net . ':cellid'][] = $cell->tags[$key];
			$node->tags[$net . ':LAC'] = $cell->tags['lac'];
			$node->tags[$net . ':RNC'] = $cell->tags['rnc'];

			// ha már volt választottunk és az más, mint amit most találtunk, ezt rögzítjük
			if ($nodeid !== null && $_nodeid !== null && $nodeid = $_nodeid)
				$multiple_nodeids[] = array($nodeid, $_nodeid);

			if ($_nodeid !== null) $nodeid = $_nodeid;

			$comm = array();
			foreach ($nets as $net)
				if (isset($node->tags[$net . ':cellid'])) $comm[] = $net;
			$node->tags['communication:mobile_phone'] = $comm;

		}

		// ha találtunk meglevő osm pontot, akkor azt használjuk
		if ($nodeid !== null) {
			$node->id = $nodeid;
			// a node megy a levesbe, nem tesszük a térképre

			$cells_at_node = $cellids_by_node
				[$nodeid]
				[$node->tags['MCC']]
				[$node->tags['MNC']];

		} else {
			foreach ($node->tags as $k => $v) {
				if (is_array($v)) {
					if (is_numeric($v[0])) {
						sort($v, SORT_NUMERIC);
					} else {
						sort($v);
					}
					$node->tags[$k] = implode(';', $v);
				}
			}
			$osm->nodes[] = $node;
		}

		// behúzzuk a vonalakat
		foreach ($location['nodes'] as $cell) {
			$way = new Way;
			$way->id = $cell->id;
			$way->attr['version'] = '9999';
			$way->addNode($node);
			$way->addNode($cell);
			$way->tags = $cell->tags;

			// ha meglevő node, akkor figyelmeztetjük az új cellákra
			if ($nodeid !== null) {
				$net = cellnet($cell->tags);
				$key = cellkey($net);
				if (!in_array($cell->tags[$key], $cells_at_node[$net])) {
					$way->tags['fixme'] = 'new cell';
				}
			}

			$osm->ways[] = $way;
		}

	}

	$osm->outputData();
	$osm->footer();

} catch (Exception $e) {

	header('HTTP/1.1 400 Bad Request');
	echo $e->getMessage();

}

function rssi ($signal) {
	if ($signal == null) return null;
	if ($signal > 60) return -$signal;
	if ($signal < 0) return $signal;
	return 2*$signal-113;
}

function cellid ($node1, $node2) {
	if ($node1->tags['cellid']>$node2->tags['cellid']) return 1;
	if ($node1->tags['cellid']<$node2->tags['cellid']) return -1;
	return 0;
}

function cellnet ($tags) {
	global $nets;

	if (@$tags['net'] != '' &&
		in_array(strtolower($tags['net']), $nets)) {
		return strtolower($tags['net']);

	} else if (@$tags['radio'] != '' &&
		in_array(strtolower($tags['radio']), $nets)) {
		return strtolower($tags['radio']);

	} else if (@$tags['tac']>0) {
		return 'lte';

	} else if (@$tags['cid']>0 || @$tags['cellid'] >= 65536) {
		return 'umts';

	} else {
		return 'gsm';
	}

}

function cellkey ($net) {
	if ($net == 'gsm') {
		return 'cellid';
	} else {
		return 'cid';
	}
}

function distance ($lat1, $lon1, $lat2, $lon2) {

	$R = 6371000;
	$φ1 = $lat1 * pi() / 180;
	$φ2 = $lat2 * pi() / 180;
	$Δφ = ($lat2-$lat1) * pi() / 180;
	$Δλ = ($lon2-$lon1) * pi() / 180;

	$a = sin($Δφ/2) * sin($Δφ/2) +
		    cos($φ1) * cos($φ2) *
		    sin($Δλ/2) * sin($Δλ/2);
	$c = 2 * atan2(sqrt($a), sqrt(1-$a));

	$d = $R * $c;
	return $d;

}
