<?php

namespace Modules\Sviat\Redis;

use Okay\Core\Config;
use Okay\Core\Response;
use Okay\Modules\Sviat\Redis\Extenders\CacheStatsReporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/StubRedisCacheService.php';

class CacheStatsReporterTest extends TestCase
{
    private function report(bool $debug, array $stats): array
    {
        $redis = new class ($stats) extends StubRedisCacheService {
            public function __construct(private array $requestStats) {}
            public function getRequestStats(): array { return $this->requestStats; }
        };

        $headers = [];
        $response = $this->createStub(Response::class);
        $response->method('addHeader')->willReturnCallback(function ($header) use (&$headers, $response) {
            $headers[] = $header;
            return $response;
        });
        $config = $this->createStub(Config::class);
        $config->method('__get')->willReturn(null);
        $config->method('get')->willReturn($debug);

        new CacheStatsReporter($redis, $response, $config, $this->createStub(LoggerInterface::class))->reportStats();

        return $headers;
    }

    private function stats(?string $error = null): array
    {
        return ['hits' => 3, 'misses' => 1, 'bumps' => 0, 'round_trips' => 2, 'ms' => '1.5', 'error' => $error, 'suppressed' => false];
    }

    public function testNoHeaderOutsideDebug(): void
    {
        self::assertSame([], $this->report(false, $this->stats()));
    }

    public function testHeaderInDebugCarriesCounters(): void
    {
        self::assertSame(['X-Redis-Prerender: h=3 m=1 bumps=0 rt=2 ms=1.5'], $this->report(true, $this->stats()));
    }

    /** Текст помилки йде від сервера Redis, а перенос рядка зробив би з нього новий заголовок. */
    public function testErrorTextCannotInjectHeader(): void
    {
        $headers = $this->report(true, $this->stats("boom\r\nSet-Cookie: x=1"));

        self::assertStringNotContainsString("\r", $headers[0]);
        self::assertStringNotContainsString("\n", $headers[0]);
        self::assertStringEndsWith('err=boom  Set-Cookie: x=1', $headers[0]);
    }
}
