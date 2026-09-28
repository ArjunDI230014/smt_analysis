<?php

namespace Tests\Unit;

use App\Libraries\DailyCsatImporter;
use CodeIgniter\Test\CIUnitTestCase;
use DomainException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class DailyCsatImporterTest extends CIUnitTestCase
{
    private array $files = [];
    private const HEADERS = ['Date', 'Team', 'Good', 'Bad', 'agent_name', 'csat_id', 'csat_submission_channel_name', 'is_excluded', 'handling_duration_secs'];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function workbook(?callable $change = null): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Daily CSAT');
        $sheet->fromArray([self::HEADERS, [46271, 'Team A', 1, 0, 'Agent One', '2096599610335211576', 'Chat', 1, 120], [46271, 'Team B', 0, 1, 'Agent Two', '2096599610335211577', 'Email', 0, null]], null, 'A1', true);
        $sheet->setCellValueExplicit('F2', '2096599610335211576', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F3', '2096599610335211577', DataType::TYPE_STRING);
        // Invalid response rows on an unrelated sheet must never be imported or validated.
        $book->createSheet()->setTitle('Ignore me')->fromArray([self::HEADERS, ['not a date', 'INVALID', 99, 99, 'Ignored', 'x', 'Other', 4, -99]]);
        $change && $change($sheet);
        $file = tempnam(sys_get_temp_dir(), 'csat_test_');
        $this->files[] = $file;
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();
        return $file;
    }

    public function testOnlyDailyCsatIsReadWithExactIdsAndFlaggedRows(): void
    {
        $rows = (new DailyCsatImporter())->read($this->workbook());
        self::assertCount(2, $rows);
        self::assertSame('2026-09-06', $rows[0]['response_date']);
        self::assertSame('2096599610335211576', $rows[0]['csat_id']);
        self::assertSame(1, $rows[0]['is_excluded']);
        self::assertSame(120.0, $rows[0]['handling_seconds']);
        self::assertNull($rows[1]['handling_seconds']);
    }

    public function testMissingExactSheetFails(): void
    {
        $file = $this->workbook(static fn ($sheet) => $sheet->setTitle('daily csat'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('named exactly');
        (new DailyCsatImporter())->read($file);
    }

    public function testMissingHeaderFails(): void
    {
        $file = $this->workbook(static fn ($sheet) => $sheet->setCellValue('A1', 'Wrong date column'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Missing columns');
        (new DailyCsatImporter())->read($file);
    }

    public function testInvalidRatingFails(): void
    {
        $file = $this->workbook(static fn ($sheet) => $sheet->setCellValue('D2', 1));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exactly one rating');
        (new DailyCsatImporter())->read($file);
    }

    public function testDuplicateResponseFails(): void
    {
        $file = $this->workbook(static fn ($sheet) => $sheet->setCellValueExplicit('F3', '2096599610335211576', DataType::TYPE_STRING));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('duplicated');
        (new DailyCsatImporter())->read($file);
    }

    public function testInvalidDateFails(): void
    {
        $file = $this->workbook(static fn ($sheet) => $sheet->setCellValue('A2', '2026-02-31'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Date must be');
        (new DailyCsatImporter())->read($file);
    }

    public function testRowPastLimitIsNotSilentlyTruncated(): void
    {
        $file = $this->workbook(static fn ($sheet) => $sheet->setCellValue('A20000', 46271));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('limit');
        (new DailyCsatImporter())->read($file);
    }
}
