<?php

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Script ini hanya boleh dijalankan dari CLI." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../src/backend/autoload.php';

use Readidx\Backend\Database\Connection;

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
$pdo = $connection->getPdo();
$pdo->beginTransaction();

try {
    $companyId = upsertCompany($pdo, $options['ticker'], $options['name']);
    $reportId = createReport(
        $pdo,
        $companyId,
        (int) $options['year'],
        (int) $options['quarter'],
        basename($options['file'])
    );

    $facts = parseFactsFromInlineXbrl($options['file']);

    $order = 0;
    $insert = $pdo->prepare(
        'INSERT INTO financial_lines (report_id, line_item, value, unit, display_order) VALUES (:report_id, :line_item, :value, :unit, :display_order)'
    );

    foreach ($facts as $fact) {
        $insert->execute([
            ':report_id' => $reportId,
            ':line_item' => $fact['label'],
            ':value' => $fact['value'],
            ':unit' => $fact['unit'],
            ':display_order' => $order++,
        ]);
    }

    $pdo->commit();
    fwrite(STDOUT, "Berhasil mengimpor " . count($facts) . " baris." . PHP_EOL);
} catch (\Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Gagal mengimpor data: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function upsertCompany(\PDO $pdo, string $ticker, string $name): int
{
    $stmt = $pdo->prepare('SELECT id FROM companies WHERE ticker = :ticker');
    $stmt->execute([':ticker' => strtoupper($ticker)]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $update = $pdo->prepare('UPDATE companies SET name = :name WHERE id = :id');
        $update->execute([':name' => $name, ':id' => $existing]);
        return (int) $existing;
    }

    $insert = $pdo->prepare('INSERT INTO companies (ticker, name) VALUES (:ticker, :name)');
    $insert->execute([
        ':ticker' => strtoupper($ticker),
        ':name' => $name,
    ]);

    return (int) $pdo->lastInsertId();
}

function createReport(\PDO $pdo, int $companyId, int $year, int $quarter, string $fileName): int
{
    $stmt = $pdo->prepare('SELECT id FROM financial_reports WHERE company_id = :company_id AND fiscal_year = :year AND fiscal_quarter = :quarter');
    $stmt->execute([
        ':company_id' => $companyId,
        ':year' => $year,
        ':quarter' => $quarter,
    ]);

    if ($existing = $stmt->fetchColumn()) {
        $pdo->prepare('DELETE FROM financial_lines WHERE report_id = :report_id')->execute([':report_id' => $existing]);
        $pdo->prepare('UPDATE financial_reports SET source_file = :source_file WHERE id = :id')->execute([
            ':source_file' => $fileName,
            ':id' => $existing,
        ]);
        return (int) $existing;
    }

    $insert = $pdo->prepare(
        'INSERT INTO financial_reports (company_id, fiscal_year, fiscal_quarter, source_file) VALUES (:company_id, :year, :quarter, :source_file)'
    );

    $insert->execute([
        ':company_id' => $companyId,
        ':year' => $year,
        ':quarter' => $quarter,
        ':source_file' => $fileName,
    ]);

    return (int) $pdo->lastInsertId();
}

function parseFactsFromInlineXbrl(string $zipPath): array
{
    if (!is_file($zipPath)) {
        throw new \InvalidArgumentException('Berkas tidak ditemukan: ' . $zipPath);
    }

    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new \RuntimeException('Tidak dapat membuka arsip ZIP.');
    }

    $facts = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!preg_match('/\.(xhtml|html)$/i', $name)) {
            continue;
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            continue;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
        $xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');

        foreach ($xpath->query('//ix:nonFraction|//ix:nonNumeric') as $fact) {
            /** @var \DOMElement $fact */
            $label = trim($fact->getAttribute('name'));
            if ($label === '') {
                continue;
            }

            $unit = $fact->getAttribute('unitRef') ?: 'IDR';
            $value = $fact->nodeValue;
            if ($fact->hasAttribute('decimals') && is_numeric($value)) {
                $decimals = (int) $fact->getAttribute('decimals');
                if ($decimals >= 0) {
                    $value = round((float) $value, $decimals);
                }
            }

            $facts[] = [
                'label' => $label,
                'value' => (float) $value,
                'unit' => $unit,
            ];
        }
    }

    $zip->close();

    return $facts;
}
