#!/usr/bin/env php
<?php

require_once('vendor/autoload.php');
require_once('include/postgresql.conf.php');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, $severity, $severity, $file, $line);
});

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$stream = STDIN;
$header = fgetcsv($stream);

$pdo = new PDO(getenv('DSN'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$columns = [
    'mcc',
    'mnc',
    'lac',
    'cellid',
    'radio',
    'lon',
    'lat',
    'signal',
    'speed',
    'direction',
    'created',
    'measured',
    'neighbour',
    'file',
];

$columnlist = implode(', ', $columns);
$placeholders = implode(', ', preg_filter('/^/', ':', $columns));
$sql = "INSERT INTO measurements ($columnlist) VALUES ($placeholders)";
$statement = $pdo->prepare($sql);

$pdo->query('BEGIN');

$lineNumber = 0;
$headerCount = count($header);
while (!feof($stream)) {
    $lineNumber++;
    $values = fgetcsv($stream);
    if ($values === false) break;
    if ($headerCount != count($values)) {
        echo 'warning: field count does not match headers in line #' . $lineNumber, PHP_EOL;
        continue;
    }
    $record = array_combine($header, $values);
    $record['cellid'] = $record['cell_id'];
    $record['signal'] = $record['dbm'];
    $record['radio'] = $record['net_type'];
    $record['direction'] = $record['bearing'];
    $record['neighbour'] = $record['neighboring'];
    $record['created'] = round(microtime(true)*1000);
    $record['file'] = -1;
    $record['measured'] = round((new DateTime($record['measured_at']))->format('U.u'))*1000;
    $record = array_intersect_key($record, array_flip($columns));
    $statement->execute($record);
}

$pdo->query('COMMIT');
