<?php

namespace Readidx\Backend\Repository;

use PDO;
use Throwable;

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

    public function listReports(): array
    {
        $sql = <<<SQL
            SELECT r.id,
                   c.ticker,
                   c.name AS company_name,
                   r.fiscal_year,
                   r.fiscal_quarter,
                   r.source_file,
                   r.created_at,
                   COALESCE(MAX(l.created_at), r.created_at) AS last_updated_at,
                   COUNT(l.id) AS line_count
            FROM financial_reports r
            INNER JOIN companies c ON c.id = r.company_id
            LEFT JOIN financial_lines l ON l.report_id = r.id
            GROUP BY r.id, c.ticker, c.name, r.fiscal_year, r.fiscal_quarter, r.source_file, r.created_at
            ORDER BY r.fiscal_year DESC, r.fiscal_quarter DESC, c.ticker ASC
        SQL;

        $statement = $this->pdo->query($sql);
        $rows = $statement->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'company' => [
                    'ticker' => $row['ticker'],
                    'name' => $row['company_name'],
                ],
                'fiscal_year' => (int) $row['fiscal_year'],
                'fiscal_quarter' => (int) $row['fiscal_quarter'],
                'line_count' => (int) $row['line_count'],
                'source_file' => $row['source_file'],
                'created_at' => $row['created_at'],
                'last_updated_at' => $row['last_updated_at'],
            ];
        }, $rows);
    }

    public function upsertCompany(string $ticker, string $name): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM companies WHERE ticker = :ticker');
        $statement->execute([':ticker' => $ticker]);
        $existingId = $statement->fetchColumn();

        if ($existingId) {
            $update = $this->pdo->prepare('UPDATE companies SET name = :name WHERE id = :id');
            $update->execute([
                ':name' => $name,
                ':id' => $existingId,
            ]);

            return (int) $existingId;
        }

        $insert = $this->pdo->prepare('INSERT INTO companies (ticker, name) VALUES (:ticker, :name)');
        $insert->execute([
            ':ticker' => $ticker,
            ':name' => $name,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createOrUpdateReport(int $companyId, int $year, int $quarter, string $sourceFile): int
    {
        $select = $this->pdo->prepare('SELECT id FROM financial_reports WHERE company_id = :company_id AND fiscal_year = :year AND fiscal_quarter = :quarter');
        $select->execute([
            ':company_id' => $companyId,
            ':year' => $year,
            ':quarter' => $quarter,
        ]);

        $existingId = $select->fetchColumn();

        if ($existingId) {
            $update = $this->pdo->prepare('UPDATE financial_reports SET source_file = :source_file WHERE id = :id');
            $update->execute([
                ':source_file' => $sourceFile,
                ':id' => $existingId,
            ]);

            return (int) $existingId;
        }

        $insert = $this->pdo->prepare('INSERT INTO financial_reports (company_id, fiscal_year, fiscal_quarter, source_file) VALUES (:company_id, :year, :quarter, :source_file)');
        $insert->execute([
            ':company_id' => $companyId,
            ':year' => $year,
            ':quarter' => $quarter,
            ':source_file' => $sourceFile,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function replaceReportLines(int $reportId, array $lines): void
    {
        $delete = $this->pdo->prepare('DELETE FROM financial_lines WHERE report_id = :report_id');
        $delete->execute([':report_id' => $reportId]);

        if ($lines === []) {
            return;
        }

        $insert = $this->pdo->prepare('INSERT INTO financial_lines (report_id, line_item, value, unit, display_order) VALUES (:report_id, :line_item, :value, :unit, :display_order)');

        $order = 0;
        foreach ($lines as $line) {
            $insert->execute([
                ':report_id' => $reportId,
                ':line_item' => $line['line_item'],
                ':value' => $line['value'],
                ':unit' => $line['unit'],
                ':display_order' => $order++,
            ]);
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws Throwable
     */
    public function transaction(callable $callback)
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
