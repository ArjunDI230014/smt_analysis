<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

/** One managed report per module, replaced atomically after a complete successful read and validation. */
class GoogleSheetsSync
{
    private BaseConnection $db;
    private GoogleSheetsClient $client;
    private string $lockPath;
    private int $sourceId;

    public function __construct(?BaseConnection $db = null, ?GoogleSheetsClient $client = null, ?string $lockPath = null, private string $module = 'shopee-food')
    {
        $this->module = Modules::validate($module);
        $this->sourceId = $module === 'marketplace' ? 2 : 1;
        $this->db = $db ?? db_connect();
        $this->client = $client ?? new GoogleSheetsClient(config('GoogleSheets')->credentialsPath($module));
        // Different databases must not block each other's connection settings or refreshes.
        $databaseIdentity = $this->db->DBDriver . '|' . $this->db->hostname . '|' . $this->db->port . '|'
            . $this->db->database . '|' . $this->db->DBPrefix;
        if ($this->db->database === ':memory:') { $databaseIdentity .= '|' . getmypid(); }
        $this->lockPath = $lockPath ?? WRITEPATH . 'private/google-sheets-sync-' . substr(hash('sha256', $databaseIdentity . '|' . $module), 0, 20) . '.lock';
    }

    public function status(): array
    {
        $source = $this->source();
        if ($source !== null) {
            foreach (['id', 'refresh_minutes', 'configured_by', 'row_count', 'duplicate_count'] as $field) {
                $source[$field] = (int) $source[$field];
            }
            $source['enabled'] = (bool) $source['enabled'];
            $source['import_id'] = $source['import_id'] !== null ? (int) $source['import_id'] : null;
            $source['mappings'] = array_intersect_key($source, array_flip(['reason_column', 'comment_column', 'case_column']));
            unset($source['content_hash']);
        }
        return array_merge(['module' => $this->module, 'configured' => $source !== null, 'enabled' => (bool) ($source['enabled'] ?? false),
            'source' => $source, 'migration_required' => !$this->db->tableExists('sheet_sources') || !$this->db->fieldExists('module', 'imports')], $this->client->credentialsStatus());
    }

