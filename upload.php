#!/usr/bin/env php
<?php

require_once('vendor/autoload.php');
require_once('include/postgresql.conf.php');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, $severity, $severity, $file, $line);
});

use League\Csv\Reader;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

//load the CSV document from a file path
$csv = Reader::createFromPath('sample.csv', 'r');
$csv->setHeaderOffset(0);

$header = $csv->getHeader(); // returns the CSV header record
$records = $csv->getRecords(); // returns all the CSV records as an Iterator object

$pdo = new PDO(getenv('DSN'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$columns = [
    'mcc',
    'mnc',
    'lac',
    'cellid',
    'lon',
    'lat',
    'signal',
    'created',
    'measured',
];

$columnlist = implode(', ', $columns);
$placeholders = implode(', ', preg_filter('/^/', ':', $columns));
$sql = "INSERT INTO measurements ($columnlist) VALUES ($placeholders)";
$statement = $pdo->prepare($sql);

$pdo->query('BEGIN');

foreach ($records as $record) {
    $record['cellid'] = $record['cell_id'];
    $record['signal'] = $record['dbm'];
    $record['created'] = time();
    $record['measured'] = strtotime($record['measured_at']);
    $record = array_intersect_key($record, array_flip($columns));
    $statement->execute($record);
    echo '.';
}
