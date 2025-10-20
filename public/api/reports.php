<?php

declare(strict_types=1);

use Readidx\Backend\Database\Connection;
use Readidx\Backend\Repository\ReportRepository;
use Readidx\Backend\Service\ReportService;

require_once __DIR__ . '/../../src/backend/autoload.php';

$config = require __DIR__ . '/../../src/backend/config/database.php';

header('Content-Type: application/json');

try {
    $connection = new Connection($config);
    $repository = new ReportRepository($connection->getPdo());
    $service = new ReportService($repository);

    $reports = $service->listReports();

    echo json_encode([
        'status' => 'success',
        'data' => $reports,
    ]);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tidak dapat mengambil daftar laporan.',
    ]);
}
