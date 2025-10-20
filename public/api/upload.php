<?php

declare(strict_types=1);

use Readidx\Backend\Database\Connection;
use Readidx\Backend\Repository\ReportRepository;
use Readidx\Backend\Service\ReportService;

require_once __DIR__ . '/../../src/backend/autoload.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Metode tidak diizinkan.',
    ]);
    return;
}

$config = require __DIR__ . '/../../src/backend/config/database.php';

try {
    $connection = new Connection($config);
    $repository = new ReportRepository($connection->getPdo());
    $service = new ReportService($repository);

    $ticker = $_POST['ticker'] ?? '';
    $companyName = $_POST['company_name'] ?? '';
    $year = $_POST['year'] ?? '';
    $quarter = $_POST['quarter'] ?? '';

    if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
        throw new \InvalidArgumentException('Berkas ZIP wajib diunggah.');
    }

    $fileInfo = $_FILES['report_file'];

    $result = $service->importReport(
        $ticker,
        $companyName,
        (string) $year,
        (string) $quarter,
        $fileInfo['tmp_name'],
        $fileInfo['name'] ?? null
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Laporan berhasil diunggah dan diproses.',
        'data' => $result,
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
