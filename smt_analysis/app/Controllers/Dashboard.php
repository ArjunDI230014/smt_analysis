<?php

namespace App\Controllers;

use App\Libraries\CsatReport;
use DomainException;

class Dashboard extends BaseController
{
    public function agent(int $id)
    {
        $db = db_connect();
        $identity = $db->table('responses')->select('responses.*')->join('imports', 'imports.id = responses.import_id')->where('responses.id', $id)->where('imports.archived', 0)->get()->getRowArray();
        if (!$identity) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Agent not found.');
        }
        $report = new CsatReport($db, $identity['agent_email'] ?: null, $identity['agent_name']);
        try {
            $filters = $report->filters($this->request->getGet());
        } catch (DomainException $e) {
            return redirect()->to(site_url('admin/agents/' . $id))->with('error', $e->getMessage());
        }
        $filters['agent'] = '';
        $data = $report->build((int) $identity['import_id'], $filters);
        $account = $identity['agent_email'] ? $db->table('users')->select('name, email, role, active, archived')->where('email', $identity['agent_email'])->get()->getRowArray() : null;
        return view('dashboard/agent', array_merge($data, compact('identity', 'account', 'filters'), ['title' => 'Agent details']));
    }

    public function index()
    {
        $imports = db_connect()->table('imports')->where('archived', 0)->orderBy('id', 'DESC')->get()->getResultArray();
        $input = $this->request->getGet();
        $personal = session('user')['role'] !== 'admin';
        $report = new CsatReport(null, $personal ? session('user')['email'] : null);
        if ($personal) {
            $input['agent'] = '';
        }
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
        if ($personal && $import) {
            $owned = db_connect()->table('responses')->where('import_id', $import['id'])->where('agent_email', strtolower(trim(session('user')['email'])))
                ->select('COUNT(*) AS row_count, MIN(response_date) AS date_from, MAX(response_date) AS date_to', false)->get()->getRowArray();
            $import = array_merge($import, $owned);
        }
        return view('dashboard/index', array_merge($data, compact('imports', 'import', 'filters', 'personal'), ['title' => 'CSAT overview']));
    }
}