    public function configure(array $input, int $adminId): array
    {
        if (!$this->db->tableExists('sheet_sources') || !$this->db->fieldExists('module', 'imports')) {
            throw new DomainException('Run php spark migrate before configuring Google Sheets.');
        }
        if (!$this->db->table('users')->where('id', $adminId)->where('role', 'admin')->where('active', 1)->where('archived', 0)->countAllResults()) {
            throw new DomainException('An active administrator must configure the Google Sheets connection.');
        }
        $config = config('GoogleSheets');
        $data = [];
        foreach (['spreadsheet_url', 'spreadsheet_id', 'sheet_gid', 'refresh_minutes', 'reason_column', 'comment_column', 'case_column', 'enabled'] as $field) {
            if (isset($input[$field]) && !is_scalar($input[$field])) {
                throw new DomainException('Google Sheets settings must contain text values.');
            }
        }
        $id = trim((string) ($input['spreadsheet_id'] ?? ''));
        $url = trim((string) ($input['spreadsheet_url'] ?? ''));
        $urlGid = null;
        if ($url !== '') {
            $parts = parse_url($url);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'docs.google.com'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
                || !preg_match('~^/spreadsheets/d/([A-Za-z0-9_-]{20,160})(?:/|$)~D', $parts['path'] ?? '', $match)) {
                throw new DomainException('Paste an https://docs.google.com/spreadsheets/d/... Google Sheets link.');
            }
            $id = $match[1];
            parse_str($parts['query'] ?? '', $query);
            parse_str($parts['fragment'] ?? '', $fragment);
            $urlGid = $fragment['gid'] ?? $query['gid'] ?? null;
        }
        $id = $id ?: ($this->module === 'shopee-food' ? $config->defaultSpreadsheetId : '');
        if (!preg_match('/^[A-Za-z0-9_-]{20,160}$/D', $id)) {
            throw new DomainException('Enter a valid Google spreadsheet ID or paste its Google Sheets link.');
        }
        $gidValue = $input['sheet_gid'] ?? $urlGid ?? ($this->module === 'shopee-food' ? $config->defaultSheetGid : 0);
        $gid = filter_var($gidValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        $minutes = filter_var($input['refresh_minutes'] ?? $config->defaultRefreshMinutes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        if ($gid === false || $minutes === false) {
            throw new DomainException('Use a numeric worksheet gid and a refresh interval between 1 and 1440 minutes.');
        }
        $enabled = $input['enabled'] ?? 1;
        if (!in_array((string) $enabled, ['0', '1'], true)) {
            throw new DomainException('Choose whether automatic refresh is enabled.');
        }
        $data = ['spreadsheet_id' => $id, 'sheet_gid' => (string) $gid, 'refresh_minutes' => $minutes,
            'enabled' => (int) $enabled, 'configured_by' => $adminId, 'updated_at' => gmdate('Y-m-d H:i:s')];
        foreach (['reason_column', 'comment_column', 'case_column'] as $field) {
            $data[$field] = trim((string) ($input[$field] ?? ''));
            if (mb_strlen($data[$field]) > 255) {
                throw new DomainException('Column mappings must be at most 255 characters.');
            }
        }
        $lock = $this->lock();
        if ($lock === null) {
            throw new DomainException('A Google Sheets refresh is running. Wait for it to finish before changing settings.');
        }
        try {
            $current = $this->source();
            $changed = !$current;
            foreach (['spreadsheet_id', 'sheet_gid', 'reason_column', 'comment_column', 'case_column'] as $field) {
                $changed = $changed || ($current[$field] ?? null) !== $data[$field];
            }
            if ($changed) {
                $data['content_hash'] = null;
                $data['last_attempt_at'] = null;
                $data['last_error'] = null;
            }
            if ($current) {
                if (!$this->db->table('sheet_sources')->where('id', $this->sourceId)->update($data)) {
                    throw new DomainException('The Google Sheets settings could not be saved. Retry shortly.');
                }
            } else {
                if (!$this->db->table('sheet_sources')->insert(array_merge(['id' => $this->sourceId], $data))) {
                    throw new DomainException('The Google Sheets settings could not be saved. Retry shortly.');
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->status();
    }

    public function sync(bool $force = false): array
    {
        $source = $this->source();
        if (!$source) { throw new DomainException('Configure a Google Sheets source first.'); }
        $lock = $this->lock();
        if ($lock === null) { return $this->result('busy', $source); }
        try {
            // Re-read after taking the lock so concurrent settings changes cannot mix sources.
            $source = $this->source();
            if (!(bool) $source['enabled']) { return $this->result('disabled', $source); }
            $lastAttempt = $source['last_attempt_at'] ? strtotime($source['last_attempt_at'] . ' UTC') : false;
            $interval = $source['last_error'] ? 60 : max(60, (int) $source['refresh_minutes'] * 60);
            if (!$force && $lastAttempt !== false && time() - $lastAttempt < $interval) {
                return $this->result('not_due', $source);
            }
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('sheet_sources')->where('id', $this->sourceId)->update(['last_attempt_at' => $now]);
            try {
                $fetched = $this->client->fetch($source['spreadsheet_id'], (int) $source['sheet_gid']);
                $normalizer = new GoogleSheetsRows();
                $normalized = isset($fetched['rows'], $fetched['headers'])
                    ? $normalizer->normalizeRows($fetched['rows'], $fetched['headers'], $source)
                    : $normalizer->normalize($fetched['values'], $source);
                $rows = $normalized['rows'];
                $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $import = $source['import_id'] ? $this->db->table('imports')->where('id', $source['import_id'])->where('module', $this->module)->get()->getRowArray() : null;
                if ($source['import_id'] && !$import && $this->db->table('imports')->where('id', $source['import_id'])->countAllResults()) {
                    throw new DomainException('This connection references a report in another module. Ask the server administrator to repair the connection.');
                }
                $status = ['sheet_title' => mb_substr($fetched['title'], 0, 255), 'last_synced_at' => $now,
                    'last_error' => null, 'row_count' => count($rows), 'duplicate_count' => $normalized['duplicate_count'],
                    'content_hash' => $hash, 'updated_at' => $now];
                if ($import && hash_equals((string) ($source['content_hash'] ?? ''), $hash)) {
                    $this->db->table('imports')->where('id', $import['id'])->update(['archived' => 0]);
                    $this->db->table('sheet_sources')->where('id', $this->sourceId)->update($status);
                    return $this->result('unchanged', array_merge($source, $status));
                }
                $uploader = $this->db->table('users')->where('id', $source['configured_by'])->get()->getRowArray();
                if (!$uploader) {
                    throw new DomainException('The administrator who configured this source no longer exists. Save the Google Sheets settings with an active administrator.');
                }
                $dates = array_column($rows, 'response_date');
                $metadata = ['filename' => mb_substr('Google Sheets · ' . $fetched['title'], 0, 255),
                    'file_hash' => hash('sha256', 'google-sheets-managed-source:' . $this->sourceId), 'module' => $this->module, 'uploaded_by' => (int) $source['configured_by'],
                    'row_count' => count($rows), 'date_from' => min($dates), 'date_to' => max($dates), 'created_at' => $now, 'archived' => 0];
                $this->db->transException(true)->transBegin();
                try {
                    if ($import) {
                        $importId = (int) $import['id'];
                        $this->db->table('responses')->where('import_id', $importId)->delete();
                        $this->db->table('imports')->where('id', $importId)->update($metadata);
                    } else {
                        $this->db->table('imports')->insert($metadata);
                        $importId = (int) $this->db->insertID();
                    }
                    foreach ($normalized['identities'] as $identity) {
                        AgentRoster::remember($this->db, $identity);
                    }
                    foreach (array_chunk($rows, 200) as $chunk) {
                        foreach ($chunk as &$row) { $row['import_id'] = $importId; }
                        unset($row);
                        if ($this->db->table('responses')->insertBatch($chunk) === false) {
                            throw new \RuntimeException('Response insert failed.');
                        }
                    }
                    $status['import_id'] = $importId;
                    $this->db->table('sheet_sources')->where('id', $this->sourceId)->update($status);
                    if (!$this->db->transStatus() || !$this->db->transCommit()) {
                        throw new \RuntimeException('Sync transaction failed.');
                    }
                } catch (Throwable $e) {
                    $this->db->transRollback();
                    $this->db->resetTransStatus();
                    throw $e;
                }
                return $this->result('synced', array_merge($source, $status));
            } catch (Throwable $e) {
                $message = $e instanceof DomainException ? $e->getMessage()
                    : 'The Google Sheets refresh could not be saved. The last successful report was kept. Retry shortly or ask your administrator to check the server.';
                $this->db->table('sheet_sources')->where('id', $this->sourceId)->update(['last_error' => mb_substr($message, 0, 1000)]);
                throw new DomainException($message);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function source(): ?array
    {
        return $this->db->tableExists('sheet_sources')
            ? $this->db->table('sheet_sources')->where('id', $this->sourceId)->get()->getRowArray() : null;
    }

    private function result(string $state, array $source): array
    {
        return ['state' => $state, 'import_id' => $source['import_id'] !== null ? (int) $source['import_id'] : null,
            'row_count' => (int) $source['row_count'], 'duplicate_count' => (int) $source['duplicate_count']];
    }

    private function lock()
    {
        $directory = dirname($this->lockPath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new DomainException('The server cannot create the private Google Sheets sync directory. Check writable folder permissions.');
        }
        $handle = @fopen($this->lockPath, 'c');
        if (!$handle) { throw new DomainException('The server cannot open the Google Sheets refresh lock. Check writable folder permissions.'); }
        @chmod($this->lockPath, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }
}
