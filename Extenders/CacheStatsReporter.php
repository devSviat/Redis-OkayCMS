<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\Config;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Response;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use Psr\Log\LoggerInterface;

/**
 * Робить кеш видимим: відмову Redis пише в лог, а hit/miss віддає заголовком
 * при debug_mode. Без цього сайт мовчки працює без кешу.
 */
class CacheStatsReporter implements ExtensionInterface
{
    private RedisCacheService $redis;
    private Response $response;
    private Config $config;
    private LoggerInterface $logger;

    public function __construct(
        RedisCacheService $redis,
        Response $response,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->redis = $redis;
        $this->response = $response;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function reportStats(): void
    {
        // Поза гейтом debug_mode: на проді він вимкнений, а мовчазна деградація
        // до роботи без кешу найдорожча саме там. Пишемо лише збої — рядок на
        // кожен запит ховає в логах справжні помилки.
        register_shutdown_function(function () {
            $stats = $this->redis->getRequestStats();
            if (!empty($stats['error'])) {
                $this->logger->warning('redis cache: ' . $this->format($stats));
            }
        });

        if (!$this->config->get('debug_mode')) {
            return;
        }

        // Знімок ДО рендеру: Html::send() робить $design->fetch() вже після
        // sendHeaders(), тож плагіни лістингів читають кеш пізніше, а додати
        // заголовок на той момент уже неможливо.
        $this->response->addHeader('X-Redis-Prerender: ' . $this->format($this->redis->getRequestStats()));
    }

    private function format(array $stats): string
    {
        $line = sprintf(
            'h=%d m=%d bumps=%d rt=%d ms=%s',
            $stats['hits'],
            $stats['misses'],
            $stats['bumps'],
            $stats['round_trips'],
            $stats['ms']
        );
        if (!empty($stats['error'])) {
            // Рядок іде і в HTTP-заголовок — переносів там бути не має.
            $line .= ' err=' . str_replace(["\r", "\n"], ' ', (string) $stats['error']);
        }
        return $line;
    }
}
