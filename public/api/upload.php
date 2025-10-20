<?php

declare(strict_types=1);

use Readidx\Backend\Database\Connection;
use Readidx\Backend\Repository\ReportRepository;
use Readidx\Backend\Service\ReportService;
use Readidx\Backend\Support\Logger;

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

$ticker = $_POST['ticker'] ?? null;
$companyName = $_POST['company_name'] ?? null;
$year = $_POST['year'] ?? null;
$quarter = $_POST['quarter'] ?? null;

try {
    $connection = new Connection($config);
    $repository = new ReportRepository($connection->getPdo());
    $service = new ReportService($repository);

    $ticker = $ticker ?? '';
    $companyName = $companyName ?? '';
    $year = $year ?? '';
    $quarter = $quarter ?? '';

    if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
        throw new \InvalidArgumentException('Berkas ZIP wajib diunggah.');
    }

    $fileInfo = $_FILES['report_file'];

    Logger::info('Report upload request received', [
        'ticker' => $ticker,
        'company_name' => $companyName,
        'year' => $year,
        'quarter' => $quarter,
        'file' => [
            'name' => $fileInfo['name'] ?? null,
            'size' => $fileInfo['size'] ?? null,
            'type' => $fileInfo['type'] ?? null,
        ],
    ]);

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

    Logger::info('Report upload processed successfully', [
        'ticker' => $result['company']['ticker'] ?? $ticker,
        'year' => $result['fiscal_year'] ?? $year,
        'quarter' => $result['fiscal_quarter'] ?? $quarter,
        'line_count' => $result['line_count'] ?? null,
        'source_file' => $result['source_file'] ?? null,
    ]);
} catch (\InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => $exception->getMessage(),
    ]);

    Logger::info('Report upload rejected by validation', [
        'ticker' => $ticker ?? null,
        'year' => $year ?? null,
        'quarter' => $quarter ?? null,
        'error' => $exception->getMessage(),
    ]);
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Terjadi kesalahan pada server.',
    ]);

    Logger::error('Report upload failed with unexpected error', [
        'ticker' => $ticker ?? null,
        'year' => $year ?? null,
        'quarter' => $quarter ?? null,
        'exception' => [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ],
    ]);
}
