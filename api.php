<?php

/**
 * segítség azoknak, akik böngészőben hívják be az oldalt
 *
 * @author Kolesár András <kolesar@turistautak.hu>
 * @since 2014.06.09
 *
 */

header('Content-type: text/plain; charset=UTF-8');

?>
JOSM-ben állítsd be ezt a címet.

r8800 és későbbi JOSM változatokban kapcsold be a szakértő módot:

Szerkesztés / Beállítások (F12)
[x] Szakértő mód (az ablak alján)

Ezután a Fájl / Letöltés Overpass API-ról (Alt-Shift-Down)
ablakban add meg az Overpass szervernél:

http://<?php echo $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']. "\n"; ?>

Régebbi JOSM változatokban az OSM API helyett használhatod:

Szerkesztés / Beállítások (F12)
második fül (OSM szerverhez kapcsolódás beállításai)
[ ] Alapértelmezett OSM szerver URL elérés használata (kapcsold ki)
OSM szerver url: http://<?php echo $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']. "\n"; ?>

Az adatok forrása: OpenCellID
http://opencellid.org/

Licenc: CC-BY-SA 3.0
http://creativecommons.org/licenses/by-sa/3.0/

--

Use this address in JOSM.

r8800 and later versions enable expert mode:

Edit / Preferences (F12)
[x] Expert mode

Use File / Download from Overpass API (Alt-Shift-Down)
set Overpass server to:

http://<?php echo $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']. "\n"; ?>

In older JOSM versions you should change OSM API url:

Edit / Preferences (F12)
second page (connection settings)
[ ] Use the default OSM server URL (uncheck it)
OSM server url: http://<?php echo $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']. "\n"; ?>

Data source: OpenCellID
http://opencellid.org/

Licence: CC-BY-SA 3.0
http://creativecommons.org/licenses/by-sa/3.0/
