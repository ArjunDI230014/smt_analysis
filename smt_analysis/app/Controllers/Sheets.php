<?php

namespace App\Controllers;

use App\Libraries\GoogleSheetsClient;
use App\Libraries\GoogleSheetsSync;
use DomainException;
use Throwable;

class Sheets extends BaseController
{
    public function index()
    {
        return view('sheets/index', ['title' => 'Google Sheets', 'googleSheets' => (new GoogleSheetsSync())->status()]);
    }

    public function save()
    {
        $temporaryKey = null;
        try {
            $file = $this->request->getFile('credentials');
            if ($file && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                if (trim((string) env('GOOGLE_SHEETS_CREDENTIALS', '')) !== '') {
                    throw new DomainException('The service-account key is managed by this server. Ask its administrator to replace it.');
                }
                if (!$file->isValid() || $file->getSize() > 65536 || strtolower($file->getClientExtension()) !== 'json') {
                    throw new DomainException('Choose a valid Google service-account JSON key smaller than 64 KB.');
                }
                $json = file_get_contents($file->getTempName());
                if ($json === false) {
                    throw new DomainException('The service-account key could not be read. Select it again.');
                }
                GoogleSheetsClient::validateCredentialsJson($json);
                $directory = WRITEPATH . 'private';
                if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new DomainException('The server cannot create its private credentials folder. Check writable folder permissions.');
                }
                $temporaryKey = tempnam($directory, 'sheet-key-');
                if ($temporaryKey === false || file_put_contents($temporaryKey, $json, LOCK_EX) !== strlen($json)) {
                    throw new DomainException('The service-account key could not be saved. Check writable folder permissions.');
                }
                @chmod($temporaryKey, 0600);
                unset($json);
            }
            $sync = new GoogleSheetsSync();
            $sync->configure($this->request->getPost(), (int) session('user_id'));
            if ($temporaryKey !== null) {
                if (!@rename($temporaryKey, config('GoogleSheets')->credentialsPath())) {
                    throw new DomainException('Settings were saved, but the server could not replace the service-account key. Check writable folder permissions.');
                }
                $temporaryKey = null;
            }
            return redirect()->to(site_url('admin/sheets'))->with('success', 'Connection settings saved. Share the file with the service-account email shown below, then choose Refresh now.');
        } catch (DomainException $e) {
            return redirect()->to(site_url('admin/sheets'))->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return redirect()->to(site_url('admin/sheets'))->with('error', 'The Google Sheets settings could not be saved. Please try again.');
        } finally {
            if (is_string($temporaryKey) && is_file($temporaryKey)) {
                @unlink($temporaryKey);
            }
        }
    }

    public function refresh()
    {
        try {
            $result = (new GoogleSheetsSync())->sync(true);
            if ($result['state'] === 'disabled') {
                return redirect()->to(site_url('admin/sheets'))->with('error', 'Enable the connection and save settings before refreshing.');
            }
            if ($result['state'] === 'busy') {
                return redirect()->to(site_url('admin/sheets'))->with('success', 'A refresh is already running. Check the dashboard shortly.');
            }
            $message = $result['state'] === 'unchanged' ? 'Google Sheets checked. Your report is already up to date.'
                : 'Google Sheets refreshed: ' . number_format($result['row_count']) . ' responses. '
                    . number_format($result['duplicate_count']) . ' identical duplicate entries skipped.';
            return redirect()->to(site_url('dashboard') . '?import=' . $result['import_id'])->with('success', $message);
        } catch (DomainException $e) {
            return redirect()->to(site_url('admin/sheets'))->with('error', $e->getMessage());
        } catch (Throwable $e) {
            return redirect()->to(site_url('admin/sheets'))->with('error', 'Google Sheets could not be refreshed. The last successful report is still available.');
        }
    }

    /** Authenticated agents may trigger a due check, but cannot force a refresh or change settings. */
    public function due()
    {
        try {
            $sync = new GoogleSheetsSync();
            $status = $sync->status();
            if (!$status['configured']) {
                $result = ['state' => 'not_configured', 'import_id' => null];
            } else {
                $result = $sync->sync(false);
            }
            $status = $sync->status();
            return $this->json(['state' => $result['state'], 'import_id' => $result['import_id'],
                'updated_at' => $status['source']['last_synced_at'] ?? null]);
        } catch (Throwable $e) {
            $admin = session('user')['role'] === 'admin';
            $message = $admin && $e instanceof DomainException ? $e->getMessage()
                : 'The automatic refresh could not finish. The last successful report is still available.';
            return $this->json(['state' => 'error', 'import_id' => null, 'updated_at' => null, 'message' => $message], 503);
        }
    }

    private function json(array $data, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON(array_merge($data, [
            'csrf_name' => csrf_token(), 'csrf_hash' => csrf_hash(),
        ]));
    }
}
