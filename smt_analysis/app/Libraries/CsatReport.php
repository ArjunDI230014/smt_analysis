<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;

class CsatReport
{
    private array $matchedNames = [];
    public function __construct(private ?BaseConnection $db = null, private ?string $ownerEmail = null)
    {
        $this->db ??= db_connect();
    }

    public function filters(array $input): array
    {
        $filters = [];
        foreach (['from', 'to', 'team', 'channel', 'agent', 'scope'] as $key) {
            if (isset($input[$key]) && !is_scalar($input[$key])) {
                throw new DomainException('Invalid dashboard filter.');
            }
            $filters[$key] = trim((string) ($input[$key] ?? ''));
            if (mb_strlen($filters[$key]) > 255) {
                throw new DomainException('A filter value is too long.');
            }
        }
        $filters['scope'] = $filters['scope'] ?: 'included';
        if (!in_array($filters['scope'], ['all', 'included', 'excluded'], true)) {
            throw new DomainException('Choose a valid inclusion setting.');
        }
        foreach (['from', 'to'] as $key) {
            if ($filters[$key] !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
                if (!$date || $date->format('Y-m-d') !== $filters[$key]) {
                    throw new DomainException('Choose valid dates.');
                }
            }
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            throw new DomainException('The start date must be before or equal to the end date.');
        }
        return $filters;
    }

    private function query(int $importId, array $filters): BaseBuilder
    {
        $query = $this->db->table('responses')->where('import_id', $importId);
        if ($this->ownerEmail !== null) {
            $query->where('agent_email', strtolower(trim($this->ownerEmail)));
        }
        foreach (['from' => 'response_date >=', 'to' => 'response_date <=', 'team' => 'team', 'channel' => 'channel'] as $key => $column) {
            if ($filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if ($filters['scope'] !== 'all') {
            $query->where('is_excluded', $filters['scope'] === 'excluded' ? 1 : 0);
        }
        if ($filters['agent'] !== '') {
            if ($this->matchedNames === []) {
                $query->where('id', -1);
            } else {
                $query->whereIn('agent_name', $this->matchedNames);
            }
        }
        return $query;
    }

    public function build(int $importId, array $filters, int $page = 1): array
    {
        $withoutAgent = array_merge($filters, ['agent' => '']);
        $agentNames = array_column($this->query($importId, $withoutAgent)->select('agent_name')->distinct()->orderBy('agent_name')->get()->getResultArray(), 'agent_name');
        $this->matchedNames = array_values(array_filter($agentNames, static fn ($name) => AgentSearch::matches($name, $filters['agent'])));
        $matchedNameCount = count($this->matchedNames);
        $summary = $this->query($importId, $filters)
            ->select('COUNT(*) AS total, SUM(good) AS good, SUM(bad) AS bad, SUM(is_excluded) AS flagged, AVG(handling_seconds) AS handling, COUNT(handling_seconds) AS handling_count', false)
            ->get()->getRowArray();
        foreach (['total', 'good', 'bad', 'flagged', 'handling_count'] as $key) {
            $summary[$key] = (int) $summary[$key];
        }
        $summary['score'] = $this->score($summary);
        $groups = [];
        foreach (['trend' => 'response_date', 'teams' => 'team', 'channels' => 'channel'] as $key => $column) {
            $query = $this->query($importId, $filters)->select($column . ' AS label, SUM(good) AS good, SUM(bad) AS bad, COUNT(*) AS total', false)->groupBy($column);
            $query = $key === 'trend' ? $query->orderBy($column, 'ASC') : $query->orderBy('total', 'DESC')->orderBy($column, 'ASC');
            if ($key === 'teams') {
                $query->limit(12);
            }
            $groups[$key] = $query->get()->getResultArray();
            foreach ($groups[$key] as &$group) {
                $group['score'] = $this->score($group);
            }
            unset($group);
        }
        $agentCount = $this->query($importId, $filters)->select('agent_name, team')->groupBy(['agent_name', 'team'])->countAllResults();
        $pages = max(1, (int) ceil($agentCount / 25));
        $page = max(1, min($pages, $page));
        $agents = $this->query($importId, $filters)->select('agent_name, team, COUNT(*) AS total, SUM(good) AS good, SUM(bad) AS bad', false)
            ->groupBy(['agent_name', 'team'])->orderBy('total', 'DESC')->orderBy('agent_name', 'ASC')->orderBy('team', 'ASC')
            ->limit(25, ($page - 1) * 25)->get()->getResultArray();
        foreach ($agents as &$agent) {
            $agent['score'] = $this->score($agent);
        }
        unset($agent);
        $options = [];
        foreach (['team', 'channel'] as $column) {
            $query = $this->db->table('responses')->select($column)->distinct()->where('import_id', $importId);
            if ($this->ownerEmail !== null) {
                $query->where('agent_email', strtolower(trim($this->ownerEmail)));
            }
            $options[$column] = array_column($query->orderBy($column)->get()->getResultArray(), $column);
        }
        return compact('summary', 'groups', 'agents', 'agentCount', 'pages', 'page', 'options', 'agentNames', 'matchedNameCount');
    }

    private function score(array $row): ?float
    {
        $rated = (int) $row['good'] + (int) $row['bad'];
        return $rated > 0 ? round((int) $row['good'] / $rated * 100, 2) : null;
    }
}
