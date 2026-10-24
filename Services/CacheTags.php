<?php

namespace Okay\Modules\Sviat\Redis\Services;

final class CacheTags
{
    public const string PRODUCT_VERSION = 'pver:';
    public const string PRODUCTS_ALL    = 'pall:global';
    public const string PRODUCTS_LIST   = 'plist:global';
    public const string CATEGORIES      = 'cver:global';
    public const string BRANDS          = 'bver:global';
    public const string AUTHORS_BLOG    = 'aver:global';
    public const string MONEY           = 'mver:global';

    public static function product(int $productId): string
    {
        return self::PRODUCT_VERSION . $productId;
    }
}
