{$meta_title = $btr->sviat_redis_settings scope=global}

<div class="main_header">
    <div class="main_header__item">
        <div class="main_header__inner">
            <div class="box_heading heading_page">{$btr->sviat_redis_settings|escape}</div>
        </div>
    </div>
</div>

{* Алерти: збережено / результат тесту підключення *}
{if $message_success || $flush_done || $test_result === true || $test_result === false || $flush_error !== null || $redis_form_errors || $redis_password_dropped}
    <div class="row">
        <div class="col-lg-12">
            {if $message_success}
                <div class="alert alert--success">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->general_settings_saved|default:"Налаштування збережено"|escape}</div>
                    </div>
                </div>
            {/if}
            {if $flush_done}
                <div class="alert alert--success">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->sviat_redis_flush_done|escape}</div>
                    </div>
                </div>
            {/if}
            {if $redis_form_errors}
                <div class="alert alert--warning">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->sviat_redis_form_invalid|escape}</div>
                    </div>
                </div>
            {/if}
            {if $redis_password_dropped}
                <div class="alert alert--warning">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->sviat_redis_password_dropped|escape}</div>
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
            {if $flush_error !== null}
                <div class="alert alert--warning">
                    <div class="alert__content">
                        <div class="alert__title">{$btr->sviat_redis_flush_failed|escape}</div>
                        {if $flush_error}<div class="alert__text">{$flush_error|escape}</div>{/if}
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

<div class="boxed sviat_redis_status sviat_redis_status--{$redis_state|escape}">
    <div class="sviat_redis_status__state">
        <span class="sviat_redis_status__icon">
            {if $redis_state === 'ok'}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5l10 -10"/></svg>
            {elseif $redis_state === 'off'}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v8"/><path d="M7.5 6.5a7 7 0 1 0 9 0"/></svg>
            {else}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8v5"/><path d="M12 16.5v.01"/><path d="M10.3 4.3l-7.4 12.9a2 2 0 0 0 1.7 2.8h14.8a2 2 0 0 0 1.7 -2.8l-7.4 -12.9a2 2 0 0 0 -3.4 0z"/></svg>
            {/if}
        </span>
        <div class="sviat_redis_status__text">
            {if $redis_state === 'ok'}
                <div class="sviat_redis_status__title">{$btr->sviat_redis_state_ok|escape}</div>
                <div>{$btr->sviat_redis_state_ok_hint|escape}</div>
            {elseif $redis_state === 'off'}
                <div class="sviat_redis_status__title">{$btr->sviat_redis_state_off|escape}</div>
                <div>{$btr->sviat_redis_state_off_hint|escape}</div>
            {else}
                <div class="sviat_redis_status__title">{$btr->sviat_redis_state_error|escape}</div>
                {if $redis_stats.error}<div>{$redis_stats.error|escape}</div>{/if}
            {/if}
        </div>
    </div>
    {if $redis_state === 'ok'}
        <div class="sviat_redis_status__metric">
            <span>{$btr->sviat_redis_db_keys_count|escape}</span>
            <strong>{$redis_stats.db_size|escape}</strong>
        </div>
        {if $redis_stats.used_memory}
            <div class="sviat_redis_status__metric">
                <span>{$btr->sviat_redis_used_memory|escape}</span>
                <strong>{$redis_stats.used_memory|escape}</strong>
            </div>
        {/if}
        <form method="post" class="sviat_redis_status__action">
            <input type="hidden" name="session_id" value="{$smarty.session.id}">
            <button type="submit" name="action" value="flush_helpers" class="btn btn_small btn_border_blue">
                {$btr->sviat_redis_flush_current_db|escape}
            </button>
        </form>
    {/if}
</div>

