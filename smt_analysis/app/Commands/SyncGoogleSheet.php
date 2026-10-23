<?php

namespace App\Commands;

use App\Libraries\GoogleSheetsSync;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;

class SyncGoogleSheet extends BaseCommand
{
    protected $group = 'CSAT';
    protected $name = 'csat:sync';
    protected $description = 'Refresh the configured Google Sheets report when due.';
    protected $options = ['--force' => 'Refresh now without waiting for the configured interval.'];

    public function run(array $params)
    {
        try {
            $sync = new GoogleSheetsSync();
            if (!$sync->status()['configured']) {
                CLI::write('Google Sheets is not configured yet. Set it up in the app when ready.');
                return EXIT_SUCCESS;
            }
            $result = $sync->sync((bool) CLI::getOption('force'));
            CLI::write('Google Sheets: ' . $result['state'] . ' · ' . $result['row_count'] . ' responses · '
                . $result['duplicate_count'] . ' identical duplicates skipped.');
        } catch (DomainException $e) {
            CLI::error($e->getMessage());
            return EXIT_ERROR;
        }
    }
}
