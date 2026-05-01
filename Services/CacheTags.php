<?php

namespace Okay\Modules\Sviat\Redis\Services;

final class CacheTags
{
    public const PRODUCT_VERSION = 'pver:';
    public const PRODUCTS_ALL    = 'pall:global';
    public const PRODUCTS_LIST   = 'plist:global';
    public const CATEGORIES      = 'cver:global';
    public const BRANDS          = 'bver:global';
    public const AUTHORS_BLOG    = 'aver:global';
    public const MONEY           = 'mver:global';

    public static function product(int $productId): string
    {
        return self::PRODUCT_VERSION . $productId;
    }
}
