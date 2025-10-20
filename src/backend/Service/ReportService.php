<?php

namespace Readidx\Backend\Service;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use Readidx\Backend\Repository\ReportRepository;
use RuntimeException;

class ReportService
{
    private ReportRepository $repository;

    public function __construct(ReportRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getReport(string $ticker, string $year, string $quarter): array
    {
        [$ticker, $yearInt, $quarterInt] = $this->validateTickerPeriod($ticker, $year, $quarter);

        $report = $this->repository->findByTickerYearQuarter($ticker, $yearInt, $quarterInt);
        if (!$report) {
            throw new InvalidArgumentException('Data laporan tidak ditemukan untuk parameter yang diberikan.');
        }

        return $report;
    }

    public function listReports(): array
    {
        return $this->repository->listReports();
    }

    public function importReport(
        string $ticker,
        string $companyName,
        string $year,
        string $quarter,
        string $sourcePath,
        ?string $originalFilename = null
    ): array {
        [$ticker, $yearInt, $quarterInt] = $this->validateTickerPeriod($ticker, $year, $quarter);

        $companyName = trim($companyName);
        if ($companyName === '') {
            throw new InvalidArgumentException('Nama perusahaan wajib diisi.');
        }

        if (!is_file($sourcePath)) {
            throw new InvalidArgumentException('Berkas unggahan tidak ditemukan.');
        }

        $storedFilePath = $this->storeZipArchive($ticker, $yearInt, $quarterInt, $sourcePath, $originalFilename);

        try {
            $facts = $this->parseFactsFromInlineXbrl($storedFilePath);
        } catch (\Throwable $exception) {
            @unlink($storedFilePath);
            throw $exception;
        }

        $lineCount = count($facts);

        $this->repository->transaction(function () use ($ticker, $companyName, $yearInt, $quarterInt, $storedFilePath, $facts): void {
            $companyId = $this->repository->upsertCompany($ticker, $companyName);
            $reportId = $this->repository->createOrUpdateReport($companyId, $yearInt, $quarterInt, basename($storedFilePath));
            $this->repository->replaceReportLines($reportId, $facts);
        });

        return [
            'company' => [
                'ticker' => $ticker,
                'name' => $companyName,
            ],
            'fiscal_year' => $yearInt,
            'fiscal_quarter' => $quarterInt,
            'line_count' => $lineCount,
            'source_file' => basename($storedFilePath),
        ];
    }

    /**
     * @return array{0:string,1:int,2:int}
     */
    private function validateTickerPeriod(string $ticker, string $year, string $quarter): array
    {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '') {
            throw new InvalidArgumentException('Ticker wajib diisi.');
        }

        if (!preg_match('/^[A-Z0-9\.\-]{3,10}$/', $ticker)) {
            throw new InvalidArgumentException('Ticker hanya boleh berisi huruf, angka, titik, atau strip.');
        }

        if (!ctype_digit($year)) {
            throw new InvalidArgumentException('Tahun harus berupa angka.');
        }

        $yearInt = (int) $year;
        if ($yearInt < 1990 || $yearInt > 2100) {
            throw new InvalidArgumentException('Tahun berada di luar rentang yang diizinkan.');
        }

        if (!ctype_digit($quarter)) {
            throw new InvalidArgumentException('Kuartal harus berupa angka antara 1 hingga 4.');
        }

        $quarterInt = (int) $quarter;
        if ($quarterInt < 1 || $quarterInt > 4) {
            throw new InvalidArgumentException('Kuartal harus bernilai 1 sampai 4.');
        }

        return [$ticker, $yearInt, $quarterInt];
    }

    private function storeZipArchive(string $ticker, int $year, int $quarter, string $sourcePath, ?string $originalFilename): string
    {
        $originalName = $originalFilename ?: basename($sourcePath);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension !== 'zip') {
            throw new InvalidArgumentException('Berkas yang diunggah harus berformat ZIP.');
        }

