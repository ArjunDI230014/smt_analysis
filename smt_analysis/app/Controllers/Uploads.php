<?php

namespace App\Controllers;

use App\Libraries\DailyCsatImporter;
use App\Libraries\UploadLimits;
use App\Libraries\Modules;
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
                ->join('users', 'users.id = imports.uploaded_by')->where('imports.module', Modules::current())->orderBy('imports.id', 'DESC')->get()->getResultArray(),
        ]);
    }

    public function archive(int $id)
    {
        return $this->setArchived($id, 1);
    }

    public function restore(int $id)
    {
        return $this->setArchived($id, 0);
    }

    private function setArchived(int $id, int $archived)
    {
        $db = db_connect();
        if (!$db->table('imports')->where('module', Modules::current())->where('id', $id)->get()->getRowArray()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Report not found.');
        }
        if (!$db->table('imports')->where('module', Modules::current())->where('id', $id)->update(['archived' => $archived])) {
            return redirect()->to(Modules::url('admin/uploads'))->with('error', 'The report could not be updated. Please try again.');
        }
        return redirect()->to(Modules::url('admin/uploads'))->with('success', $archived ? 'Report archived. You can restore it from import history.' : 'Report restored to the dashboard.');
    }

    public function delete(int $id)
    {
        $db = db_connect();
        if (!$db->table('imports')->where('module', Modules::current())->where('id', $id)->get()->getRowArray()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Report not found.');
        }
        try {
            $db->transException(true)->transStart();
            $db->table('responses')->where('import_id', $id)->delete();
            $db->table('imports')->where('module', Modules::current())->where('id', $id)->delete();
            $db->transComplete();
            if (!$db->transStatus()) {
                throw new \RuntimeException('Report deletion transaction failed.');
            }
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Report deletion failed: {error}', ['error' => $e->getMessage()]);
            return redirect()->to(Modules::url('admin/uploads'))->with('error', 'The report could not be deleted. Please try again.');
        }
        return redirect()->to(Modules::url('admin/uploads'))->with('success', 'Report and its imported responses permanently deleted.');
    }

    public function store()
    {
        $maxKilobytes = (int) ceil(DailyCsatImporter::MAX_BYTES / 1024);
        if (!$this->validateData([], ['workbook' => 'uploaded[workbook]|max_size[workbook,' . $maxKilobytes . ']|ext_in[workbook,xlsx]'])) {
            return redirect()->back()->with('error', 'Choose a valid .xlsx workbook up to ' . UploadLimits::label(DailyCsatImporter::MAX_BYTES) . '. Check the server upload limit if your file is rejected.');
        }
        $file = $this->request->getFile('workbook');
        try {
            $importer = new DailyCsatImporter(Modules::current());
            $id = $importer->import($file->getTempName(), $file->getClientName(), (int) session('user_id'));
            $duplicates = $importer->duplicatesSkipped();
            return redirect()->to(Modules::url('dashboard') . '?import=' . $id)->with('success', 'Raw Data CSAT imported successfully. ' . number_format($duplicates) . ' exact duplicate' . ($duplicates === 1 ? '' : 's') . ' skipped. All other worksheets were ignored.');
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'CSAT import failed: {error}', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'The workbook could not be imported. No partial report was saved. Check the file is readable and saved by Excel, then try again.');
        }
    }
}
