{$meta_title = $btr->sviat_redis_settings scope=global}

<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">{$btr->sviat_redis_settings|escape}</div>
        </div>
    </div>
</div>

{* Алерти: збережено / результат тесту підключення *}
{if $message_success || $test_result === true || $test_result === false}
    <div class="row">
        <div class="col-lg-12">
            {if $message_success}
                <div class="alert alert--success">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->general_settings_saved|default:"Налаштування збережено"|escape}</div>
                    </div>
                </div>
            {/if}
            {if $test_result === true}
                <div class="alert alert--success">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->sviat_redis_connection_success|escape}</div>
                    </div>
                </div>
            {/if}
            {if $test_result === false}
                <div class="alert alert--warning">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->sviat_redis_connection_failed|escape}</div>
                        {if $test_error}<div class="alert__text">{$test_error|escape}</div>{/if}
                    </div>
                </div>
            {/if}
        </div>
    </div>
{/if}

<form method="post">
    <input type="hidden" name="session_id" value="{$smarty.session.id}">
    <div class="row">
        <div class="col-lg-3 col-md-4">
            <div class="boxed">
                <div class="heading_box">{$btr->sviat_redis_connection_box|escape}</div>

                <div class="form-group">
                    <div class="activity_of_switch activity_of_switch--left">
                        <div class="activity_of_switch_item">
                            <div class="okay_switch clearfix">
                                <label class="switch_label">{$btr->sviat_redis_enable_cache|escape}</label>
                                <label class="switch switch-default">
                                    <input class="switch-input" name="enabled" value="1" type="checkbox" id="sviat_redis_enabled" {if $redis_enabled}checked{/if}>
                                    <span class="switch-label"></span>
                                    <span class="switch-handle"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="heading_label">{$btr->sviat_redis_host|escape}</label>
                    <input type="text" class="form-control" name="host" value="{$redis_host|escape}">
                </div>

                <div class="form-group">
                    <label class="heading_label">{$btr->sviat_redis_port|escape}</label>
                    <input type="number" class="form-control" name="port" value="{$redis_port|escape}">
                </div>

                <div class="form-group">
                    <label class="heading_label">{$btr->sviat_redis_db|escape}</label>
                    <input type="number" class="form-control" name="db" value="{$redis_db|escape}">
                </div>

                <div class="form-group">
                    <label class="heading_label">{$btr->sviat_redis_username|escape}</label>
                    <input type="text" class="form-control" name="username" value="{$redis_username|escape}" placeholder="{$btr->sviat_redis_username_placeholder|escape}" autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="heading_label">{$btr->sviat_redis_password|escape}</label>
                    <input type="password" class="form-control" name="password" value="{$redis_password|escape}" placeholder="{$btr->sviat_redis_password_placeholder|escape}" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label class="heading_label">{$btr->sviat_redis_key_prefix|escape}</label>
                    <input type="text" class="form-control" name="prefix" value="{$redis_prefix|escape}">
                </div>

                <div class="form-group">
                    <label class="heading_label">{$btr->sviat_redis_default_ttl|escape}</label>
                    <input type="number" class="form-control" name="default_ttl" value="{$redis_default_ttl|escape}">
                </div>

                <div class="form-group">
                    <div class="heading_label">
                        <span>{$btr->sviat_redis_cache_hmac_secret|escape}</span>
                        <i class="fn_tooltips" title="{$btr->sviat_redis_cache_hmac_secret_hint|escape}">
                            {include file='svg_icon.tpl' svgId='icon_tooltips'}
                        </i>
                    </div>
                    <input type="password" class="form-control" name="cache_hmac_secret" value="{$redis_cache_hmac_secret|escape}" placeholder="{$btr->sviat_redis_cache_hmac_secret_placeholder|escape}" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <button type="submit" name="action" value="save" class="btn btn_blue">
                        {$btr->general_apply|default:"Зберегти"|escape}
                    </button>
                    <button type="submit" name="action" value="test" class="btn btn_secondary">
                        {$btr->sviat_redis_test_connection|escape}
                    </button>
                </div>
            </div>

            <div class="boxed">
                <div class="heading_box">{$btr->sviat_redis_cache_status|escape}</div>
                {if $redis_stats.enabled && $redis_stats.connected}
                    <p>{$btr->sviat_redis_db_keys_count|escape}: <strong>{$redis_stats.db_size}</strong></p>
                    {if $redis_stats.used_memory}
                        <p>{$btr->sviat_redis_used_memory|escape}: <strong>{$redis_stats.used_memory|escape}</strong></p>
                    {/if}
                    <button type="submit" name="action" value="flush_helpers" class="btn btn_warning">
                        {$btr->sviat_redis_flush_current_db|escape}
                    </button>
                {else}
                    <p>{$btr->sviat_redis_unavailable|escape}{if $redis_stats.error}: {$redis_stats.error|escape}{/if}</p>
                {/if}
            </div>
        </div>

        <div class="col-lg-9 col-md-8">
            <div class="boxed">
                <div class="heading_box">{$btr->sviat_redis_helpers_ttl|escape}</div>

                {* ProductsHelper — 4 поля в один ряд на великому екрані *}
                <div class="row">
                    <div class="col-lg-12">
                        <div class="heading_label">ProductsHelper:</div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="form-group">
                            <label class="heading_label"><small>getList</small></label>
                            <input type="number" class="form-control" name="ttl_products_get_list" value="{$ttl_products_get_list|escape}">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="form-group">
                            <label class="heading_label"><small>attachProductData (variants)</small></label>
                            <input type="number" class="form-control" name="ttl_product_attach_variants" value="{$ttl_product_attach_variants|escape}">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="form-group">
                            <label class="heading_label"><small>attachProductData (images)</small></label>
                            <input type="number" class="form-control" name="ttl_product_attach_images" value="{$ttl_product_attach_images|escape}">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="form-group">
                            <label class="heading_label"><small>attachProductData (features)</small></label>
                            <input type="number" class="form-control" name="ttl_product_attach_features" value="{$ttl_product_attach_features|escape}">
                        </div>
                    </div>
                </div>

                {* CatalogHelper — 2 поля в один ряд *}
                <div class="row">
                    <div class="col-lg-12">
                        <div class="heading_label">CatalogHelper:</div>
                    </div>
                    <div class="col-lg-6 col-md-6">
                        <div class="form-group">
                            <label class="heading_label"><small>getCatalogFeatures</small></label>
                            <input type="number" class="form-control" name="ttl_catalog_features" value="{$ttl_catalog_features|escape}">
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6">
                        <div class="form-group">
                            <label class="heading_label"><small>getCatalogFeaturesFilter</small></label>
                            <input type="number" class="form-control" name="ttl_catalog_features_filter" value="{$ttl_catalog_features_filter|escape}">
                        </div>
                    </div>
                </div>

                {* Хелпери з одним TTL — один ряд *}
                <div class="row">
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="form-group">
                            <label class="heading_label"><small>CategoriesHelper<span class="text_grey">:getCatalogFeatures</span></small></label>
                            <input type="number" class="form-control" name="ttl_categories_catalog_features" value="{$ttl_categories_catalog_features|escape}">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="form-group">
                            <label class="heading_label"><small>MoneyHelper<span class="text_grey">:currencies</span></small></label>
                            <input type="number" class="form-control" name="ttl_money_currencies_list" value="{$ttl_money_currencies_list|escape}">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="form-group">
                            <label class="heading_label"><small>BrandsHelper<span class="text_grey">:getList</span></small></label>
                            <input type="number" class="form-control" name="ttl_brands_get_list" value="{$ttl_brands_get_list|escape}">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="form-group">
                            <label class="heading_label"><small>FilterHelper<span class="text_grey">:getBrands</span></small></label>
                            <input type="number" class="form-control" name="ttl_filter_get_brands" value="{$ttl_filter_get_brands|escape}">
                        </div>
                    </div>
                </div>

                {* Blog, Authors — один ряд *}
                <div class="row">
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="form-group">
                            <label class="heading_label"><small>BlogHelper<span class="text_grey">:getList</span></small></label>
                            <input type="number" class="form-control" name="ttl_blog_get_list" value="{$ttl_blog_get_list|escape}">
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="form-group">
                            <label class="heading_label"><small>BlogHelper<span class="text_grey">:attachPostData</span></small></label>
                            <input type="number" class="form-control" name="ttl_blog_attach_post_data" value="{$ttl_blog_attach_post_data|escape}">
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <div class="form-group">
                            <label class="heading_label"><small>AuthorsHelper<span class="text_grey">:getList</span></small></label>
                            <input type="number" class="form-control" name="ttl_authors_get_list" value="{$ttl_authors_get_list|escape}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
