<?php

namespace Readidx\Backend\Repository;

use PDO;

class ReportRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByTickerYearQuarter(string $ticker, int $year, int $quarter): array
    {
        $sql = <<<SQL
            SELECT r.id,
                   c.name AS company_name,
                   c.ticker,
                   r.fiscal_year,
                   r.fiscal_quarter,
                   l.line_item,
                   l.value,
                   l.unit
            FROM financial_reports r
            INNER JOIN companies c ON c.id = r.company_id
            INNER JOIN financial_lines l ON l.report_id = r.id
            WHERE c.ticker = :ticker
              AND r.fiscal_year = :year
              AND r.fiscal_quarter = :quarter
            ORDER BY l.display_order
        SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':ticker', $ticker);
        $statement->bindValue(':year', $year, PDO::PARAM_INT);
        $statement->bindValue(':quarter', $quarter, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        if (!$rows) {
            return [];
        }

        $report = [
            'company' => [
                'ticker' => $rows[0]['ticker'],
                'name' => $rows[0]['company_name'],
            ],
            'fiscal_year' => (int) $rows[0]['fiscal_year'],
            'fiscal_quarter' => (int) $rows[0]['fiscal_quarter'],
            'lines' => [],
        ];

        foreach ($rows as $row) {
            $report['lines'][] = [
                'line_item' => $row['line_item'],
                'value' => (float) $row['value'],
                'unit' => $row['unit'],
            ];
        }

        return $report;
    }
}
