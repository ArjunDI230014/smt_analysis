<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;

class CsatReport
{
    private array $matchedNames = [];
    public function __construct(private ?BaseConnection $db = null, private ?string $ownerEmail = null, private ?string $exactName = null)
    {
        $this->db ??= db_connect();
    }

    public function filters(array $input): array
    {
        $filters = [];
        foreach (['from', 'to', 'team', 'channel', 'agent', 'scope', 'period', 'tab'] as $key) {
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
        $filters['period'] = $filters['period'] ?: 'daily';
        if (!in_array($filters['period'], ['daily', 'weekly', 'monthly'], true)) {
            throw new DomainException('Choose a daily, weekly, or monthly view.');
        }
        $filters['tab'] = $filters['tab'] === '' ? 'statistics' : $filters['tab'];
        if (!in_array($filters['tab'], ['statistics', 'dsat', 'agents'], true)) {
            throw new DomainException('Choose Statistics, DSAT feedback, or Agent performance.');
        }
        $dsatPage = $input['dsat_page'] ?? 1;
        if ($dsatPage === '') {
            $dsatPage = 1;
        }
        if ((!is_string($dsatPage) && !is_int($dsatPage))
            || filter_var($dsatPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException('Choose a valid DSAT page.');
        }
        $filters['dsat_page'] = (int) $dsatPage;
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
        if ($this->exactName !== null) {
            $query->where('agent_name', $this->exactName);
        }
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
        $depth = $this->db->transDepth;
        if (!$this->db->transBegin()) {
            throw new \RuntimeException('The report could not start a consistent read.');
        }
        try {
            // Connected reports are replaced in place. Keep totals, charts, and
            // feedback on one database snapshot while a refresh commits elsewhere.
            $report = $this->buildSnapshot($importId, $filters, $page);
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new \RuntimeException('The report could not complete its consistent read.');
            }
            return $report;
        } catch (\Throwable $e) {
            // A caller may already own a transaction, and the framework can also
            // unwind failed transactions itself. Only release our own nesting level.
            if ($this->db->transDepth > $depth) {
                $this->db->transRollback();
            }
            throw $e;
        }
    }

    private function buildSnapshot(int $importId, array $filters, int $page): array
    {
        $filters += ['period' => 'daily', 'dsat_page' => 1];
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
        $rated = $summary['good'] + $summary['bad'];
        $summary['dsat_rate'] = $rated > 0 ? round($summary['bad'] / $rated * 100, 2) : null;
        $groups = [];
        foreach (['trend' => 'response_date', 'teams' => 'team', 'channels' => 'channel'] as $key => $column) {
            $query = $this->query($importId, $filters)->select($column . ' AS label, SUM(good) AS good, SUM(bad) AS bad, COUNT(*) AS total', false)->groupBy($column);
            $query = $key === 'trend' ? $query->orderBy($column, 'ASC') : $query->orderBy('total', 'DESC')->orderBy($column, 'ASC');
            if ($key === 'teams') {
                $query->limit(12);
            }
            $groups[$key] = $query->get()->getResultArray();
            if ($key === 'trend') {
                $groups[$key] = $this->periodGroups($groups[$key], $filters['period']);
            }
            foreach ($groups[$key] as &$group) {
                $group['score'] = $this->score($group);
            }
            unset($group);
        }
        $agentCount = $this->query($importId, $filters)->select('agent_name, team')->groupBy(['agent_name', 'team'])->countAllResults();
        $pages = max(1, (int) ceil($agentCount / 25));
        $page = max(1, min($pages, $page));
        $agents = $this->query($importId, $filters)->select('MIN(id) AS response_id, agent_name, team, COUNT(*) AS total, SUM(good) AS good, SUM(bad) AS bad', false)
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
        $dsat = $this->dsat($importId, $filters);
        return compact('summary', 'groups', 'agents', 'agentCount', 'pages', 'page', 'options', 'agentNames', 'matchedNameCount', 'dsat');
    }

    /** Aggregate daily totals in PHP so SQLite and MySQL use the same calendar boundaries. */
    private function periodGroups(array $daily, string $period): array
    {
        if ($period === 'daily') {
            return $daily;
        }
        $groups = [];
        foreach ($daily as $day) {
            $date = new \DateTimeImmutable($day['label']);
            $label = $period === 'monthly'
                ? $date->format('Y-m')
                : $date->modify('-' . ((int) $date->format('N') - 1) . ' days')->format('Y-m-d');
            $groups[$label] ??= ['label' => $label, 'good' => 0, 'bad' => 0, 'total' => 0];
            foreach (['good', 'bad', 'total'] as $column) {
                $groups[$label][$column] += (int) $day[$column];
            }
        }
        ksort($groups, SORT_STRING);
        return array_values($groups);
    }

    private function dsat(int $importId, array $filters): array
    {
        // Importers cap each snapshot at 100,000 responses. Fetch once to keep ranking
        // and pagination based on the same owned, filtered set of negative ratings.
        $sourceRows = $this->query($importId, $filters)
            ->select('response_date, csat_id, agent_name, agent_email, team, channel, source_row, raw_json')
            ->where('bad', 1)->orderBy('response_date', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();
        $rows = [];
        $reasons = [];
        $missingReason = 0;
        foreach ($sourceRows as $row) {
            $raw = json_decode($row['raw_json'], true);
            $raw = is_array($raw) ? $raw : [];
            $reason = $this->detail($raw, 'dsat_reason', [
                'dsat reason', 'reason', 'root cause', 'dsat root cause',
                'reason for dsat', 'dissatisfaction reason', 'negative rating reason',
                'bad rating reason', 'csat reason',
            ]);
            $responseReasons = [];
            if (array_key_exists('dsat_reasons', $raw)) {
                foreach (is_array($raw['dsat_reasons']) ? $raw['dsat_reasons'] : [] as $value) {
                    if (!is_string($value) && !is_int($value) && !is_float($value)) {
                        continue;
                    }
                    $label = trim((string) $value);
                    if ($label !== '') {
                        $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $label));
                        $responseReasons[$key] ??= $label;
                    }
                }
                // Unknown source codes can have no decoded tags but still carry an
                // explicit canonical value. Never fall back to legacy aliases here.
                $canonicalReason = $this->detail($raw, 'dsat_reason', []);
                if ($responseReasons === [] && $canonicalReason !== '') {
                    $responseReasons[] = $canonicalReason;
                }
                $reason = $responseReasons === [] ? ''
                    : ($canonicalReason !== '' ? $canonicalReason : implode('; ', $responseReasons));
            } elseif ($reason !== '') {
                $responseReasons[] = $reason;
            }
            if ($responseReasons === []) {
                $missingReason++;
                $reason = 'Reason not provided';
                $responseReasons[] = $reason;
            }
            $row['reason'] = $reason;
            $row['comment'] = $this->detail($raw, 'customer_comment', [
                'customer comment', 'customer comments', 'customer feedback',
                'comment', 'comments', 'feedback', 'csat comment', 'csat comments',
                'dsat comment', 'survey comment', 'survey comments', 'feedback comment',
            ]);
            $row['case_id'] = $this->detail($raw, 'case_id', [
                'case id', 'case number', 'ticket id', 'ticket number',
                'conversation id', 'contact id',
            ]);
            $row['source_row'] = (int) $row['source_row'];
            unset($row['raw_json']);
            $rows[] = $row;
            foreach ($responseReasons as $label) {
                $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $label));
                $reasons[$key] ??= ['reason' => $label, 'count' => 0];
                $reasons[$key]['count']++;
            }
        }
        $total = count($rows);
        $reasons = array_values($reasons);
        usort($reasons, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strnatcasecmp($a['reason'], $b['reason']));
        foreach ($reasons as &$reason) {
            $reason['share'] = round($reason['count'] / $total * 100, 2);
        }
        unset($reason);
        $pageSize = 50;
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = max(1, min($pages, (int) $filters['dsat_page']));
        return [
            'reasons' => $reasons,
            'rows' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
            'total' => $total, 'page' => $page, 'pages' => $pages,
            'page_size' => $pageSize, 'missing_reason' => $missingReason,
        ];
    }

    /** Prefer the mapped field; ambiguous legacy aliases must not invent a cause. */
    private function detail(array $raw, string $canonical, array $aliases): string
    {
        $text = static fn (mixed $value): string => is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (array_key_exists($canonical, $raw)) {
            return $text($raw[$canonical]);
        }
        $normalize = static fn (string $value): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
        $aliases = array_map($normalize, $aliases);
        $values = [];
        foreach ($raw as $header => $value) {
            if (in_array($normalize((string) $header), $aliases, true) && ($value = $text($value)) !== '') {
                $values[$value] = $value;
            }
        }
        return count($values) === 1 ? reset($values) : '';
    }

    private function score(array $row): ?float
    {
        $rated = (int) $row['good'] + (int) $row['bad'];
        return $rated > 0 ? round((int) $row['good'] / $rated * 100, 2) : null;
    }
}
