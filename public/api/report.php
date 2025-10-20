<?php

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

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_GET;
    }

    $ticker = $input['ticker'] ?? '';
    $year = $input['year'] ?? '';
    $quarter = $input['quarter'] ?? '';

    $report = $service->getReport($ticker, $year, $quarter);

    echo json_encode([
        'status' => 'success',
        'data' => $report,
    ]);
} catch (\InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => $exception->getMessage(),
    ]);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Terjadi kesalahan pada server.',
    ]);
}
