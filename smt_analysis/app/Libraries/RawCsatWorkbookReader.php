<?php

namespace App\Libraries;

use DomainException;
use Generator;
use XMLReader;
use ZipArchive;

/** Streams only the selected worksheet and its referenced shared strings; never evaluates formulas. */
class RawCsatWorkbookReader
{
    public const SHEET = 'Raw Data CSAT';
    public const MAX_PHYSICAL_ROWS = 200000;
    private const MAX_SHEET_BYTES = 512 * 1024 * 1024;
    private const MAX_STRINGS_BYTES = 32 * 1024 * 1024;
    private ZipArchive $zip;
    private string $path;
    private array $strings = [];

    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > DailyCsatImporter::MAX_BYTES) {
            throw new DomainException('Upload an .xlsx workbook no larger than 128 MB.');
        }
        $this->path = realpath($path) ?: $path;
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new DomainException('This is not a readable .xlsx workbook.');
        }
        $previousErrors = libxml_use_internal_errors(true);
        try {
            if ($this->zip->numFiles > 5000) {
                throw new DomainException('The workbook has too many internal files.');
            }
            $sheet = $this->locateSheet();
            $this->checkEntry($sheet, self::MAX_SHEET_BYTES);
            $needed = [];
            $this->scan($sheet, function (XMLReader $xml) use (&$needed): void {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'c'
                    && $xml->getAttribute('t') === 's') {
                    $depth = $xml->depth;
                    while ($xml->read() && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
                        $this->safeNode($xml);
                        if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'v') {
                            $id = $xml->readString();
                            if (!ctype_digit($id)) { throw new DomainException('The worksheet contains an invalid shared-string reference.'); }
                            $needed[(int) $id] = true;
                        }
                    }
                }
            });
            $this->strings = [];
            if ($needed !== []) {
                $this->checkEntry('xl/sharedStrings.xml', self::MAX_STRINGS_BYTES);
                $index = -1;
                $text = '';
                $this->scan('xl/sharedStrings.xml', function (XMLReader $xml) use (&$index, &$text, $needed): void {
                    if ($xml->localName === 'si' && $xml->nodeType === XMLReader::ELEMENT) {
                        $index++;
                        $text = '';
                        if ($index > 500000) { throw new DomainException('The workbook has too many shared strings.'); }
                        if ($xml->isEmptyElement && isset($needed[$index])) { $this->strings[$index] = ''; }
                    } elseif (isset($needed[$index]) && $xml->localName === 't' && $xml->nodeType === XMLReader::ELEMENT) {
                        $text .= $xml->readString();
                        if (strlen($text) > 60000) { throw new DomainException('A source cell contains more than 60 KB of text.'); }
                    } elseif ($xml->localName === 'si' && $xml->nodeType === XMLReader::END_ELEMENT && isset($needed[$index])) {
                        $this->strings[$index] = $text;
                    }
                });
                if (count($this->strings) !== count($needed)) {
                    throw new DomainException('The worksheet refers to missing shared strings. Save it as a complete .xlsx workbook and retry.');
                }
            }
            unset($needed);
            $headers = null;
            foreach ($this->rows($sheet) as $rowNumber => $cells) {
                if ($rowNumber !== 1) { break; }
                $headers = $cells;
                break;
            }
            if ($headers === null) { throw new DomainException('Raw Data CSAT needs column headings in row 1.'); }
            $lastColumn = -1;
            foreach ($headers as $column => $header) {
                if (trim((string) $header) !== '') { $lastColumn = max($lastColumn, $column); }
            }
            if ($lastColumn < 0 || $lastColumn >= 100) {
                throw new DomainException('Raw Data CSAT needs at most 100 labelled source columns in row 1.');
            }
            $headers = array_replace(array_fill(0, $lastColumn + 1, ''), $headers);
            return (new GoogleSheetsRows())->normalizeRows($this->rows($sheet, $lastColumn, true), $headers);
        } finally {
            $this->strings = [];
            $this->zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function locateSheet(): string
    {
        $this->checkEntry('xl/workbook.xml', 1024 * 1024);
        $this->checkEntry('xl/_rels/workbook.xml.rels', 1024 * 1024);
        $relationshipId = null;
        $this->scan('xl/workbook.xml', function (XMLReader $xml) use (&$relationshipId): void {
            if ($xml->nodeType !== XMLReader::ELEMENT) { return; }
            if ($xml->localName === 'workbookPr' && in_array($xml->getAttribute('date1904'), ['1', 'true'], true)) {
                throw new DomainException('This workbook uses the 1904 date system. Save it using the standard 1900 date system before importing.');
            }
            if ($xml->localName === 'sheet' && $xml->getAttribute('name') === self::SHEET) {
                $relationshipId = $xml->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            }
        });
        if (!$relationshipId) {
            throw new DomainException('The workbook must contain a sheet named exactly "Raw Data CSAT". No other worksheet will be imported.');
        }
        $target = null;
        $this->scan('xl/_rels/workbook.xml.rels', function (XMLReader $xml) use ($relationshipId, &$target): void {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'Relationship' && $xml->getAttribute('Id') === $relationshipId) {
                if ($xml->getAttribute('TargetMode') === 'External') {
                    throw new DomainException('The required worksheet must be stored inside the workbook.');
                }
                $target = $xml->getAttribute('Target');
            }
        });
        if (!$target || str_contains($target, '\\') || str_contains($target, ':')) {
            throw new DomainException('The Raw Data CSAT worksheet relationship is invalid.');
        }
        $parts = [];
        foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { if ($parts === []) { throw new DomainException('Invalid worksheet path.'); } array_pop($parts); }
            else { $parts[] = $part; }
        }
        $resolved = implode('/', $parts);
        if (!str_starts_with($resolved, 'xl/worksheets/')) { throw new DomainException('Invalid worksheet path.'); }
        return $resolved;
    }

    /** @return Generator<int,array<int,mixed>> */
    private function rows(string $entry, ?int $lastColumn = null, bool $dataOnly = false): Generator
    {
        [$xml, $stream] = $this->open($entry);
        $rowNumber = 0;
        $cells = [];
        $column = null;
        $type = '';
        $value = null;
        $formula = false;
        $cached = false;
        try {
            while ($xml->read()) {
                $this->safeNode($xml);
                if ($xml->nodeType === XMLReader::ELEMENT) {
                    switch ($xml->localName) {
                        case 'row':
                            $rowNumber = (int) $xml->getAttribute('r');
                            if ($rowNumber < 1 || $rowNumber > self::MAX_PHYSICAL_ROWS) { throw new DomainException('Raw Data CSAT exceeds the 200,000 worksheet-row limit.'); }
                            $cells = [];
                            break;
                        case 'c':
                            $address = $xml->getAttribute('r') ?? '';
                            if (!preg_match('/^([A-Z]+)[1-9][0-9]*$/D', $address, $match)) { throw new DomainException('The worksheet contains an invalid cell address.'); }
                            $column = 0;
                            foreach (str_split($match[1]) as $letter) { $column = $column * 26 + ord($letter) - 64; }
                            $column--;
                            $type = $xml->getAttribute('t') ?? '';
                            $value = null;
                            $formula = false;
                            $cached = false;
                            if ($xml->isEmptyElement) { $column = null; }
                            break;
                        case 'f': $formula = true; break;
                        case 'v': $value = $xml->readString(); $cached = true; break;
                        case 't': if ($type === 'inlineStr') { $value = ($value ?? '') . $xml->readString(); } break;
                    }
                } elseif ($xml->nodeType === XMLReader::END_ELEMENT) {
                    if ($xml->localName === 'c' && $column !== null && ($lastColumn === null || $column <= $lastColumn)) {
                        if ($formula && !$cached) { throw new DomainException("Row {$rowNumber}: a formula has no saved result. Recalculate and save the workbook before importing."); }
                        if ($type === 's') {
                            if ($value === null || !ctype_digit($value) || !array_key_exists((int) $value, $this->strings)) { throw new DomainException('The worksheet contains an invalid shared-string reference.'); }
                            $value = $this->strings[(int) $value];
                        } elseif (($type === '' || $type === 'n' || $type === 'b') && $value !== null && $value !== '' && is_numeric($value)) {
                            $value = preg_match('/^-?[0-9]{1,15}$/D', $value) ? (int) $value : (float) $value;
                        }
                        if (is_string($value) && strlen($value) > 60000) { throw new DomainException("Row {$rowNumber}: a cell contains more than 60 KB of text."); }
                        $cells[$column] = $value;
                        $column = null;
                    } elseif ($xml->localName === 'row' && (!$dataOnly || $rowNumber > 1)) {
                        yield $rowNumber => $cells;
                    }
                }
            }
            $this->checkXmlErrors();
        } finally {
            $xml->close();
            if (is_resource($stream)) { fclose($stream); }
        }
    }

    private function checkEntry(string $entry, int $limit): void
    {
        $metadata = $this->zip->statName($entry);
        if (!$metadata || $metadata['size'] > $limit) { throw new DomainException('A required workbook component is missing or exceeds its supported size limit.'); }
    }

    private function open(string $entry): array
    {
        $stream = null;
        if (method_exists(XMLReader::class, 'fromStream')) {
            $stream = $this->zip->getStream($entry);
            if (!$stream) { throw new DomainException('The selected worksheet cannot be read.'); }
            $xml = XMLReader::fromStream($stream, null, LIBXML_NONET | LIBXML_COMPACT);
        } else {
            $xml = new XMLReader();
            if (!$xml->open('zip://' . $this->path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT)) { throw new DomainException('The selected worksheet cannot be read.'); }
        }
        $xml->setParserProperty(XMLReader::LOADDTD, false);
        $xml->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        return [$xml, $stream];
    }

    private function scan(string $entry, callable $visit): void
    {
        [$xml, $stream] = $this->open($entry);
        try {
            while ($xml->read()) { $this->safeNode($xml); $visit($xml); }
            $this->checkXmlErrors();
        } finally {
            $xml->close();
            if (is_resource($stream)) { fclose($stream); }
        }
    }

    private function safeNode(XMLReader $xml): void
    {
        if (in_array($xml->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
            throw new DomainException('Workbook XML must not contain document types or entity references.');
        }
    }

    private function checkXmlErrors(): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) { throw new DomainException('The workbook contains unreadable XML. Save a fresh .xlsx copy and retry.'); }
        }
        libxml_clear_errors();
    }
}
