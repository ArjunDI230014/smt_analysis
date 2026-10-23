<?php

namespace Tests\Unit;

use App\Libraries\GoogleSheetsClient;
use CodeIgniter\Test\CIUnitTestCase;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;

final class GoogleSheetsClientTest extends CIUnitTestCase
{
    private string $path;
    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $opensslConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($opensslConfig)) { $options['config'] = $opensslConfig; }
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $this->privateKey, null, $options);
        $this->path = tempnam(sys_get_temp_dir(), 'sheet_credentials_');
        file_put_contents($this->path, json_encode(['type' => 'service_account', 'client_email' => 'sheets@example.test',
            'private_key' => $this->privateKey, 'token_uri' => 'https://untrusted.invalid/steal']));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) { unlink($this->path); }
        parent::tearDown();
    }

    public function testReadonlyJwtUsesOnlyExactRawDataTabEvenWhenGidPointsElsewhere(): void
    {
        $calls = [];
        $transport = function (string $url, array $headers, ?string $body, int $limit) use (&$calls): array {
            $calls[] = compact('url', 'headers', 'body', 'limit');
            return match (count($calls)) {
                1 => ['status' => 200, 'body' => '{"access_token":"fake-token"}'],
                2 => ['status' => 200, 'body' => '{"sheets":[{"properties":{"sheetId":2030175176,"title":"Agent\u0027s CSAT","sheetType":"GRID"}},{"properties":{"sheetId":99,"title":"Raw Data CSAT","sheetType":"GRID","gridProperties":{"rowCount":2,"columnCount":1}}},{"properties":{"sheetId":100,"title":"Summary","sheetType":"GRID"}}]}'],
                3 => ['status' => 200, 'body' => '{"values":[["csat_id"]]}'],
                4 => ['status' => 200, 'body' => '{"values":[["2096599610335211576"]]}'],
            };
        };
        $client = new GoogleSheetsClient($this->path, $transport);
        $result = $client->fetch('1HFE5cyls6xn_uIhG9XaGE7ZH0FbSax-JNjLxEYFkPH4', 2030175176);
        self::assertSame('Raw Data CSAT', config('GoogleSheets')->requiredSheetName);
        self::assertSame('Raw Data CSAT', $result['title']);
        self::assertSame(['csat_id'], $result['headers']);
        $rows = iterator_to_array($result['rows']);
        self::assertSame('2096599610335211576', $rows[2][0]);
        self::assertSame('https://oauth2.googleapis.com/token', $calls[0]['url']);
        parse_str($calls[0]['body'], $body);
        [$header, $claims, $signature] = explode('.', $body['assertion']);
        $decode = static fn ($value) => base64_decode(strtr($value, '-_', '+/'));
        $payload = json_decode($decode($claims), true);
        self::assertSame('https://www.googleapis.com/auth/spreadsheets.readonly', $payload['scope']);
        self::assertSame('https://oauth2.googleapis.com/token', $payload['aud']);
        self::assertSame(3600, $payload['exp'] - $payload['iat']);
        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey))['key'];
        self::assertSame(1, openssl_verify($header . '.' . $claims, $decode($signature), $publicKey, OPENSSL_ALGO_SHA256));
        self::assertStringContainsString('/values/' . rawurlencode("'Raw Data CSAT'!A1:A1") . '?', $calls[2]['url']);
        self::assertStringContainsString('/values/' . rawurlencode("'Raw Data CSAT'!A2:A2") . '?', $calls[3]['url']);
        self::assertCount(2, array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/values/')));
        self::assertStringNotContainsString('Agent', $calls[2]['url']);
        self::assertStringContainsString('UNFORMATTED_VALUE', $calls[2]['url']);
        self::assertNull($calls[1]['body']);
        self::assertNull($calls[2]['body']);
        self::assertSame(['credentials_ready' => true, 'service_email' => 'sheets@example.test', 'credentials_error' => null], $client->credentialsStatus());
        self::assertSame(['service_email' => 'sheets@example.test'], GoogleSheetsClient::validateCredentialsJson(file_get_contents($this->path)));
    }

    public function testApiErrorsCannotLeakResponseOrCredentials(): void
    {
        $calls = 0;
        $client = new GoogleSheetsClient($this->path, static function () use (&$calls): array {
            return ++$calls === 1 ? ['status' => 200, 'body' => '{"access_token":"secret-token"}']
                : ['status' => 403, 'body' => '{"error":"secret-token and internal detail"}'];
        });
        try {
            $client->fetch(str_repeat('a', 25), 1);
            self::fail('Access denial must fail.');
        } catch (DomainException $e) {
            self::assertStringContainsString('share this spreadsheet', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
            self::assertStringNotContainsString('internal detail', $e->getMessage());
        }
    }

    #[DataProvider('missingExactTabNames')]
    public function testMissingExactRawDataTabFailsBeforeReadingAnyValues(string $otherTitle): void
    {
        $calls = [];
        $client = new GoogleSheetsClient($this->path, static function (string $url) use (&$calls, $otherTitle): array {
            $calls[] = $url;
            return count($calls) === 1
                ? ['status' => 200, 'body' => '{"access_token":"fake-token"}']
                : ['status' => 200, 'body' => json_encode(['sheets' => [['properties' => [
                    'sheetId' => 2030175176, 'title' => $otherTitle, 'sheetType' => 'GRID',
                ]]]])];
        });
        try {
            $client->fetch(str_repeat('a', 25), 2030175176);
            self::fail('A matching gid must not permit a different source tab.');
        } catch (DomainException $e) {
            self::assertStringContainsString('named exactly "Raw Data CSAT"', $e->getMessage());
            self::assertStringContainsString('last successful report was kept', $e->getMessage());
        }
        self::assertCount(2, $calls);
        self::assertSame([], array_values(array_filter($calls, static fn (string $url): bool => str_contains($url, '/values/'))));
    }

    public static function missingExactTabNames(): array
    {
        return [["Agent's CSAT"], ['Raw data CSAT'], ['raw data csat'], ['Raw Data CSAT ']];
    }

    public function testRequiredTabMustBeAGridBeforeAnyValuesAreRead(): void
    {
        $calls = 0;
        $client = new GoogleSheetsClient($this->path, static function () use (&$calls): array {
            return ++$calls === 1 ? ['status' => 200, 'body' => '{"access_token":"fake-token"}']
                : ['status' => 200, 'body' => '{"sheets":[{"properties":{"title":"Raw Data CSAT","sheetType":"OBJECT"}}]}'];
        });
        try {
            $client->fetch(str_repeat('a', 25), 1);
            self::fail('Only a grid tab can contain response rows.');
        } catch (DomainException $e) {
            self::assertStringContainsString('"Raw Data CSAT" must be a normal grid', $e->getMessage());
        }
        self::assertSame(2, $calls);
    }

    public function testOversizedResponseIsRejected(): void
    {
        $client = new GoogleSheetsClient($this->path, static fn () => ['status' => 200, 'body' => str_repeat('x', 65537)]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('too large');
        $client->fetch(str_repeat('a', 25), 1);
    }

    public function testNonServiceAccountCredentialsAreRejected(): void
    {
        file_put_contents($this->path, '{"type":"authorized_user","refresh_token":"secret"}');
        $status = (new GoogleSheetsClient($this->path))->credentialsStatus();
        self::assertFalse($status['credentials_ready']);
        self::assertStringNotContainsString('secret', json_encode($status));
    }

    public function testNumericCellsStayNumericSoPrecisionValidationCanRejectThem(): void
    {
        $calls = 0;
        $client = new GoogleSheetsClient($this->path, static function () use (&$calls): array {
            return match (++$calls) {
                1 => ['status' => 200, 'body' => '{"access_token":"fake-token"}'],
                2 => ['status' => 200, 'body' => '{"sheets":[{"properties":{"sheetId":1,"title":"Raw Data CSAT","gridProperties":{"rowCount":2,"columnCount":1}}}]}'],
                3 => ['status' => 200, 'body' => '{"values":[["csat_id"]]}'],
                4 => ['status' => 200, 'body' => '{"values":[[209659961033521157600]]}'],
            };
        });
        $result = $client->fetch(str_repeat('a', 25), 1);
        $rows = iterator_to_array($result['rows']);
        self::assertIsFloat($rows[2][0]);
    }

    public function testRowsStreamThroughEmptyChunksAndIgnoreColumnsAfterLastNamedHeading(): void
    {
        $calls = [];
        $headings = array_fill(0, 53, '');
        $headings[0] = 'csat_id';
        $headings[52] = 'last_field';
        $client = new GoogleSheetsClient($this->path, static function (string $url) use (&$calls, $headings): array {
            $calls[] = $url;
            return ['status' => 200, 'body' => match (count($calls)) {
                1 => '{"access_token":"fake-token"}',
                2 => '{"sheets":[{"properties":{"title":"Raw Data CSAT","gridProperties":{"rowCount":15002,"columnCount":66}}}]}',
                3 => json_encode(['values' => [$headings]]),
                4 => '{"values":[["first-response"]]}',
                5 => '{}',
                6 => '{"values":[["later-response"]]}',
                7 => '{}',
            }];
        });
        $result = $client->fetch(str_repeat('a', 25), 123);
        self::assertCount(3, $calls, 'Response rows should not be buffered before the iterator is consumed.');
        self::assertCount(53, $result['headers']);
        $rows = iterator_to_array($result['rows']);
        self::assertSame([2, 10002], array_keys($rows));
        self::assertSame('later-response', $rows[10002][0]);
        self::assertCount(7, $calls);
        foreach ([3 => '!A2:BA5001', 4 => '!A5002:BA10001', 5 => '!A10002:BA15001', 6 => '!A15002:BA15002'] as $index => $range) {
            self::assertStringContainsString(rawurlencode("'Raw Data CSAT'" . $range), $calls[$index]);
        }
        self::assertStringContainsString(rawurlencode("'Raw Data CSAT'!A1:BN1"), $calls[2]);
    }

    public function testOversizedPhysicalGridIsRejectedBeforeReadingHeadersOrRows(): void
    {
        $calls = 0;
        $client = new GoogleSheetsClient($this->path, static function () use (&$calls): array {
            return ++$calls === 1 ? ['status' => 200, 'body' => '{"access_token":"fake-token"}']
                : ['status' => 200, 'body' => '{"sheets":[{"properties":{"title":"Raw Data CSAT","gridProperties":{"rowCount":200001,"columnCount":53}}}]}'];
        });
        try {
            $client->fetch(str_repeat('a', 25), 1);
            self::fail('A grid beyond the physical limit must be rejected without truncation.');
        } catch (DomainException $e) {
            self::assertStringContainsString('200,000 allocated rows', $e->getMessage());
        }
        self::assertSame(2, $calls);
    }
}