        $storageDir = dirname(__DIR__, 3) . '/instance_files';
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Tidak dapat membuat direktori penyimpanan.');
        }

        $timestamp = date('Ymd_His');
        $safeTicker = preg_replace('/[^A-Z0-9]/', '', $ticker) ?: $ticker;
        $targetName = sprintf('%s_%d_Q%d_%s.zip', $safeTicker, $year, $quarter, $timestamp);
        $targetPath = $storageDir . '/' . $targetName;

        if (is_uploaded_file($sourcePath)) {
            if (!move_uploaded_file($sourcePath, $targetPath)) {
                throw new RuntimeException('Gagal memindahkan berkas unggahan.');
            }
        } else {
            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException('Gagal menyalin berkas sumber.');
            }
        }

        return $targetPath;
    }

    private function parseFactsFromInlineXbrl(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Tidak dapat membuka arsip ZIP.');
        }

        $facts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            $extension = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['html', 'xhtml', 'xbrl', 'xml'], true)) {
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false || trim($content) === '') {
                continue;
            }

            $dom = new DOMDocument();
            $previousLibxmlState = libxml_use_internal_errors(true);

            $loaded = false;
            if ($extension === 'html' || $extension === 'xhtml') {
                $loaded = $dom->loadXML($content, LIBXML_NOERROR | LIBXML_NOWARNING);
                if (!$loaded) {
                    $loaded = $dom->loadHTML($content, LIBXML_NOERROR | LIBXML_NOWARNING);
                }
            } else {
                $loaded = $dom->loadXML($content, LIBXML_NOERROR | LIBXML_NOWARNING);
            }

            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);

            if (!$loaded) {
                continue;
            }

            if ($extension === 'html' || $extension === 'xhtml') {
                foreach ($this->extractInlineFacts($dom) as $factLine) {
                    $facts[] = $factLine;
                }
            } else {
                foreach ($this->extractInstanceFacts($dom) as $factLine) {
                    $facts[] = $factLine;
                }
            }
        }

        $zip->close();

        if ($facts === []) {
            throw new RuntimeException('Tidak ditemukan fakta keuangan pada arsip yang diunggah.');
        }

        return $facts;
    }

    /**
     * @return array<int, array{line_item:string,value:float,unit:string}>
     */
    private function extractInlineFacts(DOMDocument $dom): array
    {
        $facts = [];
        $xpath = new DOMXPath($dom);
        $factsQuery = '//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "nonfraction"'
            . ' or translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "nonnumeric"]';

        foreach ($xpath->query($factsQuery) as $fact) {
            if (!$fact instanceof \DOMElement) {
                continue;
            }

            $label = trim($fact->getAttribute('name'));
            if ($label === '') {
                continue;
            }

            $valueText = trim($fact->textContent ?? '');
            if ($valueText === '') {
                continue;
            }

            $unit = $fact->getAttribute('unitRef') ?: 'IDR';
            $numericValue = $this->parseNumericValue($valueText, $fact->getAttribute('decimals') ?: null);
            if ($numericValue === null) {
                continue;
            }

            $facts[] = [
                'line_item' => $label,
                'value' => $numericValue,
                'unit' => $unit,
            ];
        }

        return $facts;
    }

    /**
     * @return array<int, array{line_item:string,value:float,unit:string}>
     */
    private function extractInstanceFacts(DOMDocument $dom): array
    {
        $facts = [];
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');

        $query = '/*/*[namespace-uri() != "http://www.xbrl.org/2003/instance" and namespace-uri() != "http://www.xbrl.org/2003/linkbase"]';

        foreach ($xpath->query($query) as $fact) {
            if (!$fact instanceof \DOMElement) {
                continue;
            }

            $nilAttribute = $fact->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil');
            if ($nilAttribute !== '' && strtolower($nilAttribute) === 'true') {
                continue;
            }

            $label = trim($fact->localName ?? '');
            if ($label === '') {
                continue;
            }

            $valueText = trim($fact->textContent ?? '');
            if ($valueText === '') {
                continue;
            }

            $numericValue = $this->parseNumericValue($valueText, $fact->getAttribute('decimals') ?: null);
            if ($numericValue === null) {
                continue;
            }

            $unit = $fact->getAttribute('unitRef') ?: 'IDR';

            $facts[] = [
                'line_item' => $label,
                'value' => $numericValue,
                'unit' => $unit,
            ];
        }

        return $facts;
    }

    private function parseNumericValue(string $value, ?string $decimalsAttribute): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $isNegative = str_contains($trimmed, '(') && str_contains($trimmed, ')');
        $normalized = str_replace(["\xC2\xA0", " " ], '', $trimmed);

        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && !$hasDot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($hasComma && $hasDot) {
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        }

        $normalized = str_replace(['(', ')'], '', $normalized);

        if (substr_count($normalized, '.') > 1) {
            $normalized = str_replace('.', '', $normalized);
        }

        $normalized = preg_replace('/[^0-9\-\.]/', '', $normalized ?? '');
        if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        if ($isNegative && $normalized[0] !== '-') {
            $normalized = '-' . $normalized;
        }

        $number = (float) $normalized;

        if ($decimalsAttribute !== null && $decimalsAttribute !== '' && is_numeric($decimalsAttribute)) {
            $decimals = (int) $decimalsAttribute;
            if ($decimals >= 0) {
                $number = round($number, $decimals);
            }
        }

        return $number;
    }
}
