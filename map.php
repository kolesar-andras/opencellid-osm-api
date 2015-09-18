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
ini_set('memory_limit', '2048M');
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

	$siteids = array();
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
				$mncs = explode(';', $tags['MNC']);

				if ($net == 'umts')
					$rncs = explode(';', $tags['umts:RNC']);

				if ($net == 'lte')
					$eNBs = explode(';', $tags['lte:eNB']);

				if (count($ops) == count($mncs)) {
					foreach ($mncs as $i => $mnc) {
						$mnc = sprintf('%02d', trim($mnc));
						$cidlist = $ops[$i];
						$cids = explode(';', $cidlist);
						foreach ($cids as $cid) {
							$cid = trim($cid);
							if ($cid === '') continue;
							if (!is_numeric($cid)) continue;

							$site = (int) floor($cid/10);

							if ($net == 'umts') {
								$rnc = $rncs[$i];
								if (!is_numeric($rnc)) continue;
								$cid += $rnc*65536;
							}

							if ($net == 'lte') {
								$eNB = $eNBs[$i];
								$site = $eNB;
								if (!is_numeric($eNB)) continue;
								$cid += $eNB*256;
							}

							if ($mcc == 30 && $net != 'lte')
								$site = null;

							if ($site !== null)
								$siteids[$mcc][$mnc][$site] = $id;

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
		$where[] = sprintf("g && ST_Envelope(ST_SetSRID(ST_GeomFromText(
			'LINESTRING(%1.7f %1.7f, %1.7f %1.7f)'), 4326))",
			$osm->bbox[0], $osm->bbox[1], $osm->bbox[2], $osm->bbox[3]);

	if (is_numeric($params['mnc']))
		$where[] = sprintf('mnc=%d', $params['mnc']);

	if (is_numeric($params['site']))
		$where[] = sprintf('site=%d', $params['site']);

	if (is_numeric($params['cell']))
		$where[] = sprintf('cell=%d', $params['cell']);

	if (in_array($params['net'], $nets))
		$where[] = sprintf("net='%s'", $params['net']);

	if (!count($where)) $where[] = '1=1';

	if (isset($params['nocelloutside'])) {
		$sql = sprintf("SELECT * FROM measurements
			WHERE measurements.rssi>-113
			AND %s", implode(' AND ', $where));

	} else {
		$sql = sprintf("SELECT * FROM measurements
			INNER JOIN (
				SELECT DISTINCT mcc, mnc, site, radio
				FROM measurements
				WHERE %s
				) AS cellids
			ON measurements.mcc=cellids.mcc
			AND measurements.mnc=cellids.mnc
			AND measurements.site=cellids.site
			AND measurements.radio=cellids.radio
			AND measurements.rssi>-113", implode(' AND ', $where));
	}

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
		unset($tags['site']); // TODO most már jó jön, használhatnánk
		unset($tags['cell']);
		// unset($tags['created']);

		// automatikus hibajavítás
		if ($tags['mcc'] == 216 &&
			$tags['mnc'] == 1 &&
			$tags['lac'] >= 5000 &&
			$tags['radio'] != 'LTE') {
				$tags['radio:original'] = $tags['radio'];
				$tags['radio'] = 'LTE';
		}

		$tags['mnc'] = sprintf('%02d', $tags['mnc']);
		$tags['measured'] = formatDateTime($tags['measured']);
		$tags['created'] = formatDateTime($tags['created']);
		$tags['rssi'] = rssi($row['signal']);

		if ($tags['lac'] > 65530) continue; // hibás mérés

		if ($tags['radio'] == 'GSM') {
			if ($tags['cellid'] > 65535) continue; // hibás mérés
			$tags['site'] = (int) floor($tags['cellid'] / 10);
		}

		if ($tags['radio'] == 'UMTS') {
			$cid = $tags['cellid'] & 65535;
			$rnc = $tags['cellid'] >> 16;
			if (is_numeric($tags['rnc']))
				if ($tags['rnc'] <> $rnc)
					$tags['warning:rnc'] = 'rnc does not match cellid';
			if (is_numeric($tags['cid']))
				if ($tags['cid'] <> $cid)
					$tags['warning:cid'] = 'cid does not match cellid';
			$tags['cid'] = $cid;
			$tags['rnc'] = $rnc;
			if ($tags['rnc'] == 0) continue; // hibás mérés
			$tags['site'] = (int) floor($tags['cid'] / 10);
		}

		if ($tags['radio'] == 'LTE') {
			$tags['cid'] = $tags['cellid'] & 255;
			$tags['enb'] = $tags['cellid'] >> 8;
			if ($tags['enb'] == 0) continue; // hibás mérés
			$tags['site'] = $tags['enb'];
		}

		if ($tags['mnc'] == '30' && $tags['radio'] != 'LTE')
			unset($tags['site']);

		$tags['net'] = cellnet($tags);
		$net = array_search($tags['net'], $nets); // ez így szám lesz
		$lat = $row['lat'];
		$lon = $row['lon'];

		if (isset($cellids
			[$tags['mcc']]
			[$tags['mnc']]
			[$tags['net']]
			[$tags['cellid']])) {
				$tags['tagged'] = 'yes';

		} else if (isset($tags['site']) && isset($siteids
			[$tags['mcc']]
			[$tags['mnc']]
			[$tags['site']])) {
				$tags['tagged'] = 'auto';
		}

		$node = new Node($lat, $lon);
		$node->tags = $tags;
		$node->id = sprintf('9%012d', $row['id']);
		$node->attr = array('version' => '1');

		$id = sprintf('%03d %02d %09d %d', $tags['mcc'], $tags['mnc'], $tags['cellid'], $net);
		$weight = $tags['rssi'] + 90;
		if ($weight < 1) $weight = 1;
		if ($weight>0) {
			@$cells[$id]['lat'] += $lat * $weight;
			@$cells[$id]['lon'] += $lon * $weight;
			@$cells[$id]['weight'] += $weight;
			@$cells[$id]['count'] ++;
			@$cells[$id]['tags'] = $tags;

			// számoljuk az előfordulásokat, mert nem minden mérés helyes
			foreach (array('lac') as $key)
				@$cells[$id]['stats'][$key][$tags[$key]]++;

			if (!isset($cells[$id]['rssi']) || $cells[$id]['rssi'] < $node->tags['rssi']) $cells[$id]['rssi'] = $node->tags['rssi'];
		}

		if (isset($params['noraw'])) {
		} else if (isset($params['norawoutside']) &&
			!$osm->inBBOX($node->lat, $node->lon)) {
		} else if (isset($params['norawtagged']) &&
			@$node->tags['tagged'] == 'yes') {
		} else if (isset($params['norawautotagged']) &&
			@$node->tags['tagged'] == 'auto') {
		} else {
			$osm->outputNode($node);
		}
		unset($node);

	}

	$i = 0;
	foreach ($cells as $id => $cell) {
		if ($cell['count']<10) continue;

		$tags = array();
		foreach ($cell['stats'] as $key => $values) {
			$out = array();
			arsort($values, SORT_NUMERIC);
			$index = 0;
			foreach ($values as $value => $count) {
				if (!$index) $tags[$key] = $value;
				$out[] = sprintf('%s [%d]', $value, $count);
				$index++;
			}
			if ($index>1) $tags[$key . ':stats'] = implode('; ', $out);
		}
		$cell['tags'] = array_merge($cell['tags'], $tags);

		$lat = $cell['lat'] / $cell['weight'];
		$lon = $cell['lon'] / $cell['weight'];

		// a Telekom összekapcsolásának letiltásával
		// az alábbi feleslegessé vált
		if (false && $osm->bbox) {
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
			'lac:stats' => $cell['tags']['lac:stats'],
			'cellid' => $cell['tags']['cellid'],
			'rnc' => $cell['tags']['rnc'],
			'enb' => $cell['tags']['enb'],
			'cid' => $cell['tags']['cid'],
			'net' => $cell['tags']['net'],
			'site' => $cell['tags']['site'],
			'tagged' => $cell['tags']['tagged'],
		);
		$node->id = '9' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';

		if (isset($node->tags['site'])) {
			$site = $node->tags['site'];
			$group = 0;
		} else {
			$site = $node->tags['cellid'];
			$group = 1;
		}

		$id = sprintf('%03d %02d %05d %d', $node->tags['mcc'], $node->tags['mnc'], $site, $group);

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
				[$cell->tags['cellid']];

			$node->tags[$net . ':cellid'][] = $cell->tags[$key];
			$node->tags[$net . ':LAC'] = $cell->tags['lac'];
			$node->tags[$net . ':RNC'] = $cell->tags['rnc'];
			$node->tags[$net . ':eNB'] = $cell->tags['enb'];

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
		$newcells = array();
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
				$key = 'cellid';
				if (!isset($cells_at_node[$net]) ||
					!in_array($cell->tags[$key], $cells_at_node[$net])) {
					$way->tags['fixme'] = 'new cell';
					$newcells[$net][] = $cell->tags;
				}
			}
			$osm->ways[] = $way;
		}

		if (!isset($params['noautotag'])) try {
			// egyúttal hozzá is írjuk a ponthoz
			$modified = false;
			$tags = $sites[$nodeid]['tags'];
			$multi = new MultiTag($tags, 'MNC', $node->tags['MNC']);

			foreach ($newcells as $net => $cells) {
				$cellidlist = $multi->getValue($net . ':cellid');

				if (!preg_match('/^[0-9;]+$/', $cellidlist)) {
					$list = array();
				} else {
					$list = explode(';', $cellidlist);
				}

				$multi->setCompareValue($net . ':LAC', $cells[0]['lac']);
				if ($net == 'gsm') {
					// nincs több

				} else if ($net == 'umts') {
					$multi->setCompareValue($net . ':RNC', $cells[0]['rnc']);
				} else if ($net == 'lte') {
					$multi->setCompareValue($net . ':eNB', $cells[0]['enb']);
				}

				// hozzáadjuk a cellákat
				foreach ($cells as $cell)
					$list[] = $cell[$net == 'gsm' ? 'cellid' : 'cid'];

				sort($list);
				$cellidlist = implode(';', $list);
				$multi->setValue($net . ':cellid', $cellidlist);
				$modified = true;
			}

			if ($modified) {
				$sites[$nodeid]['action'] = 'modify';
				$sites[$nodeid]['tags'] = $multi->getTags();
			}

		} catch (Exception $e) {
			$sites[$nodeid]['tags']['warning'] = $e->getMessage();
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
