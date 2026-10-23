<?php

namespace Tests\Unit;

use App\Libraries\GoogleSheetsRows;
use CodeIgniter\Test\CIUnitTestCase;
use DomainException;

final class GoogleSheetsRowsTest extends CIUnitTestCase
{
    private function values(): array
    {
        return [['Date', 'Team', 'Good', 'Bad', 'agent_name', 'csat_id', 'csat_submission_channel_name', 'is_excluded', 'agent_email', 'Reason', 'Comment', 'Case'],
            [46271, 'Support', 0, 1, 'Agent One', '2096599610335211576', 'Chat', 0, 'AGENT@example.test', 'Late response', 'I had to wait', '000123']];
    }

    public function testExactDuplicatesAreSkippedAndMappingsPreserveSourceAndIds(): void
    {
        $values = $this->values();
        $values[] = $values[1];
        $result = (new GoogleSheetsRows())->normalize($values, ['reason_column' => 'Reason', 'comment_column' => 'Comment', 'case_column' => 'Case']);
        self::assertCount(1, $result['rows']);
        self::assertSame(1, $result['duplicate_count']);
        $row = $result['rows'][0];
        self::assertSame('2096599610335211576', $row['csat_id']);
        self::assertSame('2026-09-06', $row['response_date']);
        self::assertSame('agent@example.test', $row['agent_email']);
        self::assertSame(2, $row['source_row']);
        $raw = json_decode($row['raw_json'], true);
        self::assertSame($raw['Reason'], $raw['dsat_reason']);
        self::assertSame($raw['Comment'], $raw['customer_comment']);
        self::assertSame('000123', $raw['case_id']);
    }

    public function testActualRawDataHeadersSupplyDateTeamReasonAndCaseWithoutInventingComment(): void
    {
        $values = $this->rawValues();
        $result = (new GoogleSheetsRows())->normalize($values);
        $row = $result['rows'][0];
        self::assertSame('2026-09-06', $row['response_date']);
        self::assertSame('Raw Support', $row['team']);
        self::assertSame('2096599610335211576', $row['csat_id']);
        self::assertSame('raw.agent@example.test', $row['agent_email']);
        self::assertSame(25.5, $row['handling_seconds']);
        $raw = json_decode($row['raw_json'], true);
        self::assertSame('Late response', $raw['csat_reason']);
        self::assertSame('Late response', $raw['dsat_reason']);
        self::assertSame('0000123', $raw['case_id']);
        self::assertSame('2099-01-01', $raw['report_date']);
        self::assertSame(46271, $raw['CSAT Submit Date']);
        self::assertSame('Raw Support', $raw['agent_owner_team']);
        self::assertArrayNotHasKey('customer_comment', $raw);
        self::assertArrayNotHasKey('Date', $raw);
        self::assertArrayNotHasKey('Team', $raw);
    }

    public function testRawSourceHeadersTakePrecedenceWithoutOverwritingOtherOriginalColumns(): void
    {
        $values = $this->rawValues();
        $values[0][0] = 'Date';
        $values[0][1] = 'Team';
        $values[1][0] = '2040-01-01';
        $values[1][1] = 'Other team label';
        $row = (new GoogleSheetsRows())->normalize($values)['rows'][0];
        self::assertSame('2026-09-06', $row['response_date']);
        self::assertSame('Raw Support', $row['team']);
        $raw = json_decode($row['raw_json'], true);
        self::assertSame('2040-01-01', $raw['Date']);
        self::assertSame('Other team label', $raw['Team']);
    }

    public function testIterableRowsRetainActualWorksheetRowNumbersAndDuplicateProvenance(): void
    {
        [$headers, $row] = $this->rawValues();
        $rows = static function () use ($row): \Generator {
            yield 203 => $row;
            yield 409 => array_fill(0, 53, null);
            yield 500 => $row;
        };
        $result = (new GoogleSheetsRows())->normalizeRows($rows(), $headers);
        self::assertCount(1, $result['rows']);
        self::assertSame(203, $result['rows'][0]['source_row']);
        self::assertSame(1, $result['duplicate_count']);
    }

    public function testRawInclusionFlagIsAuthoritativeAndOriginalExclusionIsPreserved(): void
    {
        $values = $this->rawValues();
        $values[0][5] = 'Inclulde in CSAT?';
        $values[1][5] = 1;
        $values[1][45] = 1;
        $values[] = $values[1];
        $values[2][29] = 'second-survey';
        $values[2][5] = 0;
        $values[2][45] = null;
        $rows = (new GoogleSheetsRows())->normalize($values)['rows'];
        self::assertSame(0, $rows[0]['is_excluded']);
        self::assertSame(1, json_decode($rows[0]['raw_json'], true)['is_excluded']);
        self::assertSame(0, json_decode($rows[0]['raw_json'], true)['csat_report_is_excluded']);
        self::assertSame(1, $rows[1]['is_excluded']);
        self::assertNull(json_decode($rows[1]['raw_json'], true)['is_excluded']);
    }

