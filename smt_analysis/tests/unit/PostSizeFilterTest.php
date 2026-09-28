<?php

namespace Tests\Unit;

use App\Filters\PostSizeFilter;
use App\Libraries\UploadLimits;
use CodeIgniter\Test\CIUnitTestCase;

final class PostSizeFilterTest extends CIUnitTestCase
{
    public function testPhpSizeSettings(): void
    {
        self::assertSame(8388608, UploadLimits::bytes('8M'));
        self::assertSame(2097152, UploadLimits::bytes('2048K'));
        self::assertSame(0, UploadLimits::bytes('0'));
    }

    public function testOversizedPostReturnsUsefulErrorBeforeCsrf(): void
    {
        $limit = UploadLimits::bytes((string) ini_get('post_max_size'));
        if ($limit === 0) {
            self::markTestSkipped('This PHP process has no POST limit.');
        }
        $request = service('request');
        $request->setMethod('POST');
        $request->setGlobal('server', ['CONTENT_LENGTH' => $limit + 1]);
        $response = (new PostSizeFilter())->before($request);
        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('start.ps1', $response->getBody());
        self::assertStringNotContainsString('action you requested', $response->getBody());
    }

    public function testNormalPostStillGoesThroughCsrf(): void
    {
        $request = service('request');
        $request->setMethod('POST');
        $request->setGlobal('server', ['CONTENT_LENGTH' => 100]);
        self::assertNull((new PostSizeFilter())->before($request));
    }
}
