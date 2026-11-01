<?php

namespace Okay\Modules\Sviat\Redis\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Modules\Sviat\Redis\Security\RequestOrigin;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;
use Okay\Modules\Sviat\Redis\Services\RedisConnectionForm;

class RedisSettingsAdmin extends IndexAdmin
{
    /** Інші POST сторінки (вихід з адмінки) приходять сюди порожніми й не мають зачепити налаштування. */
    private const array ACTIONS = ['save', 'test', 'flush_helpers'];

    public function fetch(RedisCacheService $redisCache)
    {
        $testResult = null;
        $error = null;
        $flushError = null;
        $flushDone = false;
        $formErrors = [];
        $passwordDropped = false;

        $locked = $redisCache->lockedConnectionFields();

        if ($this->request->method('post') && in_array($this->request->post('action'), self::ACTIONS, true)) {
            if (!$this->isFromThisSite()) {
                $this->response->setStatusCode(403);
                $this->response->setContent(json_encode(['message' => 'Forbidden']), RESPONSE_JSON);

                return;
            }

            // Форма очищення не несе полів: збереження вимкнуло б кеш і скинуло TTL.
            if ($this->request->post('action') !== 'flush_helpers') {
                [$formErrors, $passwordDropped] = $this->saveSettings($locked);
            }

            if ($this->request->post('action') === 'test') {
                $testResult = $redisCache->testConnection(true);
                if (!$testResult) {
                    $error = $redisCache->getLastError();
                }
            } elseif ($this->request->post('action') === 'flush_helpers') {
                // Мовчазна кнопка гірша за зламану: адмін бачить «натиснув» і
                // йде далі, а кеш лишається старим.
                if ($redisCache->flushAll()) {
                    $flushDone = true;
                } else {
                    $flushError = $redisCache->getLastError() ?? '';
                }
            }

            if ($this->request->post('action') === 'save') {
                $this->design->assign('message_success', 'saved');
            }
        }

        $stats = $redisCache->getStats();

        $view = $redisCache->connectionView();
        $this->design->assign('redis_locked', $locked);
        $this->design->assign('redis_form_errors', $formErrors);
        $this->design->assign('redis_password_dropped', $passwordDropped);
        $this->design->assign('redis_enabled', (int) $view['enabled']);
        $this->design->assign('redis_host', $view['host']);
        $this->design->assign('redis_port', $view['port']);
        $this->design->assign('redis_db', $view['db']);
        $this->design->assign('redis_username', $view['username']);
        $this->design->assign('redis_password_is_set', (string) ($this->settings->get('sviat__redis__password') ?? '') !== '');
        $this->design->assign('redis_prefix', $view['prefix']);
        $this->design->assign('redis_default_ttl', (int)($this->settings->get('sviat__redis__default_ttl') ?: 600));

        $this->design->assign('ttl_products_get_list', (int)($this->settings->get('sviat__redis__ttl__products_get_list') ?: 300));
        $this->design->assign('ttl_catalog_features', (int)($this->settings->get('sviat__redis__ttl__catalog_features') ?: 600));
        $this->design->assign('ttl_catalog_features_filter', (int)($this->settings->get('sviat__redis__ttl__catalog_features_filter') ?: 600));
        $this->design->assign('ttl_categories_catalog_features', (int)($this->settings->get('sviat__redis__ttl__categories_catalog_features') ?: 600));
        $this->design->assign('ttl_product_attach_variants', (int)($this->settings->get('sviat__redis__ttl__product_attach_variants') ?: 300));
        $this->design->assign('ttl_product_attach_images', (int)($this->settings->get('sviat__redis__ttl__product_attach_images') ?: 3600));
        $this->design->assign('ttl_product_attach_features', (int)($this->settings->get('sviat__redis__ttl__product_attach_features') ?: 3600));
        $this->design->assign('ttl_brands_get_list', (int)($this->settings->get('sviat__redis__ttl__brands_get_list') ?: 600));
        $this->design->assign('ttl_filter_get_brands', (int)($this->settings->get('sviat__redis__ttl__filter_get_brands') ?: 600));
        $this->design->assign('ttl_authors_get_list', (int)($this->settings->get('sviat__redis__ttl__authors_get_list') ?: 600));
        $this->design->assign('ttl_blog_get_list', (int)($this->settings->get('sviat__redis__ttl__blog_get_list') ?: 600));
        $this->design->assign('ttl_blog_attach_post_data', (int)($this->settings->get('sviat__redis__ttl__blog_attach_post_data') ?: 3600));

        $this->design->assign('redis_stats', $stats);
        $this->design->assign('redis_state', !$view['enabled'] ? 'off' : (empty($stats['connected']) ? 'error' : 'ok'));
        $this->design->assign('test_result', $testResult);
        $this->design->assign('test_error', $error);
        $this->design->assign('flush_error', $flushError);
        $this->design->assign('flush_done', $flushDone);

        $this->response->setContent($this->design->fetch('redis_settings.tpl'));
    }