    public function testRawSchemaRequiresItsAuthoritativeInclusionHeadingBeforeReadingRows(): void
    {
        [$headers, $row] = $this->rawValues();
        $headers[5] = '';
        $consumed = false;
        $rows = static function () use ($row, &$consumed): \Generator {
            $consumed = true;
            yield 2 => $row;
        };
        try {
            (new GoogleSheetsRows())->normalizeRows($rows(), $headers);
            self::fail('The raw schema must never fall back to the unrelated is_excluded field.');
        } catch (DomainException $e) {
            self::assertStringContainsString('Missing required columns', $e->getMessage());
            self::assertStringContainsString('Inclulde in CSAT?', $e->getMessage());
        }
        self::assertFalse($consumed, 'Invalid raw headings should fail before any response rows are fetched.');
    }

    public function testReasonCodesAreDecodedIndividuallyAndUnknownValuesStayVerbatim(): void
    {
        $values = $this->rawValues();
        $values[1][33] = '1, 3,2,4,1';
        $values[] = $values[1];
        $values[2][29] = 'unknown-reason';
        $values[2][33] = '5';
        $values[] = $values[1];
        $values[3][29] = 'blank-reason';
        $values[3][33] = '';
        $values[] = $values[1];
        $values[4][29] = 'numeric-reason';
        $values[4][33] = 1.0;
        $rows = (new GoogleSheetsRows())->normalize($values)['rows'];
        $first = json_decode($rows[0]['raw_json'], true);
        self::assertSame('1, 3,2,4,1', $first['csat_reason']);
        self::assertSame(['Long Resolution Time', 'Company Policy can be improved',
            'Agent attitude can be improved', 'Courier can be improved'], $first['dsat_reasons']);
        self::assertSame(implode('; ', $first['dsat_reasons']), $first['dsat_reason']);
        self::assertSame('5', json_decode($rows[1]['raw_json'], true)['dsat_reason']);
        self::assertSame([], json_decode($rows[1]['raw_json'], true)['dsat_reasons']);
        self::assertSame('', json_decode($rows[2]['raw_json'], true)['dsat_reason']);
        self::assertSame(['Long Resolution Time'], json_decode($rows[3]['raw_json'], true)['dsat_reasons']);
        self::assertArrayNotHasKey('customer_comment', $first);
    }

    public function testRosterUsesLatestNameForEmailAndRowsKeepEveryOriginalName(): void
    {
        $values = $this->rawValues();
        $values[1][21] = 'Previous Name';
        $values[] = $values[1];
        $values[2][29] = 'new-name-survey';
        $values[2][21] = 'Latest Name';
        $values[2][2] = 46275;
        $values[2][30] = 46275.75;
        $values[] = $values[1];
        $values[3][29] = 'middle-name-survey';
        $values[3][21] = 'Earlier Same Day';
        $values[3][2] = 46275;
        $values[3][30] = 46275.5;
        $result = (new GoogleSheetsRows())->normalize($values);
        self::assertSame(['Previous Name', 'Latest Name', 'Earlier Same Day'], array_column($result['rows'], 'agent_name'));
        self::assertSame([['email' => 'raw.agent@example.test', 'name' => 'Latest Name']], $result['identities']);
        self::assertSame(2, $result['identity_variants']);
    }

    public function testInvalidOptionalDurationDoesNotDiscardDsatAndPreservesSourceText(): void
    {
        $values = $this->rawValues();
        $values[1][44] = 'Source text sentinel';
        $values[] = $values[1];
        $result = (new GoogleSheetsRows())->normalize($values);
        self::assertCount(1, $result['rows']);
        self::assertNull($result['rows'][0]['handling_seconds']);
        self::assertSame('Source text sentinel', json_decode($result['rows'][0]['raw_json'], true)['handling_duration_secs']);
        self::assertSame(1, $result['invalid_duration_count']);
        self::assertSame(1, $result['duplicate_count']);
    }

