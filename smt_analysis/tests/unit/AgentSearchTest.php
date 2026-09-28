<?php

namespace Tests\Unit;

use App\Libraries\AgentSearch;
use CodeIgniter\Test\CIUnitTestCase;

final class AgentSearchTest extends CIUnitTestCase
{
    public function testPartialCaseInsensitiveReorderedAndTypoSearch(): void
    {
        foreach (['SYAF', 'syafiq ahmad', 'syfiq', '  ahmad---SYAFIQ  '] as $term) {
            self::assertTrue(AgentSearch::matches('SN Ahmad Syafiq', $term), $term);
        }
        self::assertTrue(AgentSearch::matches('José Ali', 'jose'));
        self::assertFalse(AgentSearch::matches('SN Ahmad Syafiq', 'ahmad farah'));
        self::assertFalse(AgentSearch::matches('SN Ahmad Syafiq', 'zz'));
        self::assertFalse(AgentSearch::matches('SN Ahmad Syafiq', '%_'));
    }
}
