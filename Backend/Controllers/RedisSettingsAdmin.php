<?php

namespace Okay\Modules\Sviat\Redis\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Modules\Sviat\Redis\Services\RedisCacheService;

class RedisSettingsAdmin extends IndexAdmin
{
    public function fetch(RedisCacheService $redisCache)
    {
        $testResult = null;
        $error = null;

        if ($this->request->method('post')) {
            $this->settings->set('sviat__redis__enabled', (int)$this->request->post('enabled'));
            $this->settings->set('sviat__redis__host', $this->request->post('host'));
            $this->settings->set('sviat__redis__port', (int)$this->request->post('port'));
            $this->settings->set('sviat__redis__db', (int)$this->request->post('db'));
            $this->settings->set('sviat__redis__username', trim((string) $this->request->post('username')));
            // Save password as-is; empty value means no auth.
            $this->settings->set('sviat__redis__password', (string) ($this->request->post('password') ?? ''));
            $this->settings->set('sviat__redis__prefix', $this->request->post('prefix'));
            $this->settings->set('sviat__redis__default_ttl', (int)$this->request->post('default_ttl'));
            $this->settings->set('sviat__redis__cache_hmac_secret', trim((string) ($this->request->post('cache_hmac_secret') ?? '')));

            $this->settings->set('sviat__redis__ttl__products_get_list', (int)$this->request->post('ttl_products_get_list'));
            $this->settings->set('sviat__redis__ttl__catalog_features', (int)$this->request->post('ttl_catalog_features'));
            $this->settings->set('sviat__redis__ttl__catalog_features_filter', (int)$this->request->post('ttl_catalog_features_filter'));
            $this->settings->set('sviat__redis__ttl__categories_catalog_features', (int)$this->request->post('ttl_categories_catalog_features'));
            $this->settings->set('sviat__redis__ttl__product_attach_variants', (int)$this->request->post('ttl_product_attach_variants'));
            $this->settings->set('sviat__redis__ttl__product_attach_images', (int)$this->request->post('ttl_product_attach_images'));
            $this->settings->set('sviat__redis__ttl__product_attach_features', (int)$this->request->post('ttl_product_attach_features'));
            $this->settings->set('sviat__redis__ttl__money_currencies_list', (int)$this->request->post('ttl_money_currencies_list'));
            $this->settings->set('sviat__redis__ttl__brands_get_list', (int)$this->request->post('ttl_brands_get_list'));
            $this->settings->set('sviat__redis__ttl__filter_get_brands', (int)$this->request->post('ttl_filter_get_brands'));
            $this->settings->set('sviat__redis__ttl__authors_get_list', (int)$this->request->post('ttl_authors_get_list'));
            $this->settings->set('sviat__redis__ttl__blog_get_list', (int)$this->request->post('ttl_blog_get_list'));
            $this->settings->set('sviat__redis__ttl__blog_attach_post_data', (int)$this->request->post('ttl_blog_attach_post_data'));

            if ($this->request->post('action') === 'test') {
                $testResult = $redisCache->testConnection(true);
                if (!$testResult) {
                    $error = $redisCache->getLastError();
                }
            } elseif ($this->request->post('action') === 'flush_helpers') {
                $redisCache->flushAll();
            }

            if ($this->request->post('action') === 'save') {
                $this->design->assign('message_success', 'saved');
            }
        }

        $stats = $redisCache->getStats();

        $this->design->assign('redis_enabled', (int)$this->settings->get('sviat__redis__enabled'));
        $this->design->assign('redis_host', $this->settings->get('sviat__redis__host') ?: '127.0.0.1');
        $this->design->assign('redis_port', (int)($this->settings->get('sviat__redis__port') ?: 6379));
        $this->design->assign('redis_db', (int)($this->settings->get('sviat__redis__db') ?: 0));
        $this->design->assign('redis_username', (string) ($this->settings->get('sviat__redis__username') ?? ''));
        $this->design->assign('redis_password', $this->settings->get('sviat__redis__password'));
        $this->design->assign('redis_prefix', $this->settings->get('sviat__redis__prefix') ?: 'okay:');
        $this->design->assign('redis_default_ttl', (int)($this->settings->get('sviat__redis__default_ttl') ?: 600));
        $this->design->assign(
            'redis_cache_hmac_secret',
            (string) ($this->settings->get('sviat__redis__cache_hmac_secret') ?? '')
        );

        $this->design->assign('ttl_products_get_list', (int)($this->settings->get('sviat__redis__ttl__products_get_list') ?: 300));
        $this->design->assign('ttl_catalog_features', (int)($this->settings->get('sviat__redis__ttl__catalog_features') ?: 600));
        $this->design->assign('ttl_catalog_features_filter', (int)($this->settings->get('sviat__redis__ttl__catalog_features_filter') ?: 600));
        $this->design->assign('ttl_categories_catalog_features', (int)($this->settings->get('sviat__redis__ttl__categories_catalog_features') ?: 600));
        $this->design->assign('ttl_product_attach_variants', (int)($this->settings->get('sviat__redis__ttl__product_attach_variants') ?: 300));
        $this->design->assign('ttl_product_attach_images', (int)($this->settings->get('sviat__redis__ttl__product_attach_images') ?: 3600));
        $this->design->assign('ttl_product_attach_features', (int)($this->settings->get('sviat__redis__ttl__product_attach_features') ?: 3600));
        $this->design->assign('ttl_money_currencies_list', (int)($this->settings->get('sviat__redis__ttl__money_currencies_list') ?: 3600));
        $this->design->assign('ttl_brands_get_list', (int)($this->settings->get('sviat__redis__ttl__brands_get_list') ?: 600));
        $this->design->assign('ttl_filter_get_brands', (int)($this->settings->get('sviat__redis__ttl__filter_get_brands') ?: 600));
        $this->design->assign('ttl_authors_get_list', (int)($this->settings->get('sviat__redis__ttl__authors_get_list') ?: 600));
        $this->design->assign('ttl_blog_get_list', (int)($this->settings->get('sviat__redis__ttl__blog_get_list') ?: 600));
        $this->design->assign('ttl_blog_attach_post_data', (int)($this->settings->get('sviat__redis__ttl__blog_attach_post_data') ?: 3600));

        $this->design->assign('redis_stats', $stats);
        $this->design->assign('test_result', $testResult);
        $this->design->assign('test_error', $error);

        $this->response->setContent($this->design->fetch('redis_settings.tpl'));
    }
}