    public function testConflictingIdRejectsInsteadOfKeepingArbitraryWinner(): void
    {
        $values = $this->values();
        $values[] = $values[1];
        $values[2][9] = 'Different reason';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('conflicts');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testL1CategoryIsNotInferredAsDsatReason(): void
    {
        $values = $this->values();
        $values[0][] = 'l1_name';
        $values[1][] = 'Payments';
        $result = (new GoogleSheetsRows())->normalize($values);
        self::assertSame('Payments', $result['rows'][0]['category']);
        self::assertArrayNotHasKey('dsat_reason', json_decode($result['rows'][0]['raw_json'], true));
    }

    public function testMissingRequiredHeaderFails(): void
    {
        $values = $this->values();
        $values[0][0] = 'Wrong';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Missing required columns');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testUnknownMappingFailsClearly(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mapped');
        (new GoogleSheetsRows())->normalize($this->values(), ['reason_column' => 'Not here']);
    }

    public function testRoundedNumericIdentifierIsRejected(): void
    {
        $values = $this->values();
        $values[1][5] = 2096599610335211576.0;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('plain text');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testSafeIntegralFloatIdsKeepEveryDigit(): void
    {
        $values = $this->values();
        $values[1][5] = 123456789012345.0;
        $values[1][11] = 9007199254740991.0;
        $result = (new GoogleSheetsRows())->normalize($values, ['case_column' => 'Case']);
        self::assertSame('123456789012345', $result['rows'][0]['csat_id']);
        self::assertSame('9007199254740991', json_decode($result['rows'][0]['raw_json'], true)['case_id']);
    }

    public function testCanonicalMappingCollisionsUseOriginalSourceValues(): void
    {
        $values = $this->values();
        $values[0][9] = 'dsat_reason';
        $values[0][10] = 'customer_comment';
        $values[1][9] = 'Original reason';
        $values[1][10] = 'Original comment';
        $result = (new GoogleSheetsRows())->normalize($values, ['reason_column' => 'customer_comment', 'comment_column' => 'dsat_reason', 'case_column' => 'customer_comment']);
        $raw = json_decode($result['rows'][0]['raw_json'], true);
        self::assertSame('Original comment', $raw['dsat_reason']);
        self::assertSame('Original reason', $raw['customer_comment']);
        self::assertSame('Original comment', $raw['case_id']);
    }

    public function testInvalidCalendarDateFails(): void
    {
        $values = $this->values();
        $values[1][0] = '2026-02-31';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Date must');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testInvalidRatingFails(): void
    {
        $values = $this->values();
        $values[1][2] = 1;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exactly one rating');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testRowAndColumnBoundsNeverSilentlyTruncate(): void
    {
        $values = $this->values();
        $values[0][100] = 'Extra named column';
        $values[1][100] = 'Hidden data';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('100-column');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testDuplicateRowsStillCountTowardInputLimit(): void
    {
        $values = $this->values();
        $values = array_merge([$values[0]], array_fill(0, GoogleSheetsRows::MAX_ROWS + 1, $values[1]));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('100,000');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testHelperCellsAfterTheLastNamedHeadingAreOutsideTheRecord(): void
    {
        $values = $this->values();
        $values[1][65] = 'Derived helper';
        $values[] = [65 => 'Helper without a response'];
        $result = (new GoogleSheetsRows())->normalize($values);
        self::assertCount(1, $result['rows']);
        self::assertStringNotContainsString('Derived helper', $result['rows'][0]['raw_json']);
    }

    public function testPopulatedUnnamedColumnWithinRecordStillRejectsTheSource(): void
    {
        $values = $this->values();
        $values[0][9] = '';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('populated column has no heading');
        (new GoogleSheetsRows())->normalize($values);
    }

    public function testMissingIdOnMaterialRowIsNeverTreatedAsABlankTemplate(): void
    {
        $values = $this->rawValues();
        $values[1][29] = '';
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('csat_id is required');
        (new GoogleSheetsRows())->normalize($values);
    }

    private function rawValues(): array
    {
        $headers = array_fill(0, 53, '');
        $cells = array_fill(0, 53, null);
        foreach ([
            2 => ['CSAT Submit Date', 46271], 5 => ['Inclulde in CSAT?', 1], 6 => ['Good', 0], 7 => ['Bad', 1],
            14 => ['case_id', '0000123'], 21 => ['agent_name', 'Raw Agent'],
            22 => ['agent_email', 'RAW.AGENT@example.test'], 23 => ['agent_owner_team', 'Raw Support'],
            29 => ['csat_id', '2096599610335211576'], 30 => ['csat_submit_timestamp', 46271.5],
            31 => ['csat_submission_channel_name', 'Chat'], 32 => ['csat_level', 'Bad'],
            33 => ['csat_reason', 'Late response'], 34 => ['report_date', '2099-01-01'],
            44 => ['handling_duration_secs', 25.5], 45 => ['is_excluded', 0],
        ] as $column => [$heading, $value]) {
            $headers[$column] = $heading;
            $cells[$column] = $value;
        }
        return [$headers, $cells];
    }
}