    /**
     * @param array<string, mixed> $locked поля, задані поза адмінкою
     * @return array{0: array, 1: bool} помилки форми й чи скинуто пароль
     */
    private function saveSettings(array $locked): array
    {
        if (!isset($locked['enabled'])) {
            $this->settings->set('sviat__redis__enabled', (int) $this->request->post('enabled'));
        }

        $connection = RedisConnectionForm::apply(
            [
                'host'           => $this->request->post('host'),
                'port'           => $this->request->post('port'),
                'db'             => $this->request->post('db'),
                'username'       => $this->request->post('username'),
                'password'       => $this->request->post('password'),
                'clear_password' => $this->request->post('clear_password'),
                'prefix'         => $this->request->post('prefix'),
            ],
            [
                'host'     => (string) ($this->settings->get('sviat__redis__host') ?: '127.0.0.1'),
                'port'     => (string) ($this->settings->get('sviat__redis__port') ?: 6379),
                'username' => (string) ($this->settings->get('sviat__redis__username') ?? ''),
                'password' => (string) ($this->settings->get('sviat__redis__password') ?? ''),
            ],
            $locked
        );
        foreach ($connection['values'] as $field => $value) {
            $this->settings->set('sviat__redis__' . $field, $value);
        }

        $this->settings->set('sviat__redis__default_ttl', (int)$this->request->post('default_ttl'));

        $this->settings->set('sviat__redis__ttl__products_get_list', (int)$this->request->post('ttl_products_get_list'));
        $this->settings->set('sviat__redis__ttl__catalog_features', (int)$this->request->post('ttl_catalog_features'));
        $this->settings->set('sviat__redis__ttl__catalog_features_filter', (int)$this->request->post('ttl_catalog_features_filter'));
        $this->settings->set('sviat__redis__ttl__categories_catalog_features', (int)$this->request->post('ttl_categories_catalog_features'));
        $this->settings->set('sviat__redis__ttl__product_attach_variants', (int)$this->request->post('ttl_product_attach_variants'));
        $this->settings->set('sviat__redis__ttl__product_attach_images', (int)$this->request->post('ttl_product_attach_images'));
        $this->settings->set('sviat__redis__ttl__product_attach_features', (int)$this->request->post('ttl_product_attach_features'));
        $this->settings->set('sviat__redis__ttl__brands_get_list', (int)$this->request->post('ttl_brands_get_list'));
        $this->settings->set('sviat__redis__ttl__filter_get_brands', (int)$this->request->post('ttl_filter_get_brands'));
        $this->settings->set('sviat__redis__ttl__authors_get_list', (int)$this->request->post('ttl_authors_get_list'));
        $this->settings->set('sviat__redis__ttl__blog_get_list', (int)$this->request->post('ttl_blog_get_list'));
        $this->settings->set('sviat__redis__ttl__blog_attach_post_data', (int)$this->request->post('ttl_blog_attach_post_data'));

        return [$connection['errors'], $connection['passwordDropped']];
    }

    /** На стоку CSRF-перевірка ядра стоїть після контролера, тому модуль перевіряє сам. */
    protected function isFromThisSite(): bool
    {
        return RequestOrigin::isFromThisSite();
    }
}
