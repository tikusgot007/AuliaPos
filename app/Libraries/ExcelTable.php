<?php

namespace App\Libraries;

/**
 * Membangun tabel HTML untuk ekspor Excel `.xls`. Excel membuka tabel HTML
 * sebagai spreadsheet dengan kolom yang benar, tanpa bergantung pada pemisah
 * daftar/locale Windows — masalah yang membuat CSV `;` terbaca satu kolom.
 *
 * Dipisah dari controller supaya bisa dites tanpa database.
 *
 * @internal
 */
final class ExcelTable
{
    /**
     * @param array<int, string>             $headers
     * @param array<int, array<int, mixed>>  $rows
     */
    public static function render(array $headers, array $rows): string
    {
        $html = '<html><head><meta charset="UTF-8"></head><body><table border="1"><tr>';

        foreach ($headers as $header) {
            $html .= '<th>' . self::escape($header) . '</th>';
        }

        $html .= '</tr>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . self::escape($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table></body></html>';
    }

    private static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
