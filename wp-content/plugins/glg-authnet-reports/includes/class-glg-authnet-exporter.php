<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Streams report rows as a CSV or XLSX download.
 */
class GLG_Authnet_Exporter {

    /**
     * @param string   $format   'csv' or 'xlsx'.
     * @param string   $basename File name without extension.
     * @param string[] $headers
     * @param array[]  $rows     Rows keyed by header.
     * @param string[] $types    Header => 'money' | 'number' | 'text'.
     */
    public static function send($format, $basename, array $headers, array $rows, array $types) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();

        if ('xlsx' === $format) {
            self::send_xlsx($basename . '.xlsx', $headers, $rows, $types);
        } else {
            self::send_csv($basename . '.csv', $headers, $rows, $types);
        }

        exit;
    }

    private static function send_csv($filename, array $headers, array $rows, array $types) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // Byte order mark so Excel opens the file as UTF-8.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ',', '"', '');

        foreach ($rows as $row) {
            $line = array();
            foreach ($headers as $header) {
                $value = isset($row[$header]) ? (string) $row[$header] : '';
                $line[] = 'text' === $types[$header] ? self::csv_text($value) : $value;
            }
            fputcsv($out, $line, ',', '"', '');
        }

        fclose($out);
    }

    /**
     * Prefixes donor-entered text that a spreadsheet would run as a formula.
     */
    private static function csv_text($value) {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private static function send_xlsx($filename, array $headers, array $rows, array $types) {
        require_once GLG_AUTHNET_REPORTS_DIR . 'lib/SimpleXLSXGen.php';

        $data = array();
        foreach ($headers as $header) {
            // Headers are our own constants, so the library's <b> markup is safe here.
            $data[0][] = '<b>' . $header . '</b>';
        }

        foreach ($rows as $row) {
            $line = array();
            foreach ($headers as $header) {
                $value = isset($row[$header]) ? (string) $row[$header] : '';
                if ('text' === $types[$header] && '' !== $value) {
                    // "\0" marks a raw string: no tag parsing (so no injected formulas), no number guessing,
                    // so IDs and ZIP codes keep their leading zeros.
                    $value = "\0" . $value;
                }
                $line[] = $value;
            }
            $data[] = $line;
        }

        \Shuchkin\SimpleXLSXGen::fromArray($data, 'Transactions')
            ->freezePanes('A2')
            ->downloadAs($filename);
    }
}
