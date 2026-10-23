<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class GoogleSheets extends BaseConfig
{
    public string $defaultSpreadsheetId = '1HFE5cyls6xn_uIhG9XaGE7ZH0FbSax-JNjLxEYFkPH4';
    public int $defaultSheetGid = 2030175176;
    public string $requiredSheetName = 'Raw Data CSAT';
    public int $defaultRefreshMinutes = 15;

    public function credentialsPath(): string
    {
        $path = trim((string) env('GOOGLE_SHEETS_CREDENTIALS', ''));
        return $path !== '' ? $path : WRITEPATH . 'private/google-service-account.json';
    }
}
