<?php

namespace App\Controllers;

use App\Libraries\CsatReport;
use DomainException;

class Dashboard extends BaseController
{
    public function index()
    {
        $imports = db_connect()->table('imports')->where('archived', 0)->orderBy('id', 'DESC')->get()->getResultArray();
        $input = $this->request->getGet();
        $report = new CsatReport();
        try {
            $filters = $report->filters($input);
        } catch (DomainException $e) {
            return redirect()->to(site_url('dashboard'))->with('error', $e->getMessage());
        }
        $selected = $input['import'] ?? ($imports[0]['id'] ?? null);
        $import = null;
        foreach ($imports as $candidate) {
            if (is_scalar($selected) && (string) $candidate['id'] === (string) $selected) {
                $import = $candidate;
                break;
            }
        }
        if ($selected !== null && !$import) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Report not found.');
        }
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $data = $import ? $report->build((int) $import['id'], $filters, $page) : [];
        return view('dashboard/index', array_merge($data, compact('imports', 'import', 'filters'), ['title' => 'CSAT overview']));
    }
}
