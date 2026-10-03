<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use DomainException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;
use ZipArchive;

class DailyCsatImporter
{
    public const SHEET = 'Daily CSAT';
    public const MAX_ROWS = 15000;
    public const MAX_BYTES = 20 * 1024 * 1024;
    private const REQUIRED = ['Date', 'Team', 'Good', 'Bad', 'agent_name', 'csat_id', 'csat_submission_channel_name', 'is_excluded'];

    /** Read only the selected sheet; use cached formulas without evaluating other sheets. */
    public function read(string $path): array
    {
        $this->validateArchive($path);
        $reader = new Xlsx();
        if (!in_array(self::SHEET, $reader->listWorksheetNames($path), true)) {
            throw new DomainException('The workbook must contain a sheet named exactly "Daily CSAT". No data was imported.');
        }
        $reader->setLoadSheetsOnly(self::SHEET);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $filter = new class implements IReadFilter {
            public bool $overflow = false;

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                if ($worksheetName !== DailyCsatImporter::SHEET) {
                    return false;
                }
                if ($row > DailyCsatImporter::MAX_ROWS + 1 || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) > 100) {
                    $this->overflow = true;
                    return false;
                }
                return true;
            }
        };
        $reader->setReadFilter($filter);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName(self::SHEET);
            if (!$sheet || $filter->overflow) {
                throw new DomainException('Daily CSAT exceeds the 15,000-row or 100-column limit. Split the report into smaller files.');
            }
            $headers = [];
            foreach ($sheet->getRowIterator(1, 1) as $headerRow) {
                foreach ($headerRow->getCellIterator() as $cell) {
                    $value = trim((string) $this->cellValue($cell));
                    if ($value === '') {
                        continue;
                    }
                    if (in_array($value, $headers, true)) {
                        throw new DomainException('Duplicate column heading: ' . $value);
                    }
                    $headers[$cell->getColumn()] = $value;
                }
            }
            $missing = array_diff(self::REQUIRED, $headers);
            if ($missing !== []) {
                throw new DomainException('Missing columns in row 1 of Daily CSAT: ' . implode(', ', $missing));
            }
            $rows = [];
            $ids = [];
            $lastRow = $sheet->getHighestDataRow();
            for ($r = 2; $r <= $lastRow; $r++) {
                $raw = [];
                foreach ($headers as $column => $name) {
                    $raw[$name] = $this->cellValue($sheet->getCell($column . $r));
                }
                if (count(array_filter($raw, static fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }
                $good = $this->flag($raw['Good'], $r, 'Good');
                $bad = $this->flag($raw['Bad'], $r, 'Bad');
                if ($good + $bad !== 1) {
                    throw new DomainException("Row {$r}: Good and Bad must identify exactly one rating (1/0 or 0/1).");
                }
                $id = trim((string) $raw['csat_id']);
                if ($id === '' || strlen($id) > 100 || isset($ids[$id])) {
                    throw new DomainException("Row {$r}: csat_id is missing, too long, or duplicated within Daily CSAT.");
                }
                $ids[$id] = true;
                $rawJson = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                AgentRoster::identity($raw);
                if (strlen($rawJson) > 60000) {
                    throw new DomainException("Row {$r}: the row contains too much text to import.");
                }
                $duration = $raw['handling_duration_secs'] ?? null;
                if ($duration !== null && $duration !== '' && (!is_numeric($duration) || (float) $duration < 0 || !is_finite((float) $duration))) {
                    throw new DomainException("Row {$r}: handling_duration_secs must be blank or a nonnegative number.");
                }
                $rows[] = [
                    'source_row' => $r,
                    'response_date' => $this->parseDate($raw['Date'], $r),
                    'team' => $this->label($raw['Team'], 120, $r, 'Team'),
                    'agent_name' => $this->label($raw['agent_name'], 255, $r, 'agent_name'),
                    'channel' => $this->label($raw['csat_submission_channel_name'], 120, $r, 'csat_submission_channel_name'),
                    'category' => $this->label($raw['l1_name'] ?? '', 255, $r, 'l1_name'),
                    'csat_id' => $id,
                    'good' => $good,
                    'bad' => $bad,
                    'is_excluded' => $this->flag($raw['is_excluded'], $r, 'is_excluded'),
                    'handling_seconds' => $duration === null || $duration === '' ? null : (float) $duration,
                    'raw_json' => $rawJson,
                ];
            }
            if ($rows === []) {
                throw new DomainException('Daily CSAT contains no response rows.');
            }
            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function import(string $path, string $filename, int $userId, ?BaseConnection $db = null): int
    {
        $db ??= db_connect();
        $hash = hash_file('sha256', $path);
        if ($db->table('imports')->where('file_hash', $hash)->countAllResults() > 0) {
            throw new DomainException('This exact workbook has already been imported. Open its existing report from Uploads.');
        }
        $rows = $this->read($path);
        $dates = array_column($rows, 'response_date');
        $db->transException(true)->transStart();
        try {
            $db->table('imports')->insert([
                'filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255),
                'file_hash' => $hash,
                'uploaded_by' => $userId,
                'row_count' => count($rows),
                'date_from' => min($dates),
                'date_to' => max($dates),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $importId = (int) $db->insertID();
            $identities = [];
            foreach ($rows as $row) {
                $identity = AgentRoster::identity(json_decode($row['raw_json'], true));
                if ($identity) {
                    if (isset($identities[$identity['email']]) && $identities[$identity['email']]['name'] !== $identity['name']) {
                        throw new DomainException('The same agent email has conflicting names in this workbook. Correct it before importing.');
                    }
                    $identities[$identity['email']] = $identity;
                }
            }
            foreach ($identities as $identity) {
                AgentRoster::remember($db, $identity);
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                foreach ($chunk as &$row) {
                    $row['import_id'] = $importId;
                }
                unset($row);
                $db->table('responses')->insertBatch($chunk);
            }
            $db->transComplete();
            if (!$db->transStatus()) {
                throw new \RuntimeException('The import could not be saved.');
            }
            return $importId;
        } catch (Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    private function cellValue($cell): mixed
    {
        $value = $cell->getDataType() === DataType::TYPE_FORMULA ? $cell->getOldCalculatedValue() : $cell->getValue();
        if ($cell->getDataType() === DataType::TYPE_FORMULA && $value === null) {
            throw new DomainException('Cell ' . $cell->getCoordinate() . ': formula has no saved result. Recalculate and save the workbook in Excel before uploading.');
        }
        return $value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText ? $value->getPlainText() : $value;
    }

    private function flag(mixed $value, int $row, string $column): int
    {
        if (!is_numeric($value) || !in_array((float) $value, [0.0, 1.0], true)) {
            throw new DomainException("Row {$row}: {$column} must be 0 or 1.");
        }
        return (int) $value;
    }

    private function label(mixed $value, int $max, int $row, string $column): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new DomainException("Row {$row}: {$column} exceeds {$max} characters.");
        }
        return $value === '' ? '(Unspecified)' : $value;
    }

    private function parseDate(mixed $value, int $row): string
    {
        if (is_numeric($value) && (float) $value >= 1 && (float) $value <= 2958465) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        foreach (['!Y-m-d', '!d/m/Y', '!d M Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim((string) $value));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        throw new DomainException("Row {$row}: Date must be an Excel date, YYYY-MM-DD, DD/MM/YYYY, or DD Mon YYYY.");
    }

    private function validateArchive(string $path): void
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new DomainException('Upload an .xlsx file no larger than 20 MB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new DomainException('This is not a readable .xlsx workbook.');
        }
        try {
            $bytes = 0;
            if ($zip->numFiles > 5000) {
                throw new DomainException('The workbook has too many internal files.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $bytes += $entry['size'];
                if ($bytes > 100 * 1024 * 1024) {
                    throw new DomainException('The workbook is too large when expanded. Split it into smaller files.');
                }
            }
            if ($zip->locateName('xl/workbook.xml') === false) {
                throw new DomainException('Only .xlsx workbooks are supported.');
            }
        } finally {
            $zip->close();
        }
    }
}
