<?php
declare(strict_types=1);

/**
 * CSV export — hand-rolled, no dependencies.
 *
 * Public surface:
 *
 *   csv_send(string $filename, callable $writer): void
 *       Sends Content-Type + Content-Disposition headers, writes a UTF-8 BOM
 *       (so Excel opens accented names correctly), opens php://output, calls
 *       $writer($handle), closes, exits. The page MUST NOT have emitted any
 *       output (no HTML, no whitespace) before calling this.
 *
 *   csv_row(resource $handle, array $row): void
 *       Writes a single row through fputcsv with consistent escaping rules.
 *
 *   csv_section(resource $handle, string $title, array $header, array $rows): void
 *       Writes a labelled section: title line, header line, data rows, blank
 *       line separator. Used when an export needs multiple tables in one file.
 */

function csv_send(string $filename, callable $writer): void
{
    if (headers_sent($file, $line)) {
        throw new \RuntimeException("csv_send: headers already sent at $file:$line");
    }

    $safe_filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    if ($safe_filename === '' || $safe_filename === null) {
        $safe_filename = 'export.csv';
    }
    if (!str_ends_with(strtolower($safe_filename), '.csv')) {
        $safe_filename .= '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $handle = fopen('php://output', 'w');
    if ($handle === false) {
        throw new \RuntimeException('csv_send: failed to open php://output');
    }

    // UTF-8 BOM so Excel opens accented names correctly.
    fwrite($handle, "\xEF\xBB\xBF");

    $writer($handle);

    fclose($handle);
    exit;
}

function csv_row($handle, array $row): void
{
    $cleaned = array_map(static function ($v): string {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return (string) $v;
    }, $row);

    // Empty escape opts into RFC 4180 quoting (PHP 8.4+ deprecates the
    // legacy "\\" escape; "" disables it and matches what Excel expects).
    fputcsv($handle, $cleaned, ',', '"', '');
}

function csv_section($handle, string $title, array $header, array $rows): void
{
    csv_row($handle, [$title]);
    csv_row($handle, $header);
    foreach ($rows as $row) {
        csv_row($handle, $row);
    }
    csv_row($handle, []);
}
