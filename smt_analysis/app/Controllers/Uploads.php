<?php

namespace App\Controllers;

use App\Libraries\DailyCsatImporter;
use App\Libraries\UploadLimits;
use DomainException;
use Throwable;

class Uploads extends BaseController
{
    public function index()
    {
        return view('uploads/index', [
            'title' => 'Upload reports',
            'uploadLimit' => UploadLimits::current(),
            'imports' => db_connect()->table('imports')->select('imports.*, users.name AS uploader')
                ->join('users', 'users.id = imports.uploaded_by')->orderBy('imports.id', 'DESC')->get()->getResultArray(),
        ]);
    }

    public function store()
    {
        if (!$this->validateData([], ['workbook' => 'uploaded[workbook]|max_size[workbook,20480]|ext_in[workbook,xlsx]'])) {
            return redirect()->back()->with('error', 'Choose a valid .xlsx workbook up to 20 MB. Check the server upload limit if your file is rejected.');
        }
        $file = $this->request->getFile('workbook');
        try {
            $id = (new DailyCsatImporter())->import($file->getTempName(), $file->getClientName(), (int) session('user_id'));
            return redirect()->to(site_url('dashboard') . '?import=' . $id)->with('success', 'Daily CSAT imported successfully. All other worksheets were ignored.');
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'CSAT import failed: {error}', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'The workbook could not be imported. No partial report was saved. Check the file is readable and saved by Excel, then try again.');
        }
    }
}
