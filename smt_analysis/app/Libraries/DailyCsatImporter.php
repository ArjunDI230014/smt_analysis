<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

/** Original class name retained for compatibility with existing upload routes. */
class DailyCsatImporter
{
    public const SHEET = 'Raw Data CSAT';
    public const MAX_ROWS = 100000;
    public const MAX_BYTES = 128 * 1024 * 1024;
    private array $readStats = [];

    public function __construct(private string $module = 'shopee-food')
    {
        $this->module = Modules::validate($module);
    }

    public function read(string $path): array
    {
        $this->readStats = (new RawCsatWorkbookReader())->read($path);
        return $this->readStats['rows'];
    }

    public function duplicatesSkipped(): int
    {
        return (int) ($this->readStats['duplicate_count'] ?? 0);
    }

    public function import(string $path, string $filename, int $userId, ?BaseConnection $db = null): int
    {
        $db ??= db_connect();
        if (!is_file($path)) { throw new DomainException('Choose a readable .xlsx workbook.'); }
        $hash = hash_file('sha256', $path);
        if ($hash === false) { throw new DomainException('The workbook could not be read.'); }
        if ($db->table('imports')->where('module', $this->module)->where('file_hash', $hash)->countAllResults() > 0) {
            throw new DomainException('This exact workbook has already been imported. Open its existing report from Uploads.');
        }
        $rows = $this->read($path);
        $dates = array_column($rows, 'response_date');
        $db->transException(true)->transStart();
        try {
            $db->table('imports')->insert([
                'filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255),
                'file_hash' => $hash, 'module' => $this->module, 'uploaded_by' => $userId, 'row_count' => count($rows),
                'date_from' => min($dates), 'date_to' => max($dates), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $importId = (int) $db->insertID();
            foreach ($this->readStats['identities'] as $identity) {
                AgentRoster::remember($db, $identity);
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                foreach ($chunk as &$row) { $row['import_id'] = $importId; }
                unset($row);
                if ($db->table('responses')->insertBatch($chunk) === false) {
                    throw new \RuntimeException('The response batch could not be saved.');
                }
            }
            $db->transComplete();
            if (!$db->transStatus()) { throw new \RuntimeException('The import could not be saved.'); }
            return $importId;
        } catch (Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }
}
