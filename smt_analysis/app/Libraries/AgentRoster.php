<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use DomainException;

class AgentRoster
{
    public static function identity(array $raw): ?array
    {
        $email = strtolower(trim((string) ($raw['agent_email'] ?? $raw['email'] ?? '')));
        if ($email === '') {
            return null;
        }
        $name = trim((string) ($raw['agent_name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Agent registration requires a valid agent email and an agent_name of at most 120 characters.');
        }
        return ['email' => $email, 'name' => $name];
    }

    public static function remember(BaseConnection $db, array $identity): void
    {
        $table = $db->table('agent_roster');
        if (!$table->where('email', $identity['email'])->countAllResults()) {
            $db->table('agent_roster')->insert($identity);
        } else {
            $db->table('agent_roster')->where('email', $identity['email'])->update(['name' => $identity['name']]);
        }
    }
}
