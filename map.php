<?php 

/**
 * térképi objektumok letöltése osm fájlként
 *
 * 2015.02.01 előtt csak hitelesítéssel és jogosultsággal működött
 * az ODbL nyitással ezt kikapcsoltam
 *
 * közvetlenül a MySQL adatbázist olvassa
 * felhasznál php összetevőket is a turistautak.hu-ból
 * például a beállításokat, típusdefiníciós tömböket
 *
 * @todo ötletek
 * geometriai index használata (sajnos a táblák nem MyISAM-ok)
 * objektum-orientált megvalósítás, szétválasztás részekre
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2014.06.09
 *
 */

include_once('include/postgresql.conf.php');

ini_set('display_errors', 0);
ini_set('max_execution_time', 1800);
ini_set('memory_limit', '512M');
mb_internal_encoding('UTF-8');

try {

	$osm = new OSM;
	$osm->bbox(@$_REQUEST['bbox']);

	if ($osm->bbox) {
		$where = sprintf("WHERE g && ST_Envelope(ST_GeomFromText(
			'LINESTRING(%1.7f %1.7f, %1.7f %1.7f)'))",
			$osm->bbox[0], $osm->bbox[1], $osm->bbox[2], $osm->bbox[3]);
	} else {
		$where = '';
	}

	$sql = sprintf("SELECT *
		FROM measurements
		%s", $where);

	$pg = pg_connect(PG_CONNECTION_STRING);
	$result = pg_query($sql);

	while ($row = pg_fetch_assoc($result)) {
			
		$tags = $row;
		unset($tags['g']);
		unset($tags['lat']);
		unset($tags['lon']);
		unset($tags['id']);
		unset($tags['created']);
		unset($tags['signal']);

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

		$node = new Node($row['lat'], $row['lon']);
		$node->tags = $tags;
		$node->id = $row['id'];
		$node->attr = array('version' => '1');
		$osm->nodes[] = $node;
	}

	// kiírjuk osm fájlba
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
