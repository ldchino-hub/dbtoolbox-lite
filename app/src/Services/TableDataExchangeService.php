<?php
declare(strict_types=1);

namespace Navicat\Services;

use Navicat\Drivers\MySqlDriver;
use Navicat\Drivers\PostgresDriver;
use PDO;
use ZipArchive;

/**
 * Export/import table data in multiple formats (Navicat-style wizard).
 */
final class TableDataExchangeService
{
    private const CHUNK_SIZE = 10000;
    private const MAX_BUFFERED_ROWS = 500000;
    private const MAX_IMPORT_ROWS = 50000;

    /**
     * Export full table by paging through the driver (10k rows per query).
     * Streams text formats; buffers binary formats (xlsx/xls/dbf) up to MAX_BUFFERED_ROWS.
     *
     * @param array<string,mixed> $query  Router query params (orderBy, filter, sortKeys, …)
     * @param array<string,mixed> $options Export options (delimiter, …)
     */
    public function streamTableExport(
        object $driver,
        string $database,
        string $table,
        string $format,
        array $query = [],
        array $options = [],
    ): void {
        $format = strtolower(trim($format));
        $buffered = in_array($format, ['xlsx', 'xls', 'dbf'], true);
        $queryOpts = $this->buildExportQueryOptions($query);

        $offset = 0;
        $columns = null;
        $total = null;
        $exported = 0;
        $allRows = [];

        if (!$buffered) {
            $this->openStreamDownload($format, $table);
        }

        $jsonFirst = true;
        $xmlOpened = false;
        $htmlOpened = false;
        $sqlTableEsc = null;
        $sqlColList = null;

        while (true) {
            $page = $driver->queryPaginated($database, $table, array_merge($queryOpts, [
                'offset' => $offset,
                'limit' => self::CHUNK_SIZE,
            ]));
            if ($columns === null) {
                $columns = $page['columns'] ?? [];
                $total = $page['total'] ?? null;
                if ($buffered) {
                    // defer
                } elseif ($format === 'json') {
                    echo '[';
                } elseif ($format === 'xml') {
                    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                    echo '<table name="' . htmlspecialchars($table, ENT_XML1) . '">' . "\n";
                    $xmlOpened = true;
                } elseif ($format === 'html' || $format === 'htm') {
                    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($table) . '</title>';
                    echo '<style>table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:4px 8px;font-family:sans-serif;font-size:12px}</style></head><body><table><thead><tr>';
                    foreach ($columns as $col) {
                        echo '<th>' . htmlspecialchars($col) . '</th>';
                    }
                    echo '</tr></thead><tbody>';
                    $htmlOpened = true;
                } elseif ($format === 'csv' || $format === 'txt') {
                    $delimiter = $format === 'txt'
                        ? (string)($options['delimiter'] ?? "\t")
                        : (string)($options['delimiter'] ?? ',');
                    echo implode($delimiter, array_map(
                        fn(string $c) => $format === 'csv' ? $this->csvEscape($c, $delimiter) : $c,
                        $columns,
                    )) . "\n";
                } elseif ($format === 'sql') {
                    $sqlTableEsc = str_replace('`', '``', $table);
                    $sqlColList = implode(', ', array_map(
                        static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`',
                        $columns,
                    ));
                    if ($total !== null) {
                        echo '-- Exporting ' . $total . " row(s) from `{$sqlTableEsc}`\n";
                    }
                }
            }

            $rows = $page['rows'] ?? [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ($buffered) {
                    $allRows[] = $row;
                } else {
                    match ($format) {
                        'txt', 'csv' => $this->echoDelimitedRow($columns, $row, $format, $options),
                        'json' => $this->echoJsonRow($row, $jsonFirst),
                        'xml' => $this->echoXmlRow($columns, $row),
                        'html', 'htm' => $this->echoHtmlRow($columns, $row),
                        'sql' => $this->echoSqlRow($columns, $row, $sqlTableEsc ?? $table, $sqlColList ?? ''),
                        default => throw new \InvalidArgumentException('Unsupported streaming format: ' . $format),
                    };
                    if ($format === 'json') {
                        $jsonFirst = false;
                    }
                }
                $exported++;
                if ($buffered && $exported >= self::MAX_BUFFERED_ROWS) {
                    throw new \RuntimeException(
                        'Tabla demasiado grande para ' . strtoupper($format)
                        . ' (' . number_format(self::MAX_BUFFERED_ROWS) . '+ filas). Use CSV o TXT para exportación completa.',
                    );
                }
            }

            if (count($rows) < self::CHUNK_SIZE) {
                break;
            }
            $offset += self::CHUNK_SIZE;
            if (!$buffered && function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }

        if ($buffered) {
            $this->sendExport(['columns' => $columns ?? [], 'rows' => $allRows], $table, $format, $options);
            return;
        }

        if ($format === 'json') {
            echo ']';
        } elseif ($format === 'xml' && $xmlOpened) {
            echo "</table>\n";
        } elseif (($format === 'html' || $format === 'htm') && $htmlOpened) {
            echo '</tbody></table></body></html>';
        }
    }

    /** @param array<string,mixed> $query */
    private function buildExportQueryOptions(array $query): array
    {
        $exportSortKeys = null;
        $exportSortKeysRaw = $query['sortKeys'] ?? null;
        if ($exportSortKeysRaw) {
            $decoded = json_decode((string)$exportSortKeysRaw, true);
            if (is_array($decoded)) {
                $exportSortKeys = array_values(array_filter($decoded, fn($k) => isset($k['col'])));
            }
        }
        return [
            'orderBy' => $query['orderBy'] ?? null,
            'orderDir' => $query['orderDir'] ?? null,
            'sortKeys' => $exportSortKeys,
            'filter' => $query['filter'] ?? null,
        ];
    }

    private function openStreamDownload(string $format, string $table): void
    {
        $types = [
            'txt' => 'text/plain; charset=utf-8',
            'csv' => 'text/csv; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
            'sql' => 'application/sql; charset=utf-8',
        ];
        $ext = $format === 'htm' ? 'html' : $format;
        header('Content-Type: ' . ($types[$format] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $table . '.' . $ext . '"');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
    }

    /** @param list<string> $columns @param array<string,mixed> $row */
    private function echoDelimitedRow(array $columns, array $row, string $format, array $options): void
    {
        $delimiter = $format === 'txt'
            ? (string)($options['delimiter'] ?? "\t")
            : (string)($options['delimiter'] ?? ',');
        $cells = [];
        foreach ($columns as $col) {
            $s = $this->cellToString($row[$col] ?? null);
            $cells[] = $format === 'csv' ? $this->csvEscape($s, $delimiter) : $s;
        }
        echo implode($delimiter, $cells) . "\n";
    }

    /** @param array<string,mixed> $row */
    private function echoJsonRow(array $row, bool $first): void
    {
        if (!$first) {
            echo ',';
        }
        echo json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    /** @param list<string> $columns @param array<string,mixed> $row */
    private function echoXmlRow(array $columns, array $row): void
    {
        echo "  <row>\n";
        foreach ($columns as $col) {
            $tag = $this->xmlTag($col);
            $val = htmlspecialchars($this->cellToString($row[$col] ?? null), ENT_XML1);
            echo "    <{$tag}>{$val}</{$tag}>\n";
        }
        echo "  </row>\n";
    }

    /** @param list<string> $columns @param array<string,mixed> $row */
    private function echoHtmlRow(array $columns, array $row): void
    {
        echo '<tr>';
        foreach ($columns as $col) {
            echo '<td>' . htmlspecialchars($this->cellToString($row[$col] ?? null)) . '</td>';
        }
        echo '</tr>';
    }

    /** @param list<string> $columns @param array<string,mixed> $row */
    private function echoSqlRow(array $columns, array $row, string $tableEsc, string $colList): void
    {
        $vals = [];
        foreach ($columns as $col) {
            $v = $row[$col] ?? null;
            if ($v === null) {
                $vals[] = 'NULL';
            } elseif (is_bool($v)) {
                $vals[] = $v ? 'TRUE' : 'FALSE';
            } elseif (is_int($v) || is_float($v)) {
                $vals[] = (string)$v;
            } else {
                $vals[] = "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string)$v) . "'";
            }
        }
        echo 'INSERT INTO `' . $tableEsc . '` (' . $colList . ') VALUES (' . implode(', ', $vals) . ");\n";
    }

    /** @param array{columns:list<string>,rows:list<array<string,mixed>>} $result */
    public function sendExport(array $result, string $table, string $format, array $options = []): void
    {
        $format = strtolower(trim($format));
        $columns = $result['columns'];
        $rows = $result['rows'];

        match ($format) {
            'txt' => $this->exportTxt($columns, $rows, $table, $options),
            'csv' => $this->exportCsv($columns, $rows, $table, $options),
            'json' => $this->exportJson($rows, $table),
            'xml' => $this->exportXml($columns, $rows, $table),
            'html', 'htm' => $this->exportHtml($columns, $rows, $table),
            'sql' => $this->exportSql($columns, $rows, $table),
            'xls' => $this->exportXls($columns, $rows, $table),
            'xlsx' => $this->exportXlsx($columns, $rows, $table),
            'dbf' => $this->exportDbf($columns, $rows, $table),
            default => throw new \InvalidArgumentException('Unsupported export format: ' . $format),
        };
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    public function parseImport(string $content, string $format, array $options = []): array
    {
        $format = strtolower(trim($format));
        $parsed = match ($format) {
            'txt' => $this->parseDelimited($content, (string)($options['delimiter'] ?? "\t"), (bool)($options['hasHeader'] ?? true)),
            'csv' => $this->parseCsv($content, (bool)($options['hasHeader'] ?? true)),
            'json' => $this->parseJson($content),
            'xml' => $this->parseXml($content),
            'html', 'htm' => $this->parseHtml($content),
            'xls' => $this->parseHtml($content),
            'xlsx' => $this->parseXlsxFile($content),
            'dbf' => $this->parseDbf($content),
            'mdb', 'accdb' => throw new \RuntimeException('MS Access (.mdb/.accdb) no está soportado en esta edición. Exporte a CSV o use ODBC.'),
            'odbc' => $this->parseOdbc($options),
            'db', 'paradox' => throw new \RuntimeException('Paradox (.db) no está soportado en esta edición.'),
            default => throw new \InvalidArgumentException('Unsupported import format: ' . $format),
        };

        if (count($parsed['rows']) > self::MAX_IMPORT_ROWS) {
            $parsed['rows'] = array_slice($parsed['rows'], 0, self::MAX_IMPORT_ROWS);
        }
        return $parsed;
    }

    /** @param object $driver */
    public function importRows(object $driver, string $database, string $table, array $parsed, array $options = []): array
    {
        $truncate = !empty($options['truncate']);
        if ($truncate && method_exists($driver, 'truncateTable')) {
            $driver->truncateTable($database, $table);
        }

        $columns = $parsed['columns'];
        $rows = $parsed['rows'];
        $imported = 0;
        $errors = [];

        foreach ($rows as $idx => $row) {
            try {
                $payload = [];
                foreach ($columns as $col) {
                    if (array_key_exists($col, $row)) {
                        $payload[$col] = $row[$col];
                    }
                }
                if ($payload === []) {
                    continue;
                }
                $driver->insertRow($database, $table, $payload);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $idx + 1, 'message' => $e->getMessage()];
                if (count($errors) >= 20) {
                    break;
                }
            }
        }

        return ['imported' => $imported, 'total' => count($rows), 'errors' => $errors];
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportTxt(array $columns, array $rows, string $table, array $options): void
    {
        $delimiter = (string)($options['delimiter'] ?? "\t");
        $lines = [implode($delimiter, $columns)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $col) {
                $cells[] = $this->cellToString($row[$col] ?? null);
            }
            $lines[] = implode($delimiter, $cells);
        }
        $this->download('text/plain; charset=utf-8', $table . '.txt', implode("\n", $lines));
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportCsv(array $columns, array $rows, string $table, array $options): void
    {
        $delimiter = (string)($options['delimiter'] ?? ',');
        $lines = [implode($delimiter, array_map(fn(string $c) => $this->csvEscape($c, $delimiter), $columns))];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $col) {
                $cells[] = $this->csvEscape($this->cellToString($row[$col] ?? null), $delimiter);
            }
            $lines[] = implode($delimiter, $cells);
        }
        $this->download('text/csv; charset=utf-8', $table . '.csv', implode("\n", $lines));
    }

    /** @param list<array<string,mixed>> $rows */
    private function exportJson(array $rows, string $table): void
    {
        $this->download('application/json; charset=utf-8', $table . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]');
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportXml(array $columns, array $rows, string $table): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<table name="' . htmlspecialchars($table, ENT_XML1) . '">' . "\n";
        foreach ($rows as $row) {
            $xml .= '  <row>' . "\n";
            foreach ($columns as $col) {
                $val = $row[$col] ?? null;
                $xml .= '    <' . $this->xmlTag($col) . '>' . htmlspecialchars($this->cellToString($val), ENT_XML1) . '</' . $this->xmlTag($col) . '>' . "\n";
            }
            $xml .= '  </row>' . "\n";
        }
        $xml .= '</table>';
        $this->download('application/xml; charset=utf-8', $table . '.xml', $xml);
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportHtml(array $columns, array $rows, string $table): void
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($table) . '</title>';
        $html .= '<style>table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:4px 8px;font-family:sans-serif;font-size:12px}</style></head><body>';
        $html .= '<table><thead><tr>';
        foreach ($columns as $col) {
            $html .= '<th>' . htmlspecialchars($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $col) {
                $html .= '<td>' . htmlspecialchars($this->cellToString($row[$col] ?? null)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        $this->download('text/html; charset=utf-8', $table . '.html', $html);
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportSql(array $columns, array $rows, string $table): void
    {
        $tableEsc = str_replace('`', '``', $table);
        $colList = implode(', ', array_map(static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns));
        $lines = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $v = $row[$col] ?? null;
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_bool($v)) {
                    $vals[] = $v ? 'TRUE' : 'FALSE';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string)$v;
                } else {
                    $vals[] = "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string)$v) . "'";
                }
            }
            $lines[] = 'INSERT INTO `' . $tableEsc . '` (' . $colList . ') VALUES (' . implode(', ', $vals) . ');';
        }
        $this->download('application/sql; charset=utf-8', $table . '.sql', implode("\n", $lines));
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportXls(array $columns, array $rows, string $table): void
    {
        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        $html .= '<head><meta charset="utf-8"></head><body><table border="1"><tr>';
        foreach ($columns as $col) {
            $html .= '<th>' . htmlspecialchars($col) . '</th>';
        }
        $html .= '</tr>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $col) {
                $html .= '<td>' . htmlspecialchars($this->cellToString($row[$col] ?? null)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></body></html>';
        $this->download('application/vnd.ms-excel; charset=utf-8', $table . '.xls', $html);
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportXlsx(array $columns, array $rows, string $table): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension required for XLSX export');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp file');
        }
        $path = $tmp . '.xlsx';
        @unlink($tmp);

        $shared = [];
        $sharedIndex = [];
        $getShared = static function (string $s) use (&$shared, &$sharedIndex): int {
            if (!isset($sharedIndex[$s])) {
                $sharedIndex[$s] = count($shared);
                $shared[] = $s;
            }
            return $sharedIndex[$s];
        };

        $sheetRows = '';
        $r = 1;
        $sheetRows .= '<row r="' . $r . '">';
        $c = 0;
        foreach ($columns as $col) {
            $c++;
            $idx = $getShared($col);
            $sheetRows .= '<c r="' . $this->xlsxCol($c) . $r . '" t="s"><v>' . $idx . '</v></c>';
        }
        $sheetRows .= '</row>';
        foreach ($rows as $row) {
            $r++;
            $sheetRows .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($columns as $col) {
                $c++;
                $val = $this->cellToString($row[$col] ?? null);
                if (is_numeric($val) && $val !== '') {
                    $sheetRows .= '<c r="' . $this->xlsxCol($c) . $r . '"><v>' . htmlspecialchars($val, ENT_XML1) . '</v></c>';
                } else {
                    $idx = $getShared($val);
                    $sheetRows .= '<c r="' . $this->xlsxCol($c) . $r . '" t="s"><v>' . $idx . '</v></c>';
                }
            }
            $sheetRows .= '</row>';
        }

        $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
        foreach ($shared as $s) {
            $sharedXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
        }
        $sharedXml .= '</sst>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create XLSX archive');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $table . '.xlsx"');
        readfile($path);
        @unlink($path);
    }

    /** @param list<string> $columns @param list<array<string,mixed>> $rows */
    private function exportDbf(array $columns, array $rows, string $table): void
    {
        $fieldDefs = [];
        foreach ($columns as $col) {
            $name = strtoupper(substr(preg_replace('/[^A-Za-z0-9_]/', '_', $col) ?: 'F', 0, 10));
            $fieldDefs[] = ['name' => $name, 'type' => 'C', 'len' => 254];
        }
        $headerLen = 32 + count($fieldDefs) * 32 + 1;
        $recordLen = 1;
        foreach ($fieldDefs as $f) {
            $recordLen += $f['len'];
        }
        $data = str_repeat("\0", $headerLen);
        $data[0] = "\x03";
        $year = (int)date('Y') - 1900;
        $data[1] = chr($year);
        $data[2] = chr((int)date('n'));
        $data[3] = chr((int)date('j'));
        $data[4] = pack('V', count($rows));
        $data[8] = pack('v', $headerLen);
        $data[10] = pack('v', $recordLen);

        $offset = 32;
        foreach ($fieldDefs as $f) {
            $name = str_pad($f['name'], 11, "\0");
            $data = substr_replace($data, $name, $offset, 11);
            $data[$offset + 11] = $f['type'];
            $data = substr_replace($data, pack('V', $f['len']), $offset + 16, 4);
            $offset += 32;
        }
        $data[$offset] = "\r";

        foreach ($rows as $row) {
            $rec = ' ';
            $i = 0;
            foreach ($columns as $col) {
                $val = substr($this->cellToString($row[$col] ?? null), 0, $fieldDefs[$i]['len']);
                $rec .= str_pad($val, $fieldDefs[$i]['len'], ' ');
                $i++;
            }
            $data .= $rec;
        }
        $data .= "\x1A";
        $this->download('application/octet-stream', $table . '.dbf', $data);
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseDelimited(string $content, string $delimiter, bool $hasHeader): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        if ($lines === []) {
            return ['columns' => [], 'rows' => []];
        }
        if ($delimiter === '\\t') {
            $delimiter = "\t";
        }
        if ($delimiter === 'auto') {
            $delimiter = $this->detectDelimiter($lines[0]);
        }
        $header = $hasHeader ? str_getcsv(array_shift($lines) ?? '', $delimiter) : [];
        $rows = [];
        $colCount = count($header);
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter);
            if ($colCount === 0) {
                $colCount = count($cells);
                $header = array_map(fn(int $i) => 'col' . ($i + 1), range(0, $colCount - 1));
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = $cells[$i] ?? null;
            }
            $rows[] = $row;
        }
        return ['columns' => $header, 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseCsv(string $content, bool $hasHeader): array
    {
        return $this->parseDelimited($content, ',', $hasHeader);
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseJson(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON');
        }
        if ($data !== [] && !isset($data[0]) && is_array($data)) {
            $data = [$data];
        }
        $columns = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $k) {
                if (!in_array($k, $columns, true)) {
                    $columns[] = $k;
                }
            }
        }
        $rows = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseXml(string $content): array
    {
        $xml = @simplexml_load_string($content);
        if ($xml === false) {
            throw new \RuntimeException('Invalid XML');
        }
        $columns = [];
        $rows = [];
        foreach ($xml->row ?? $xml->record ?? $xml->children() as $rowNode) {
            $row = [];
            foreach ($rowNode->children() as $child) {
                $name = (string)$child->getName();
                if (!in_array($name, $columns, true)) {
                    $columns[] = $name;
                }
                $row[$name] = (string)$child;
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseHtml(string $content): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($content);
        $tables = $dom->getElementsByTagName('table');
        if ($tables->length === 0) {
            throw new \RuntimeException('No table found in HTML');
        }
        $table = $tables->item(0);
        $columns = [];
        $rows = [];
        $trs = $table->getElementsByTagName('tr');
        $isFirst = true;
        foreach ($trs as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $node) {
                if ($node instanceof \DOMElement && in_array(strtolower($node->tagName), ['td', 'th'], true)) {
                    $cells[] = trim($node->textContent ?? '');
                }
            }
            if ($cells === []) {
                continue;
            }
            if ($isFirst) {
                $columns = $cells;
                $isFirst = false;
                continue;
            }
            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = $cells[$i] ?? null;
            }
            $rows[] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseXlsxFile(string $binary): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive required for XLSX import');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_in_');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp file');
        }
        file_put_contents($tmp, $binary);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Invalid XLSX file');
        }
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sst = @simplexml_load_string($sharedXml);
            if ($sst) {
                foreach ($sst->si as $si) {
                    $shared[] = (string)($si->t ?? $si->r ?? '');
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        if ($sheetXml === false) {
            throw new \RuntimeException('Cannot read sheet1 from XLSX');
        }
        $sheet = @simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new \RuntimeException('Invalid sheet XML');
        }
        $columns = [];
        $rows = [];
        $rowIndex = 0;
        foreach ($sheet->sheetData->row ?? [] as $rowNode) {
            $cells = [];
            foreach ($rowNode->c as $c) {
                $ref = (string)($c['r'] ?? '');
                preg_match('/([A-Z]+)/', $ref, $m);
                $colLetter = $m[1] ?? '';
                $colNum = $this->xlsxColToNum($colLetter);
                $type = (string)($c['t'] ?? '');
                $val = (string)($c->v ?? '');
                if ($type === 's' && isset($shared[(int)$val])) {
                    $val = $shared[(int)$val];
                }
                $cells[$colNum] = $val;
            }
            ksort($cells);
            $line = array_values($cells);
            if ($rowIndex === 0) {
                $columns = $line;
            } else {
                $row = [];
                foreach ($columns as $i => $col) {
                    $row[$col] = $line[$i] ?? null;
                }
                $rows[] = $row;
            }
            $rowIndex++;
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseDbf(string $binary): array
    {
        if (strlen($binary) < 32) {
            throw new \RuntimeException('Invalid DBF file');
        }
        $numRecords = unpack('V', substr($binary, 4, 4))[1] ?? 0;
        $headerLen = unpack('v', substr($binary, 8, 2))[1] ?? 0;
        $recordLen = unpack('v', substr($binary, 10, 2))[1] ?? 0;
        $fields = [];
        $pos = 32;
        while ($pos + 32 <= $headerLen - 1) {
            $name = rtrim(substr($binary, $pos, 11), "\0 ");
            $type = $binary[$pos + 11];
            $len = ord($binary[$pos + 16]);
            if ($name === '' || ord($name[0]) === 0x0D) {
                break;
            }
            $fields[] = ['name' => $name, 'len' => $len];
            $pos += 32;
        }
        $columns = array_column($fields, 'name');
        $rows = [];
        $dataPos = $headerLen;
        for ($i = 0; $i < $numRecords; $i++) {
            $rec = substr($binary, $dataPos + $i * $recordLen, $recordLen);
            if ($rec === '' || $rec === false) {
                break;
            }
            $row = [];
            $offset = 1;
            foreach ($fields as $f) {
                $row[$f['name']] = trim(substr($rec, $offset, $f['len']));
                $offset += $f['len'];
            }
            $rows[] = $row;
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return array{columns:list<string>,rows:list<array<string,mixed>>} */
    private function parseOdbc(array $options): array
    {
        if (!extension_loaded('pdo_odbc')) {
            throw new \RuntimeException('PDO ODBC no está disponible en este servidor.');
        }
        $dsn = trim((string)($options['odbcDsn'] ?? ''));
        $query = trim((string)($options['odbcQuery'] ?? ''));
        if ($dsn === '' || $query === '') {
            throw new \RuntimeException('DSN y consulta ODBC son requeridos.');
        }
        $user = (string)($options['odbcUser'] ?? '');
        $pass = (string)($options['odbcPassword'] ?? '');
        $pdo = new PDO('odbc:' . $dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->query($query);
        if ($st === false) {
            throw new \RuntimeException('ODBC query failed');
        }
        $columns = [];
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $meta = $st->getColumnMeta($i);
            $columns[] = (string)($meta['name'] ?? 'col' . $i);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['columns' => $columns, 'rows' => $rows];
    }

    private function detectDelimiter(string $line): string
    {
        foreach (["\t", ';', '|', ','] as $d) {
            if (substr_count($line, $d) > 0) {
                return $d;
            }
        }
        return ',';
    }

    private function cellToString(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
        }
        return (string)$v;
    }

    private function csvEscape(string $s, string $delimiter): string
    {
        if (str_contains($s, $delimiter) || str_contains($s, '"') || str_contains($s, "\n")) {
            return '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    private function xmlTag(string $name): string
    {
        $tag = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?: 'field';
        if (preg_match('/^[0-9]/', $tag)) {
            $tag = 'f_' . $tag;
        }
        return $tag;
    }

    private function xlsxCol(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private function xlsxColToNum(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    private function download(string $contentType, string $filename, string $body): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $body;
    }
}
