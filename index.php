<?php

/**
 * osm api OpenCellID mérésekhez
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2014.06.09
 *
 */

require_once('vendor/autoload.php');

if (isset($_SERVER['REQUEST_URI'])) {
	$uri = $_SERVER['REQUEST_URI'];
} else {
	$uri = @$argv[1];
}

if (preg_match('#^/opencellid/#', $uri))
	$uri = preg_replace('#^/opencellid#', '', $uri);

$url = parse_url($uri);

if (preg_match('#^/(api\.dev|api)-?([^/]*)/?([0-9]+\.[0-9]+/)?(.*)$#', $url['path'], $regs)) {
	$api = $regs[1];
	$mods = explode('-', $regs[2]);
	$version = $regs[3];
	$request = $regs[4];

} else {
	var_dump($url); exit;
	header('HTTP/1.0 404 Not Found');
	echo '404 Not Found';
	exit;
}

$params = @$_GET;

// a címben megadott paramétereket átalakítjuk igazi paraméterekké
foreach ($mods as $mod) {
	if (preg_match('/^([^=]+)=?(.*)$/', $mod, $regs)) {
		$key = urldecode($regs[1]);
		$value = urldecode($regs[2]);
		if (!isset($params[$key])) {
			$params[$key] = $value;
		} else if (is_array($params[$key])) {
			$params[$key][] = $value;
		} else {
			$params[$key] = array($params[$key], $value);
		}
	}
}

switch ($request) {

	case 'map':
	case 'interpreter':
		require_once($request . '.php');
		break;

	case '':
		require_once('api.php');
		break;

	default:
		if (!isset($params['noredirect'])) {
			$location = 'http://api.openstreetmap.org/api/' . $version . $request;
			if ($url['query'] != '') $location .= '?' . $url['query'];

			// header('HTTP/1.1 301 Moved Permanently');
			// header('HTTP/1.1 302 Found');
			// header('HTTP/1.1 303 See Other');
			header('HTTP/1.1 307 Temporary Redirect');
			header('Location: ' . $location);
			exit;

		} else {
			header('HTTP/1.0 404 Not Found');
			echo '404 Not Found';
		}
}
