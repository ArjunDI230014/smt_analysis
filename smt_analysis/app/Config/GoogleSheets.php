<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class GoogleSheets extends BaseConfig
{
    public string $defaultSpreadsheetId = '1HFE5cyls6xn_uIhG9XaGE7ZH0FbSax-JNjLxEYFkPH4';
    public int $defaultSheetGid = 2030175176;
    public string $requiredSheetName = 'Raw Data CSAT';
    public int $defaultRefreshMinutes = 15;

    public function credentialsEnvironment(string $module = 'shopee-food'): string
    {
        return \App\Libraries\Modules::validate($module) === 'marketplace'
            ? 'MARKETPLACE_GOOGLE_SHEETS_CREDENTIALS' : 'GOOGLE_SHEETS_CREDENTIALS';
    }

    public function credentialsPath(string $module = 'shopee-food'): string
    {
        $path = trim((string) env($this->credentialsEnvironment($module), ''));
        $filename = $module === 'marketplace' ? 'marketplace-google-service-account.json' : 'google-service-account.json';
        return $path !== '' ? $path : WRITEPATH . 'private/' . $filename;
    }
}