<form method="post">
    <input type="hidden" name="session_id" value="{$smarty.session.id}">
    <div class="boxed tabs">
        <div class="heading_tabs">
            <div class="tab_navigation">
                <a href="#redis_connection" class="heading_box tab_navigation_link">{$btr->sviat_redis_connection_box|escape}</a>
                <a href="#redis_ttl" class="heading_box tab_navigation_link">{$btr->sviat_redis_helpers_ttl|escape}</a>
                <a href="#redis_guide" class="heading_box tab_navigation_link">{$btr->sviat_redis_guide_tab|escape}</a>
            </div>
        </div>

        <div class="tab_container">
            <div id="redis_connection" class="tab">
                <div class="sviat_redis_connection">
                    {if $redis_locked}
                        <div class="alert alert--info">
                            <div class="alert__content">
                                <div class="alert__text">{$btr->sviat_redis_locked_hint|escape}</div>
                            </div>
                        </div>
                    {/if}

                    <div class="form-group">
                        <div class="activity_of_switch activity_of_switch--left">
                            <div class="activity_of_switch_item">
                                <div class="okay_switch clearfix">
                                    <label class="switch_label">{$btr->sviat_redis_enable_cache|escape}</label>
                                    <label class="switch switch-default">
                                        <input class="switch-input" name="enabled" value="1" type="checkbox"{if $redis_locked.enabled} disabled{/if} id="sviat_redis_enabled" {if $redis_enabled}checked{/if}>
                                        <span class="switch-label"></span>
                                        <span class="switch-handle"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="sviat_redis_server">
                        <div class="form-group">
                            <label class="heading_label" for="sviat_redis_host">{$btr->sviat_redis_host|escape}</label>
                            <input id="sviat_redis_host" type="text" class="form-control" name="host" value="{$redis_host|escape}"{if $redis_locked.host} readonly{/if}>
                        </div>
                        <div class="form-group">
                            <label class="heading_label" for="sviat_redis_port">{$btr->sviat_redis_port|escape}</label>
                            <input id="sviat_redis_port" type="number" class="form-control" name="port" value="{$redis_port|escape}"{if $redis_locked.port} readonly{/if}>
                        </div>
                        <div class="form-group">
                            <label class="heading_label" for="sviat_redis_db">{$btr->sviat_redis_db|escape}</label>
                            <input id="sviat_redis_db" type="number" class="form-control" name="db" value="{$redis_db|escape}"{if $redis_locked.db} readonly{/if}>
                        </div>
                    </div>

                    {if !$redis_locked.password}
                    <div class="form-group">
                        <label class="heading_label" for="sviat_redis_password">{$btr->sviat_redis_password|escape}</label>
                        <input id="sviat_redis_password" type="password" autocomplete="new-password" class="form-control" name="password" value="" placeholder="{if $redis_password_is_set}{$btr->sviat_redis_password_placeholder|escape}{else}{$btr->sviat_redis_password_placeholder_empty|escape}{/if}" autocomplete="new-password">
                        {if $redis_password_is_set}
                            <label class="form-check-label" style="display:block; margin-top:8px;">
                                <input type="checkbox" name="clear_password" value="1">
                                {$btr->sviat_redis_clear_password|escape}
                            </label>
                        {/if}
                    </div>
                    {/if}

                    <div class="form-group">
                        <label class="heading_label" for="sviat_redis_username">{$btr->sviat_redis_username|escape}</label>
                        <input id="sviat_redis_username" type="text" class="form-control" name="username" value="{$redis_username|escape}"{if $redis_locked.username} readonly{/if} placeholder="{$btr->sviat_redis_username_placeholder|escape}">
                    </div>

                    <div class="form-group mb-0">
                        <label class="heading_label" for="sviat_redis_prefix">{$btr->sviat_redis_key_prefix|escape}</label>
                        <input id="sviat_redis_prefix" type="text" class="form-control" name="prefix" value="{$redis_prefix|escape}"{if $redis_locked.prefix} readonly{/if}>
                    </div>
                </div>
            </div>

            <div id="redis_ttl" class="tab">
                <p>{$btr->sviat_redis_ttl_hint|escape}</p>
                <div class="form-group">
                    <label class="heading_label" for="sviat_redis_default_ttl">{$btr->sviat_redis_default_ttl|escape}</label>
                    <input id="sviat_redis_default_ttl" type="number" min="1" class="form-control sviat_redis_ttl_default" name="default_ttl" value="{$redis_default_ttl|escape}">
                </div>
                <div class="sviat_redis_ttl_title">{$btr->sviat_redis_ttl_more|escape}</div>
                <div class="sviat_redis_ttl_grid">
                    <div>
                        <div class="sviat_redis_ttl_group">{$btr->sviat_redis_group_products|escape}</div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_products_get_list" title="ProductsHelper::getList">{$btr->sviat_redis_label_ttl_products_get_list|escape}</label>
                            <input id="ttl_products_get_list" type="number" min="0" class="form-control" name="ttl_products_get_list" value="{$ttl_products_get_list|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_product_attach_variants" title="attachProductData (variants)">{$btr->sviat_redis_label_ttl_product_attach_variants|escape}</label>
                            <input id="ttl_product_attach_variants" type="number" min="0" class="form-control" name="ttl_product_attach_variants" value="{$ttl_product_attach_variants|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_product_attach_images" title="attachProductData (images)">{$btr->sviat_redis_label_ttl_product_attach_images|escape}</label>
                            <input id="ttl_product_attach_images" type="number" min="0" class="form-control" name="ttl_product_attach_images" value="{$ttl_product_attach_images|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_product_attach_features" title="attachProductData (features)">{$btr->sviat_redis_label_ttl_product_attach_features|escape}</label>
                            <input id="ttl_product_attach_features" type="number" min="0" class="form-control" name="ttl_product_attach_features" value="{$ttl_product_attach_features|escape}">
                        </div>
                        </div>
                    <div>
                        <div class="sviat_redis_ttl_group">{$btr->sviat_redis_group_catalog|escape}</div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_catalog_features" title="CatalogHelper::getCatalogFeatures">{$btr->sviat_redis_label_ttl_catalog_features|escape}</label>
                            <input id="ttl_catalog_features" type="number" min="0" class="form-control" name="ttl_catalog_features" value="{$ttl_catalog_features|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_catalog_features_filter" title="CatalogHelper::getCatalogFeaturesFilter">{$btr->sviat_redis_label_ttl_catalog_features_filter|escape}</label>
                            <input id="ttl_catalog_features_filter" type="number" min="0" class="form-control" name="ttl_catalog_features_filter" value="{$ttl_catalog_features_filter|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_categories_catalog_features" title="CategoriesHelper::getCatalogFeatures">{$btr->sviat_redis_label_ttl_categories_catalog_features|escape}</label>
                            <input id="ttl_categories_catalog_features" type="number" min="0" class="form-control" name="ttl_categories_catalog_features" value="{$ttl_categories_catalog_features|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_brands_get_list" title="BrandsHelper::getList">{$btr->sviat_redis_label_ttl_brands_get_list|escape}</label>
                            <input id="ttl_brands_get_list" type="number" min="0" class="form-control" name="ttl_brands_get_list" value="{$ttl_brands_get_list|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_filter_get_brands" title="FilterHelper::getBrands">{$btr->sviat_redis_label_ttl_filter_get_brands|escape}</label>
                            <input id="ttl_filter_get_brands" type="number" min="0" class="form-control" name="ttl_filter_get_brands" value="{$ttl_filter_get_brands|escape}">
                        </div>
                        </div>
                    <div>
                        <div class="sviat_redis_ttl_group">{$btr->sviat_redis_group_blog|escape}</div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_blog_get_list" title="BlogHelper::getList">{$btr->sviat_redis_label_ttl_blog_get_list|escape}</label>
                            <input id="ttl_blog_get_list" type="number" min="0" class="form-control" name="ttl_blog_get_list" value="{$ttl_blog_get_list|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_blog_attach_post_data" title="BlogHelper::attachPostData">{$btr->sviat_redis_label_ttl_blog_attach_post_data|escape}</label>
                            <input id="ttl_blog_attach_post_data" type="number" min="0" class="form-control" name="ttl_blog_attach_post_data" value="{$ttl_blog_attach_post_data|escape}">
                        </div>
                        <div class="sviat_redis_ttl">
                            <label for="ttl_authors_get_list" title="AuthorsHelper::getList">{$btr->sviat_redis_label_ttl_authors_get_list|escape}</label>
                            <input id="ttl_authors_get_list" type="number" min="0" class="form-control" name="ttl_authors_get_list" value="{$ttl_authors_get_list|escape}">
                        </div>
                        </div>
                </div>
            </div>

            <div id="redis_guide" class="tab">
                <div class="sviat_redis_guide">
                    <div>
                        <div class="sviat_redis_ttl_title">{$btr->sviat_redis_guide_title|escape}</div>
                        <p>{$btr->sviat_redis_guide_about|escape}</p>
                        <ol>
                            <li>{$btr->sviat_redis_guide_step_connect|escape}</li>
                            <li>{$btr->sviat_redis_guide_step_test|escape}</li>
                            <li>{$btr->sviat_redis_guide_step_ttl|escape}</li>
                        </ol>
                    </div>
                    <div>
                        <div class="sviat_redis_ttl_title">{$btr->sviat_redis_guide_config_title|escape}</div>
                        <p>{$btr->sviat_redis_guide_config_text|escape}</p>
<pre>[redis]
redis_enabled = 1
redis_host = 127.0.0.1
redis_port = 6379
redis_db = 0
redis_password = 'your-password'
redis_prefix = shop1:</pre>
                        <p class="mb-0">{$btr->sviat_redis_guide_config_note|escape}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="sviat_redis_actions">
            <button type="submit" name="action" value="save" class="btn btn_blue">
                {$btr->general_apply|default:"Зберегти"|escape}
            </button>
            <button type="submit" name="action" value="test" class="btn btn_border_blue">
                {$btr->sviat_redis_test_connection|escape}
            </button>
        </div>
    </div>
</form>
