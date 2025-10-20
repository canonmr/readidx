<?php

namespace Readidx\Backend\Service;

use InvalidArgumentException;
use Readidx\Backend\Repository\ReportRepository;

class ReportService
{
    private ReportRepository $repository;

    public function __construct(ReportRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getReport(string $ticker, string $year, string $quarter): array
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

        $report = $this->repository->findByTickerYearQuarter($ticker, $yearInt, $quarterInt);
        if (!$report) {
            throw new InvalidArgumentException('Data laporan tidak ditemukan untuk parameter yang diberikan.');
        }

        return $report;
    }
}
