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
	// alapértelmezett beállítások
	if (!isset($params['rawoutside'])) $params['norawoutside'] = true;
	if (!isset($params['rawtagged'])) $params['norawtagged'] = true;
	if (!isset($params['rawautotagged'])) $params['norawautotagged'] = true;
	if (!isset($params['irregular'])) $params['noirregular'] = true;
	if (!isset($params['celltagged'])) $params['nocelltagged'] = true;

	$osm = new OSM;
	$osm->bbox(@$_REQUEST['bbox']);
	$osm->header();

	$siteids = array(); // [$mcc][$mnc][$site]
	$cellids = array(); // [$mcc][$mnc][$net][$cellid]
	$cellids_by_node = array(); // [$id][$mcc][$mnc][$net][]
	$elements = array(); // osm objektumok gyorsítótárból vagy overpassról
	$display = array(); // ezeket az azonosítókat fogjuk megjelentetni
	$list = array(); // meg még ezeket

	// beolvassuk a helyben tárolt cellaállományt
	if (!isset($params['nocache'])) {
		$json = file_get_contents('../geojson/overpass.json');
		$data = json_decode($json, true);
		if (is_array($data['elements']))
			$elements = $data['elements'];

		// ha nem lesz friss lekérdezés a befoglaló téglalapra,
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

	$osmsites = array();
	foreach ($elements as $element) {
		$id = $element['type'] . '#' . $element['id'];
		$osmsites[$id] = $element;
		$tags = $element['tags'];

		foreach ($nets as $net) {
			$key = $net . ':cellid';
			if (isset($tags[$key]) &&
				isset($tags['MCC']) &&
				isset($tags['MNC'])) {

				$ops = explode(' ', $tags[$key]);
				$mcc = $tags['MCC'];
				$mncs = explode(';', $tags['MNC']);
				$rncs = explode('; ', @$tags['umts:RNC']);
				$eNBs = explode('; ', @$tags['lte:eNB']);

				if (count($ops) == count($mncs)) {
					foreach ($mncs as $i => $mnc) {
						$mnc = sprintf('%02d', trim($mnc));
						$cellidlist = $ops[$i];
						foreach (explode(';', $eNBs[$i]) as $eNB) {
							foreach (explode(';', $cellidlist) as $cellid) {
								$cellid = trim($cellid);
								if ($cellid === '') continue;
								if (!is_numeric($cellid)) continue;

								$site = (int) floor($cellid/10);

								if ($net == 'umts') {
									$rnc = $rncs[$i];
									if (!is_numeric($rnc)) continue;
									// $cellid += $rnc*65536;
								}

								if ($net == 'lte') {
									if (!is_numeric($eNB)) continue;
									$site = $eNB;
									// $cellid += $eNB*256;
								}

								if ($mnc == 30 && $net != 'lte')
									$site = -$cellid;

								$siteids[$mcc][$mnc][$site] = $id;
								$cellids[$mcc][$mnc][$site][$net][$cellid] = $id;
								$cellids_by_node[$id][$mcc][$mnc][$site][$net][] = $cellid;
							}
						}
					}
				}
			}
		}
	}

	$pg = pg_connect(PG_CONNECTION_STRING);

	$where = array();
	$whereSite = array();
	$whereBBOX = '1=1';
	if ($osm->bbox && !isset($params['nobbox'])) {
		$whereBBOX = sprintf("g && ST_Envelope(ST_SetSRID(ST_GeomFromText(
			'LINESTRING(%1.7f %1.7f, %1.7f %1.7f)'), 4326))",
			$osm->bbox[0], $osm->bbox[1], $osm->bbox[2], $osm->bbox[3]);
	}

	if (is_numeric($params['mnc']))
		$whereSite[] = sprintf('mnc=%d', $params['mnc']);

	if (is_numeric($params['site']))
		$whereSite[] = sprintf('site=%d', $params['site']);

	if ($params['noirregular'])
		$whereSite[] = 'site > 0';

	if (is_numeric($params['cell']))
		$where[] = sprintf('cell=%d', $params['cell']);

	if (is_numeric($params['cellid']))
		$where[] = sprintf('cellid=%d', $params['cellid']);

	if (is_numeric($params['lac']))
		$where[] = sprintf('lac=%d', $params['lac']);

	if (in_array($params['net'], $nets))
		$whereSite[] = sprintf("net='%s'",
			pg_escape_string($pg, $params['net']));

	if (in_array($params['radio'], $nets))
		$where[] = sprintf("radio='%s'",
			pg_escape_string($pg, $params['radio']));

	if (!count($where)) $where[] = '1=1';
	if (!count($whereSite)) $whereSite[] = '1=1';

	if (!isset($params['noraw'])) {
		if (isset($params['norawtagged'])) {
			$norawtagged = "AND NOT EXISTS (
				SELECT * FROM known.sites
				WHERE sites.mcc = measurements.mcc
				AND sites.mnc = measurements.mnc
				AND sites.site = measurements.site
			);";
		} else {
			$norawtagged = '';
		}

		if (!isset($params['norawoutside'])) {
			$sql = sprintf("SELECT * FROM measurements
				INNER JOIN (
					SELECT mcc, mnc, site
					FROM cells
					WHERE %s
					UNION
					SELECT mcc, mnc, site
					FROM sites
					WHERE %s
					UNION
					SELECT mcc, mnc, site
					FROM known.cells
					WHERE %s
				) AS sites
				ON measurements.mcc=sites.mcc
				AND measurements.mnc=sites.mnc
				AND measurements.site=sites.site
				AND net IS NOT NULL
				AND error IS NULL
				AND %s
				AND %s
				%s",
					$whereBBOX,
					$whereBBOX,
					$whereBBOX,
					implode(' AND ', $where),
					implode(' AND ', $whereSite),
					$norawtagged
				);
		} else {
			$sql = sprintf("SELECT * FROM measurements
				WHERE %s
				AND %s
				AND %s
				AND net IS NOT NULL
				AND error IS NULL
				%s",
					implode(' AND ', $where),
					implode(' AND ', $whereSite),
					$whereBBOX,
					$norawtagged
				);
		}
		$result = pg_query($sql);
		while ($row = pg_fetch_assoc($result)) {

			$tags = $row;
			$tags['cellid'] = $tags['cell'];

			$tags['measured'] = formatDateTime($tags['measured']);
			$tags['created'] = formatDateTime($tags['created']);
			$tags['mnc'] = sprintf('%02d', $tags['mnc']);

			if ($tags['mnc'] != $tags['mnco'])
				$tags['mnc:original'] = sprintf('%02d', $tags['mnco']);

			unset($tags['g']);
			unset($tags['lat']);
			unset($tags['lon']);
			unset($tags['id']);
			unset($tags['signal']);
			unset($tags['cell']);
			unset($tags['mnco']);
			unset($tags['cid']);

			$net = array_search($tags['net'], $nets); // ez így szám lesz
			$lat = $row['lat'];
			$lon = $row['lon'];

			if (isset($cellids
				[$tags['mcc']]
				[$tags['mnc']]
				[$tags['site']]
				[$tags['net']]
				[$tags['cellid']])) {
					$tags['tagged'] = 'yes';

			} else if (isset($siteids
				[$tags['mcc']]
				[$tags['mnc']]
				[$tags['site']])) {
					$tags['tagged'] = 'auto';
			}

			$node = new Node($lat, $lon);
			$node->tags = $tags;
			$node->id = sprintf('9%012d', $row['id']);
			$node->attr = array('version' => '1');

			$id = sprintf('%03d %02d %09d %d',
				$tags['mcc'],
				$tags['mnc'],
				$tags['cellid'],
				$net
			);

			if (isset($params['norawtagged']) &&
				@$node->tags['tagged'] == 'yes') {
			} else if (isset($params['norawautotagged']) &&
				@$node->tags['tagged'] == 'auto') {
			} else {
				$osm->outputNode($node);
			}
			unset($node);
		}
	}

	$cells = array();
	$cellNodesBySite = array();
	$sql = sprintf("SELECT * FROM (
		SELECT cells.* FROM cells
		INNER JOIN (
			SELECT mcc, mnc, site
			FROM cells
			WHERE %s
			UNION
			SELECT mcc, mnc, site
			FROM sites
			WHERE %s
			UNION
			SELECT mcc, mnc, site
			FROM known.cells
			WHERE %s
		) AS bbox
		ON cells.mcc = bbox.mcc
		AND cells.mnc = bbox.mnc
		AND cells.site = bbox.site
		) AS cells WHERE %s",
			$whereBBOX, $whereBBOX, $whereBBOX, implode(' AND ', $whereSite)
	);

	$result = pg_query($sql);
	while ($cell = pg_fetch_assoc($result)) {

		$cell['mnc'] = sprintf('%02d', $cell['mnc']);

		if ($cell['net'] == 'lte') $cell['enb'] = $cell['site'];
		$net = array_search($cell['net'], $nets); // ez így szám lesz
		$id = sprintf('%03d %02d %09d %d',
			$cell['mcc'],
			$cell['mnc'],
			$cell['cell'],
			$net
		);

		$node = new Node($cell['lat'], $cell['lon']);
		$node->tags = array(
			'[count]' => $cell['measurements'],
			'rssi' => $cell['rssi'],
			'mcc' => $cell['mcc'],
			'mnc' => $cell['mnc'],
			'lac' => $cell['lac'],
			'psc' => $cell['psc'],
			'cellid' => $cell['cell'],
			'rnc' => $cell['rnc'],
			'enb' => $cell['enb'],
			'net' => $cell['net'],
			'site' => $cell['site'],
			'tagged' => $cell['tagged'],
		);
		$node->id = '9' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';
		$cellNodesBySite[$cell['mcc']][$cell['mnc']][$cell['site']][] = $node;
	}

	$sites = array();
	$multiple_nodeids = array();
	$sql = sprintf("SELECT * FROM (
		SELECT sites.* FROM sites
		INNER JOIN (
			SELECT mcc, mnc, site
			FROM cells
			WHERE %s
			UNION
			SELECT mcc, mnc, site
			FROM sites
			WHERE %s
			UNION
			SELECT mcc, mnc, site
			FROM known.cells
			WHERE %s
		) AS bbox
		ON sites.mcc = bbox.mcc
		AND sites.mnc = bbox.mnc
		AND sites.site = bbox.site
		) AS sites WHERE %s",
			$whereBBOX, $whereBBOX, $whereBBOX, implode(' AND ', $whereSite)
	);
	$result = pg_query($sql);
	while ($site = pg_fetch_assoc($result)) {

		$site['mnc'] = sprintf('%02d', $site['mnc']);

		if ($site['site'] < 0) {
			$group = 1;
		} else {
			$group = 0;
		}

		$id = sprintf('%03d %02d %05d %d',
			$site['mcc'],
			$site['mnc'],
			abs($site['site']),
			$group
		);

		$node = new Node($site['lat'], $site['lon']);
		$node->id = '8' . str_replace(' ', '', $id);
		$node->attr['version'] = '9999';

		$nodeid = null;
		foreach ($cellNodesBySite[$site['mcc']][$site['mnc']][$site['site']] as $cell) {

			$node->tags['MCC'] = $cell->tags['mcc'];
			$node->tags['MNC'] = $cell->tags['mnc'];
			$node->tags['operator'] = $operators[$node->tags['MNC']];
			$net = $cell->tags['net'];

			$_nodeid = @$cellids
				[$node->tags['MCC']]
				[$node->tags['MNC']]
				[$cell->tags['site']]
				[$net]
				[$cell->tags['cellid']];

			$node->tags[$net . ':cellid'][] = $cell->tags['cellid'];
			$node->tags[$net . ':PSC'][] = $cell->tags['psc'] ? $cell->tags['psc'] : 'fixme';
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
		$cells_at_node = null;
		if ($nodeid !== null) {
			$node->id = getNodeId($nodeid);
			$display[$nodeid] = true;
			// a node megy a levesbe, nem tesszük a térképre

			$cells_at_node = $cellids_by_node
				[$nodeid]
				[$site['mcc']]
				[$site['mnc']]
				[$site['site']];

		} else if (isset($params['nosingle']) &&
			count($cellNodesBySite[$site['mcc']][$site['mnc']][$site['site']]) == 1) {
			// csak egy cella van a helyszínen
			// és nincs hozzá meglevő pont
			// és nem kérte az ilyeneket
			// ezért nem készítünk neki feltételezett helyszínt
			continue;

		} else {
			foreach ($nets as $net) {
				if (isset($node->tags[$net . ':PSC']))
					if (implode(';', array_unique($node->tags[$net . ':PSC'])) == 'fixme')
						unset($node->tags[$net . ':PSC']);

				if (isset($node->tags[$net . ':cellid'])) {
					if (isset($node->tags[$net . ':PSC'])) {
						array_multisort(
							$node->tags[$net . ':cellid'],
							$node->tags[$net . ':PSC'],
							SORT_NUMERIC);
						} else {
							sort($node->tags[$net . ':cellid'], SORT_NUMERIC);
					}
				}
			}
			foreach ($node->tags as $k => $v) {
				if (is_array($v)) {
					if (!preg_match('/(:cellid|:PSC)$/', $k))
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
		foreach ($cellNodesBySite[$site['mcc']][$site['mnc']][$site['site']] as $cell) {

			$way = new Way;
			$way->id = $cell->id;
			$way->attr['version'] = '9999';
			$way->addNode($node);
			$way->addNode($cell);
			$way->tags = $cell->tags;

			// ha meglevő node, akkor figyelmeztetjük az új cellákra
			if ($nodeid !== null) {
				$net = $cell->tags['net'];
				$key = 'cellid';
				if (!isset($cells_at_node[$net]) ||
					!in_array($cell->tags[$key], $cells_at_node[$net])) {
					$way->tags['fixme'] = 'new cell';
					$newcells[$net][] = $cell->tags;
					if (!isset($params['noautotag'])) {
						$way->tags['tagged'] = 'auto';
						$cell->tags['tagged'] = 'auto';
					}
				} else {
					$way->tags['tagged'] = 'yes';
					$cell->tags['tagged'] = 'yes';
				}
			}
			if (!(isset($way->tags['tagged']) && isset($params['nocelltagged']))) {
				$osm->nodes[] = $cell;
				$osm->ways[] = $way;
			}
		}

		if (!isset($params['noautotag']) &&
			$osm->inBBOX($osmsites[$nodeid]['lat'], $osmsites[$nodeid]['lon'])) try {
			// ha a torony a befoglaló téglalapon belül van
			// akkor egyúttal hozzá is írjuk a ponthoz
			$tags = $osmsites[$nodeid]['tags'];
			$multi = new MultiTag($tags, 'MNC', $node->tags['MNC']);

			foreach ($newcells as $net => $cells) {
				$cellidlist = $multi->getValue($net . ':cellid');

				if (!preg_match('/^[0-9;]+$/', $cellidlist)) {
					$list = array();
				} else {
					$list = explode(';', $cellidlist);
				}

				$multi->setValueIfEmpty($net . ':LAC', $cells[0]['lac']);

				if ($net == 'gsm') {
					// nincs több

				} else if ($net == 'umts') {
					$multi->setValueIfEmpty($net . ':RNC', $cells[0]['rnc']);
				} else if ($net == 'lte') {
					$multi->setValueIfEmpty($net . ':eNB', $cells[0]['enb']);
				}

				// hozzáadjuk a cellákat
				foreach ($cells as $cell)
					$list[] = $cell['cellid'];

				sort($list);
				$list = array_unique($list, SORT_NUMERIC);
				$cellidlist = implode(';', $list);
				$multi->setValue($net . ':cellid', $cellidlist);
			}

			if ($newtags = $multi->getTags());
			if ($newtags != $tags) {
				$osmsites[$nodeid]['action'] = 'modify';
				$osmsites[$nodeid]['tags'] = $newtags;
			}

		} catch (Exception $e) {
			$osmsites[$nodeid]['tags']['warning'] = $e->getMessage();
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
		$element = $osmsites[$id];
		$osm->addElement($element);
	}

	$osm->outputData();
	$osm->footer();

} catch (Exception $e) {

	header('HTTP/1.1 400 Bad Request');
	echo $e->getMessage();

}

function cellid ($node1, $node2) {
	if ($node1->tags['cellid']>$node2->tags['cellid']) return 1;
	if ($node1->tags['cellid']<$node2->tags['cellid']) return -1;
	return 0;
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

	global $osmsites, $list;
	$element = $osmsites[$id];

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
