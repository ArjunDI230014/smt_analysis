<?php

namespace App\Libraries;

use DomainException;
use JsonException;

/** Read-only Sheets API client. Authentication destinations never come from uploaded credentials. */
class GoogleSheetsClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_URL = 'https://sheets.googleapis.com/v4/spreadsheets/';
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';
    private const MAX_RESPONSE_BYTES = 12 * 1024 * 1024;
    private const ROWS_PER_CHUNK = 5000;
    private const MAX_GRID_ROWS = 200000;
    private $transport;

    public function __construct(private ?string $credentialsPath = null, ?callable $transport = null)
    {
        $this->credentialsPath ??= config('GoogleSheets')->credentialsPath();
        $this->transport = $transport;
    }

    public function credentialsStatus(): array
    {
        try {
            $credentials = $this->credentials();
            return ['credentials_ready' => true, 'service_email' => $credentials['client_email'], 'credentials_error' => null];
        } catch (DomainException $e) {
            return ['credentials_ready' => false, 'service_email' => null, 'credentials_error' => $e->getMessage()];
        }
    }

    public static function validateCredentialsJson(string $json): array
    {
        $credentials = self::decodeCredentials($json);
        return ['service_email' => $credentials['client_email']];
    }

    private static function decodeCredentials(string $json): array
    {
        if (strlen($json) > 65536) {
            throw new DomainException('Use a Google service-account JSON key smaller than 64 KB.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('The credentials file is not valid service-account JSON.');
        }
        if (!is_array($data) || ($data['type'] ?? '') !== 'service_account'
            || !is_string($data['client_email'] ?? null) || !filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)
            || strlen($data['client_email']) > 190 || !is_string($data['private_key'] ?? null)) {
            throw new DomainException('Use a Google service-account JSON key containing client_email and private_key.');
        }
        if (!function_exists('openssl_pkey_get_private')) {
            throw new DomainException('Enable the PHP OpenSSL extension on the server.');
        }
        $key = @openssl_pkey_get_private($data['private_key']);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new DomainException('The service-account JSON must contain a valid RSA private key of at least 2048 bits.');
        }
        return $data;
    }

    private function credentials(): array
    {
        $path = $this->credentialsPath;
        if (!is_string($path) || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $path)
            || !is_file($path) || !is_readable($path)) {
            throw new DomainException('Add a service-account JSON key in Google Sheets settings, or set GOOGLE_SHEETS_CREDENTIALS to its absolute server path.');
        }
        if (filesize($path) > 65536) {
            throw new DomainException('Use a Google service-account JSON key smaller than 64 KB.');
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new DomainException('The server cannot read the service-account JSON key. Check its file permissions.');
        }
        return self::decodeCredentials($json);
    }

    public function fetch(string $spreadsheetId, int $gid): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,160}$/D', $spreadsheetId)) {
            throw new DomainException('Enter a valid Google spreadsheet ID.');
        }
        // Keep the gid argument for existing callers; only the exact required tab is a source.
        $requiredSheetName = config('GoogleSheets')->requiredSheetName;
        $credentials = $this->credentials();
        $now = time();
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $encode(json_encode(['iss' => $credentials['client_email'], 'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600], JSON_THROW_ON_ERROR));
        $unsigned = $header . '.' . $claims;
        if (!@openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new DomainException('The server could not sign in with this service-account key. Replace the JSON key and retry.');
        }
        $token = $this->request(self::TOKEN_URL, [], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $encode($signature),
        ]), 65536);
        if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '' || preg_match('/[\r\n]/', $token['access_token'])) {
            throw new DomainException('Google sign-in did not return an access token. Check the service-account key and retry.');
        }
        $headers = ['Authorization: Bearer ' . $token['access_token']];
        $base = self::API_URL . rawurlencode($spreadsheetId);
        $metadata = $this->request($base . '?fields=' . rawurlencode('sheets(properties(title,sheetType,gridProperties(rowCount,columnCount)))'), $headers, null, 262144);
        $title = null;
        $grid = [];
        foreach ($metadata['sheets'] ?? [] as $sheet) {
            $properties = $sheet['properties'] ?? [];
            if (($properties['title'] ?? null) === $requiredSheetName) {
                if (($properties['sheetType'] ?? 'GRID') !== 'GRID') {
                    throw new DomainException('The required worksheet "' . $requiredSheetName . '" must be a normal grid worksheet. The last successful report was kept.');
                }
                $title = $requiredSheetName;
                $grid = $properties['gridProperties'] ?? [];
                break;
            }
        }
        if (!is_string($title) || $title === '') {
            throw new DomainException('The spreadsheet must contain a worksheet named exactly "' . $requiredSheetName . '". The last successful report was kept.');
        }
        $rowCount = filter_var($grid['rowCount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $columnCount = filter_var($grid['columnCount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($rowCount === false || $columnCount === false) {
            throw new DomainException('Google did not return valid dimensions for "' . $title . '". Retry shortly.');
        }
        if ($rowCount > self::MAX_GRID_ROWS) {
            throw new DomainException('The worksheet "' . $title . '" has more than 200,000 allocated rows. Trim unused rows before syncing. The last successful report was kept.');
        }
        $sheetRange = "'" . str_replace("'", "''", $title) . "'";
        $options = '?majorDimension=ROWS&valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=SERIAL_NUMBER';
        $headerResult = $this->request($base . '/values/' . rawurlencode($sheetRange . '!A1:' . $this->columnName(min(100, $columnCount)) . '1') . $options,
            $headers, null, self::MAX_RESPONSE_BYTES);
        $headings = $headerResult['values'][0] ?? null;
        if (!is_array($headings) || $headings === []) {
            throw new DomainException('The worksheet "' . $requiredSheetName . '" is empty. Add its source column headings and response rows. The last successful report was kept.');
        }
        $lastNamedColumn = null;
        foreach ($headings as $index => $heading) {
            if ($heading !== null && !is_scalar($heading)) {
                throw new DomainException('The worksheet needs valid column headings in row 1.');
            }
            if (trim((string) $heading) !== '') { $lastNamedColumn = $index + 1; }
        }
        if ($lastNamedColumn === null || $lastNamedColumn > 100) {
            throw new DomainException('The worksheet needs column headings within the first 100 columns.');
        }
        $lastColumn = $this->columnName($lastNamedColumn);
        $rows = function () use ($base, $headers, $options, $sheetRange, $lastColumn, $rowCount): \Generator {
            for ($start = 2; $start <= $rowCount; $start += self::ROWS_PER_CHUNK) {
                $end = min($rowCount, $start + self::ROWS_PER_CHUNK - 1);
                $range = $sheetRange . '!A' . $start . ':' . $lastColumn . $end;
                $result = $this->request($base . '/values/' . rawurlencode($range) . $options, $headers, null, self::MAX_RESPONSE_BYTES);
                $chunk = $result['values'] ?? [];
                if (!is_array($chunk) || count($chunk) > $end - $start + 1) {
                    throw new DomainException('Google returned invalid response rows. The last successful report was kept.');
                }
                foreach ($chunk as $offset => $cells) {
                    if (!is_int($offset) || $offset < 0 || $offset > $end - $start) {
                        throw new DomainException('Google returned invalid response row numbers. The last successful report was kept.');
                    }
                    yield $start + $offset => $cells;
                }
            }
        };
        return ['title' => $title, 'headers' => array_slice($headings, 0, $lastNamedColumn), 'rows' => $rows()];
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + $column % 26) . $name;
            $column = intdiv($column, 26);
        }
        return $name;
    }

    private function request(string $url, array $headers, ?string $body, int $limit): array
    {
        // This check also protects injected transports from accidental arbitrary URL use.
        if ($url !== self::TOKEN_URL && !str_starts_with($url, self::API_URL)) {
            throw new DomainException('The Google API endpoint is invalid.');
        }
        if ($this->transport) {
            $response = ($this->transport)($url, $headers, $body, $limit);
            $status = (int) ($response['status'] ?? 0);
            $text = (string) ($response['body'] ?? '');
        } else {
            if (!function_exists('curl_init')) {
                throw new DomainException('Enable the PHP cURL extension on the server.');
            }
            $curl = curl_init($url);
            $text = '';
            $overflow = false;
            curl_setopt_array($curl, [CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$text, &$overflow, $limit): int {
                    if (strlen($text) + strlen($chunk) > $limit) { $overflow = true; return 0; }
                    $text .= $chunk;
                    return strlen($chunk);
                },
            ]);
            // Windows PHP builds using OpenSSL do not automatically load the OS root store.
            // Use its maintained certificates while retaining peer and hostname verification.
            if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
                && version_compare(curl_version()['version'], '7.71.0', '>=')) {
                curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            }
            if ($body !== null) {
                curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => array_merge($headers, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'])]);
            }
            $ok = curl_exec($curl);
            $curlError = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($overflow) {
                throw new DomainException('The Google Sheets response is too large. Keep each response chunk below 12 MB. The last successful report was kept.');
            }
            if ($ok === false) {
                throw new DomainException(match ($curlError) {
                    CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT_BADFILE => 'The server could not verify Google’s security certificate. Ask your administrator to check its trusted CA certificates, then retry.',
                    CURLE_COULDNT_RESOLVE_HOST => 'The server could not find Google’s API address. Check its internet connection and DNS settings, then retry.',
                    CURLE_COULDNT_CONNECT => 'The server could not connect to Google. Check its internet connection or firewall, then retry.',
                    CURLE_OPERATION_TIMEDOUT => 'The connection to Google timed out. Retry shortly.',
                    default => 'The server could not reach Google securely. Check its internet connection and trusted CA certificates, then retry.',
                });
            }
        }
        if (strlen($text) > $limit) {
            throw new DomainException('The Google Sheets response is too large. Keep each response chunk below 12 MB. The last successful report was kept.');
        }
        if ($status < 200 || $status >= 300) {
            $message = match ($status) {
                401 => 'Google rejected the service-account key. Replace it with an active JSON key and retry.',
                403 => 'Google denied access. Enable the Google Sheets API and share this spreadsheet with the service-account email as Viewer.',
                404 => 'The spreadsheet was not found. Check its link and share it with the service-account email as Viewer.',
                429 => 'Google temporarily limited requests. Wait a minute and retry.',
                default => $url === self::TOKEN_URL
                    ? 'Google sign-in failed. Check that the service-account key is active and the server clock is correct.'
                    : 'Google could not read the worksheet. Check the spreadsheet and retry shortly.',
            };
            throw new DomainException($message);
        }
        try {
            // Quoted IDs remain strings. Numeric cells remain numeric so unsafe rounded IDs are rejected.
            $result = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('Google returned an unreadable response. Retry shortly.');
        }
        if (!is_array($result)) {
            throw new DomainException('Google returned an unreadable response. Retry shortly.');
        }
        return $result;
    }
}
