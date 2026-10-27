<?php

namespace App\Libraries;

use DomainException;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** Validate the same response contract as workbook imports, without losing text identifiers. */
class GoogleSheetsRows
{
    public const MAX_ROWS = 100000;
    private const REQUIRED = ['Good', 'Bad', 'agent_name', 'csat_id', 'csat_submission_channel_name', 'is_excluded'];
    private const REASON_LABELS = [1 => 'Long Resolution Time', 2 => 'Agent attitude can be improved',
        3 => 'Company Policy can be improved', 4 => 'Courier can be improved'];

    public function normalize(array $values, array $mappings = []): array
    {
        if ($values === [] || !is_array($values[0] ?? null)) {
            throw new DomainException('The worksheet needs column headings in row 1.');
        }
        $rows = static function () use ($values): \Generator {
            foreach ($values as $index => $cells) {
                if ($index === 0) { continue; }
                yield $index + 1 => $cells;
            }
        };
        return $this->normalizeRows($rows(), $values[0], $mappings);
    }

    /** @param iterable<int, array> $rawRows Actual worksheet row number => zero-indexed cells. */
    public function normalizeRows(iterable $rawRows, array $headerValues, array $mappings = []): array
    {
        if ($headerValues === []) {
            throw new DomainException('The worksheet needs column headings in row 1.');
        }
        $headers = [];
        foreach ($headerValues as $index => $value) {
            $name = $this->text($value, 255, 1, 'column heading');
            if ($name === '') { continue; }
            if ($index >= 100) {
                throw new DomainException('The worksheet exceeds the 100-column limit. No data was changed.');
            }
            if (in_array($name, $headers, true)) {
                throw new DomainException('Duplicate column heading in row 1. Give each source column a unique name.');
            }
            $headers[$index] = $name;
        }
        $dateHeader = in_array('CSAT Submit Date', $headers, true) ? 'CSAT Submit Date' : 'Date';
        $teamHeader = in_array('agent_owner_team', $headers, true) ? 'agent_owner_team' : 'Team';
        $required = array_merge(self::REQUIRED, [$dateHeader, $teamHeader]);
        if ($dateHeader === 'CSAT Submit Date' || $teamHeader === 'agent_owner_team') {
            $required[] = 'Inclulde in CSAT?';
        }
        if (array_diff($required, $headers) !== []) {
            throw new DomainException('Missing required columns in row 1: ' . implode(', ', array_diff($required, $headers)) . '.');
        }
        foreach (['reason_column', 'comment_column', 'case_column'] as $field) {
            $mapped = trim((string) ($mappings[$field] ?? ''));
            if ($mapped !== '' && !in_array($mapped, $headers, true)) {
                throw new DomainException('A mapped reason, comment, or case column was not found. Check the source workbook headings.');
            }
        }
        $lastNamedColumn = max(array_keys($headers));
        $rows = [];
        $ids = [];
        $identities = [];
        $identityRanks = [];
        $identityNames = [];
        $duplicates = 0;
        $invalidDurationCount = 0;
        $nonempty = 0;
        foreach ($rawRows as $sourceRow => $cells) {
            if (!is_int($sourceRow) || $sourceRow < 2) {
                throw new DomainException('Source response row numbers must be integers starting at row 2.');
            }
            if (!is_array($cells)) {
                throw new DomainException("Row {$sourceRow}: the worksheet contains an invalid row.");
            }
            // The export has styled/helper cells to the right of its named record.
            // They are outside the source table, including on otherwise blank rows.
            foreach (array_keys($cells) as $column) {
                if ($column > $lastNamedColumn) { unset($cells[$column]); }
            }
            if (count(array_filter($cells, static fn ($v) => $v !== null && $v !== '')) === 0) { continue; }
            if (++$nonempty > self::MAX_ROWS) {
                throw new DomainException('The worksheet exceeds the 100,000-response limit. Split the source before syncing.');
            }
            $raw = [];
            foreach ($cells as $column => $value) {
                if ($value === null || $value === '') { continue; }
                if ($column >= 100) {
                    throw new DomainException('The worksheet exceeds the 100-column limit. No data was changed.');
                }
                if (!isset($headers[$column])) {
                    throw new DomainException("Row {$sourceRow}: a populated column has no heading in row 1.");
                }
            }
            foreach ($headers as $column => $name) {
                $value = $cells[$column] ?? null;
                if ($value !== null && !is_scalar($value)) {
                    throw new DomainException("Row {$sourceRow}: source cells must contain text or numbers.");
                }
                $raw[$name] = $value;
            }
            $good = $this->flag($raw['Good'], $sourceRow, 'Good');
            $bad = $this->flag($raw['Bad'], $sourceRow, 'Bad');
            if ($good + $bad !== 1) {
                throw new DomainException("Row {$sourceRow}: Good and Bad must identify exactly one rating (1/0 or 0/1).");
            }
            $id = $this->identifier($raw['csat_id'], $sourceRow, 'csat_id', 100, true);
            $sourceRaw = $raw;
            $responseDate = $this->date($raw[$dateHeader], $sourceRow);
            // The raw export records the customer's selected reason explicitly.
            // Keep every source heading and add only an absent reporting alias.
            if (!array_key_exists('dsat_reason', $raw) && array_key_exists('csat_reason', $raw)) {
                $sourceReason = $this->text($raw['csat_reason'], 1000, $sourceRow, 'csat_reason');
                $decoded = $this->reasonLabels($sourceReason);
                $raw['dsat_reasons'] = $decoded ?? [];
                $raw['dsat_reason'] = $decoded === null ? $sourceReason : implode('; ', $decoded);
            }
            foreach (['reason_column' => 'dsat_reason', 'comment_column' => 'customer_comment', 'case_column' => 'case_id'] as $field => $canonical) {
                $mapped = trim((string) ($mappings[$field] ?? ''));
                if ($mapped !== '') {
                    if ($canonical === 'dsat_reason' && $mapped === 'csat_reason') {
                        $sourceReason = $this->text($sourceRaw[$mapped], 1000, $sourceRow, $canonical);
                        $decoded = $this->reasonLabels($sourceReason);
                        $raw['dsat_reasons'] = $decoded ?? [];
                        $raw[$canonical] = $decoded === null ? $sourceReason : implode('; ', $decoded);
                    } else {
                        $raw[$canonical] = $canonical === 'case_id'
                            ? $this->identifier($sourceRaw[$mapped], $sourceRow, 'case ID', 255, false)
                            : $this->text($sourceRaw[$mapped], $canonical === 'dsat_reason' ? 1000 : 30000, $sourceRow, $canonical);
                        if ($canonical === 'dsat_reason') { unset($raw['dsat_reasons']); }
                    }
                }
            }
            $identity = AgentRoster::identity($raw);
            if ($identity !== null) {
                $email = $identity['email'];
                $identityNames[$email][$identity['name']] = true;
                $timestamp = $sourceRaw['csat_submit_timestamp'] ?? null;
                $rank = [$responseDate, is_numeric($timestamp) ? (float) $timestamp : 0.0, $sourceRow];
                if (!isset($identityRanks[$email]) || $rank > $identityRanks[$email]) {
                    $identities[$email] = $identity;
                    $identityRanks[$email] = $rank;
                }
            }
            $duration = $raw['handling_duration_secs'] ?? null;
            $invalidDuration = false;
            if ($duration !== null && $duration !== '' && (!is_numeric($duration) || !is_finite((float) $duration) || (float) $duration < 0)) {
                $duration = null;
                $invalidDuration = true;
            }
            if (array_key_exists('Inclulde in CSAT?', $sourceRaw)) {
                $excluded = 1 - $this->flag($sourceRaw['Inclulde in CSAT?'], $sourceRow, 'Inclulde in CSAT?');
                $raw['csat_report_is_excluded'] = $excluded;
            } else {
                $excluded = $this->flag($sourceRaw['is_excluded'], $sourceRow, 'is_excluded');
            }
            $rawJson = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($rawJson) > 60000) {
                throw new DomainException("Row {$sourceRow}: the row contains more than 60 KB of text.");
            }
            $row = [
                'source_row' => $sourceRow,
                'response_date' => $responseDate,
                'team' => $this->label($raw[$teamHeader], 120, $sourceRow, $teamHeader),
                'agent_name' => $this->label($raw['agent_name'], 255, $sourceRow, 'agent_name'),
                'agent_email' => $identity['email'] ?? null,
                'channel' => $this->label($raw['csat_submission_channel_name'], 120, $sourceRow, 'channel'),
                'category' => $this->label($raw['l1_name'] ?? '', 255, $sourceRow, 'l1_name'),
                'csat_id' => $id, 'good' => $good, 'bad' => $bad,
                'is_excluded' => $excluded,
                'handling_seconds' => $duration === null || $duration === '' ? null : (float) $duration,
                'raw_json' => $rawJson,
            ];
            // Source row numbers are provenance, not part of a response's identity.
            $fingerprint = $row;
            unset($fingerprint['source_row']);
            $fingerprint = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if (isset($ids[$id])) {
                if ($ids[$id] !== $fingerprint) {
                    throw new DomainException("Row {$sourceRow}: csat_id conflicts with an earlier row. Keep one correct record per CSAT ID in the workbook, then retry.");
                }
                $duplicates++;
                continue;
            }
            $ids[$id] = $fingerprint;
            $rows[] = $row;
            if ($invalidDuration) { $invalidDurationCount++; }
        }
        if ($rows === []) {
            throw new DomainException('The worksheet contains no response rows. The last successful report was kept.');
        }
        $identityVariants = array_sum(array_map(static fn (array $names): int => max(0, count($names) - 1), $identityNames));
        return ['rows' => $rows, 'duplicate_count' => $duplicates, 'identities' => array_values($identities),
            'headers' => array_values($headers), 'identity_variants' => $identityVariants,
            'invalid_duration_count' => $invalidDurationCount];
    }

    /** Null means an unknown source value: keep it verbatim instead of assigning a label. */
    private function reasonLabels(string $source): ?array
    {
        if ($source === '') { return []; }
        if (preg_match('/^[1-4]\.0+$/D', $source)) { $source = (string) (int) $source; }
        if (!preg_match('/^[1-4](?:\s*,\s*[1-4])*$/D', $source)) { return null; }
        $codes = array_unique(array_map('intval', explode(',', $source)));
        return array_values(array_map(static fn (int $code): string => self::REASON_LABELS[$code], $codes));
    }

    private function identifier(mixed $value, int $row, string $column, int $max, bool $required): string
    {
        if ((is_float($value) || is_int($value)) && (!is_finite((float) $value) || abs((float) $value) > 9007199254740991 || floor((float) $value) !== (float) $value)) {
            throw new DomainException("Row {$row}: {$column} must be stored as plain text to preserve its exact digits.");
        }
        // PHP's default float string conversion may use only 14 significant digits.
        // Every validated integer up to 2^53 - 1 has an exact decimal representation.
        if (is_float($value)) {
            $value = sprintf('%.0f', $value);
        }
        $text = $this->text($value, $max, $row, $column);
        if ($required && $text === '') {
            throw new DomainException("Row {$row}: csat_id is required.");
        }
        return $text;
    }

    private function text(mixed $value, int $max, int $row, string $column): string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new DomainException("Row {$row}: {$column} must contain text or a number.");
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw new DomainException("Row {$row}: {$column} exceeds {$max} characters.");
        }
        return $value;
    }

    private function label(mixed $value, int $max, int $row, string $column): string
    {
        return $this->text($value, $max, $row, $column) ?: '(Unspecified)';
    }

    private function flag(mixed $value, int $row, string $column): int
    {
        if (!is_numeric($value) || !in_array((float) $value, [0.0, 1.0], true)) {
            throw new DomainException("Row {$row}: {$column} must be 0 or 1.");
        }
        return (int) $value;
    }

    private function date(mixed $value, int $row): string
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
        throw new DomainException("Row {$row}: Date must be a Google date, YYYY-MM-DD, DD/MM/YYYY, or DD Mon YYYY.");
    }
}
