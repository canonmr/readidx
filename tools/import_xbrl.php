<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Script ini hanya boleh dijalankan dari CLI." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../src/backend/autoload.php';

use Readidx\Backend\Database\Connection;
use Readidx\Backend\Repository\ReportRepository;
use Readidx\Backend\Service\ReportService;

$options = getopt('', ['file:', 'ticker:', 'name:', 'year:', 'quarter:']);

$required = ['file', 'ticker', 'name', 'year', 'quarter'];
foreach ($required as $key) {
    if (empty($options[$key])) {
        fwrite(STDERR, "Parameter --{$key} wajib diisi." . PHP_EOL);
        exit(1);
    }
}

$config = require __DIR__ . '/../src/backend/config/database.php';
$connection = new Connection($config);
$repository = new ReportRepository($connection->getPdo());
$service = new ReportService($repository);

try {
    $result = $service->importReport(
        $options['ticker'],
        $options['name'],
        (string) $options['year'],
        (string) $options['quarter'],
        $options['file'],
        basename($options['file'])
    );

    fwrite(STDOUT, 'Berhasil mengimpor ' . $result['line_count'] . ' baris.' . PHP_EOL);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Gagal mengimpor data: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
