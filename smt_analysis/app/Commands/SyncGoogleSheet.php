<?php

namespace App\Commands;

use App\Libraries\GoogleSheetsSync;
use App\Libraries\Modules;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;

class SyncGoogleSheet extends BaseCommand
{
    protected $group = 'CSAT';
    protected $name = 'csat:sync';
    protected $description = 'Refresh each module\'s configured Google Sheets report when due.';
    protected $options = ['--force' => 'Refresh now without waiting for the configured interval.'];

    public function run(array $params)
    {
        $failed = false;
        foreach (Modules::all() as $module => $label) {
            try {
                $sync = new GoogleSheetsSync(null, null, null, $module);
                if (!$sync->status()['configured']) {
                    CLI::write($label . ': Google Sheets is not configured yet. Set it up in the app when ready.');
                    continue;
                }
                $result = $sync->sync((bool) CLI::getOption('force'));
                CLI::write($label . ' Google Sheets: ' . $result['state'] . ' · ' . $result['row_count'] . ' responses · '
                    . $result['duplicate_count'] . ' identical duplicates skipped.');
            } catch (\Throwable $e) {
                $failed = true;
                CLI::error($label . ': ' . ($e instanceof DomainException ? $e->getMessage()
                    : 'The Google Sheets refresh could not finish. The last successful report was kept.'));
            }
        }
        return $failed ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
