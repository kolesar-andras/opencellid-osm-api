<?php

/**
 * OpenCellID mérések letöltése osm fájlként
 *
 * ezen felül:
 * mérések alapján súlyozott cella-középpontok számítása
 * azonos állomáshoz tartozó cellák összekapcsolása
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2015.02.28
 *
 */

// az alábbi fájl nem része a forráskódnak
// valami ilyesmi legyen bennne:
// define('PG_CONNECTION_STRING', 'host=localhost user=dummy password=secret');
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
	$elements = array();
	$display = array(); // ezeket az azonosítókat fogjuk megjelentetni
	$list = array(); // meg még ezeket

	// ha nem kér ismert cellákat, méréseket sem kap rájuk
	if (isset($params['nocelltagged']))
		$params['norawtagged'] = true;

	// beolvassuk a helyben tárolt cellaállományt
	if (!isset($params['nocache'])) {
		$json = file_get_contents('../geojson/overpass.json');
		$data = json_decode($json, true);
		if (is_array($data['elements']))
			$elements = $data['elements'];

		// ha nem lesz friss lekérdezés a befoglaló	téglalapra,
		// ezekből választjuk ki a befoglalóra esőket
		if (isset($params['noosm'])) {
			foreach ($data['elements'] as $element) {
				// csak a node-ok koordinátáit vizsgáljuk
				// ami eléggé hanyag dolog,
				// mert lehet hogy nincs mind bent
				if ($element['type'] != 'node') continue;
				if (!$osm->inBBOX($element['lat'], $element['lon'])) continue;
				$id = $element['type'] . '#' . $element['id'];
				$display[$id] = true;
			}
		}
	}

	// friss adatokat töltünk le a befoglaló téglalapból
	if (!isset($params['noosm'])) {
		$overpass = Overpass::query($osm->bbox);
		$data = json_decode($overpass, true);
		if (is_array($data['elements']))
			$elements = array_merge($elements, $data['elements']);

		// ezeket mind megjelenítjük majd
		foreach ($data['elements'] as $element) {
			$id = $element['type'] . '#' . $element['id'];
			$display[$id] = true;
		}
	}

	$sites = array();
	foreach ($elements as $element) {
		$id = $element['type'] . '#' . $element['id'];
		$sites[$id] = $element;
		$tags = $element['tags'];

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
							$cellids[$mcc][$mnc][$net][$cid] = $id;
							$cellids_by_node[$id][$mcc][$mnc][$net][] = $cid;
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
		AND measurements.rssi>-113
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
		unset($tags['signal']);
		unset($tags['site']);
		unset($tags['cell']);
		// unset($tags['created']);

		$tags['mnc'] = sprintf('%02d', $tags['mnc']);
		$tags['measured'] = formatDateTime($tags['measured']);
		$tags['created'] = formatDateTime($tags['created']);
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

		if (isset($cellids
			[$tags['mcc']]
			[$tags['mnc']]
			[$tags['net']]
			[$tags[cellkey($tags['net'])]]))
				$tags['tagged'] = 'yes';

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
		
		if (isset($params['noraw'])) {
		} else if (isset($params['norawoutside']) &&
			!$osm->inBBOX($node->lat, $node->lon)) {
		} else if (isset($params['norawtagged']) &&
			isset($node->tags['tagged'])) {
		} else {
			$osm->outputNode($node);
		}
		unset($node);

	}

	$i = 0;
	foreach ($cells as $id => $cell) {
		if ($cell['count']<10) continue;

		$lat = $cell['lat'] / $cell['weight'];
		$lon = $cell['lon'] / $cell['weight'];

		if ($osm->bbox) {
			// ha nagyon távoli rokont talált, azt hagyjuk (Telekom)
			// ha a súlypont távolabb van befoglaló közepétől, mint a befoglaló átlója
			// vagy 10 kilométer
			$clon = ($osm->bbox[0]+$osm->bbox[2])/2;
			$clat = ($osm->bbox[1]+$osm->bbox[3])/2;

			$bsize = distance($osm->bbox[1], $osm->bbox[0], $osm->bbox[3], $osm->bbox[2]);
			if ($bsize < 10000) $bsize = 10000;
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

		if (isset($cellids
					[$cell['tags']['mcc']]
					[$cell['tags']['mnc']]
					[$cell['tags']['net']]
					[$cell['tags'][cellkey($cell['tags']['net'])]]))
			$node->tags['tagged'] = 'yes';

		$cid = isset($node->tags['cid']) ? $node->tags['cid'] : $node->tags['cellid'];
		if ($cell['tags']['mnc'] != '30') $cid = floor($cid/10)*10;
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

		$lat = $location['lat'] / $location['weight'];
		$lon = $location['lon'] / $location['weight'];

		$node = new Node($lat, $lon);
		$node->id = '8' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';

		$nodeid = null;
		foreach ($location['nodes'] as $cell) {

			if (isset($params['nocelltagged']) &&
				isset($cell->tags['tagged']))
					continue;
			
			$osm->nodes[] = $cell;

			$node->tags['MCC'] = $cell->tags['mcc'];
			$node->tags['MNC'] = $cell->tags['mnc'];
			$node->tags['operator'] = $operators[$node->tags['MNC']];
			$net = cellnet($cell->tags);
			$key = cellkey($net);

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
			$node->id = getNodeId($nodeid);
			$display[$nodeid] = true;
			// a node megy a levesbe, nem tesszük a térképre

			$cells_at_node = $cellids_by_node
				[$nodeid]
				[$node->tags['MCC']]
				[$node->tags['MNC']];

		} else if (isset($params['nosingle']) &&
			count($location['nodes']) == 1) {
			// csak egy cella van a helyszínen
			// és nincs hozzá meglevő pont
			// és nem kérte az ilyeneket
			// ezért nem készítünk neki feltételezett helyszínt
			continue;
			
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

			if (isset($params['nocelltagged']) &&
				isset($cell->tags['tagged']))
					continue;

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
				if (!isset($cells_at_node[$net]) ||
					!in_array($cell->tags[$key], $cells_at_node[$net])) {
					$way->tags['fixme'] = 'new cell';
				}
			}

			$osm->ways[] = $way;
		}

	}

	// kibontjuk az esetleges hivatkozásokat
	foreach ($display as $id => $dummy) {
		addToList($id);
	}

	// hozzáadjuk a frissen kibontottakat
	$display = array_merge($display, $list);

	// megjelenítjük a hivatkozott osm azonostókat
	foreach ($display as $id => $dummy) {
		$element = $sites[$id];
		$osm->addElement($element);
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

function getNodeId ($id) {
	if (!preg_match('/^node#([0-9]+)$/', $id, $regs)) return false;
	return $regs[1];
}

function addToList ($id) {

	global $sites, $list;
	$element = $sites[$id];

	switch ($element['type']) {
		case 'way':
			foreach ($element['nodes'] as $nodeid) {
				$id = 'node#' . $nodeid;
				$list[$id] = true;
			}
			break;

		case 'relation':
			foreach ($element['members'] as $member) {
				$id = $member['type'] . '#' . $member['id'];
				$list[$id] = true;
				addToList($id);
				// rekurzív, reméljük nincs körkörös hivatkozás
			}
			break;
	}

}

function formatDateTime ($timestamp) {
	return gmdate('Y-m-d\TH:i:s\Z', substr($timestamp, 0, -3));
}
