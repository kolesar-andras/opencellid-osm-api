<?php

/**
 * OpenCellID mérések letöltése osm fájlként
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2015.02.28
 *
 */

class Overpass {

	static function query ($bbox) {

		$bboxstr = sprintf('%1.7f,%1.7f,%1.7f,%1.7f',
			$bbox[1], $bbox[0], $bbox[3], $bbox[2]);

		$query = sprintf('[out:json][timeout:25];
		(
		  node["communication:mobile_phone"](%1$s);
		  way["communication:mobile_phone"](%1$s);
		  relation["communication:mobile_phone"](%1$s);

		  node["man_made"="mast"](%1$s);
		  way["man_made"="mast"](%1$s);
		  relation["man_made"="mast"](%1$s);

		  node["man_made"="tower"](%1$s);
		  way["man_made"="tower"](%1$s);
		  relation["man_made"="tower"](%1$s);

		  node["man_made"="water_tower"](%1$s);
		  way["man_made"="water_tower"](%1$s);
		  relation["man_made"="water_tower"](%1$s);

		  node["building"="church"](%1$s);
		  way["building"="church"](%1$s);
		  relation["building"="church"](%1$s);

		  node["amenity"="place_of_worship"](%1$s);
		  way["amenity"="place_of_worship"](%1$s);
		  relation["amenity"="place_of_worship"](%1$s);
		);
		out meta;
		>;
		out meta qt;', $bboxstr);

		$postdata = http_build_query(
			array(
				'data' => $query,
			)
		);

		$opts = array('http' =>
			array(
				'method'  => 'POST',
				'header'  => 'Content-type: application/x-www-form-urlencoded; charset=UTF-8'. "\n" .
					'Content-Length: ' . strlen($postdata) . "\n",
				'content' => $postdata,
			)
		);

		$context = stream_context_create($opts);

		$url = 'http://overpass-api.de/api/interpreter';
		$result = file_get_contents($url, false, $context);

		return $result;
	}
}
