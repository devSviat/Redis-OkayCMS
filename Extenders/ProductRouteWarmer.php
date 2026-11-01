<?php

namespace Okay\Modules\Sviat\Redis\Extenders;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Router;
use Okay\Core\Routes\ProductRoute;
use Okay\Entities\RouterCacheEntity;

/**
 * Прогріває маршрут поточного товару до мовного перемикача.
 *
 * Перемикач будує URL ще до контролера, і без псевдоніма ядро вичитує всю
 * таблицю маршрутів. Запит тут точковий, за ключем url_type.
 */
class ProductRouteWarmer implements ExtensionInterface
{
    private const string PRODUCT_ROUTE = 'product';

    private Router $router;
    private EntityFactory $entityFactory;

    public function __construct(Router $router, EntityFactory $entityFactory)
    {
        $this->router = $router;
        $this->entityFactory = $entityFactory;
    }

    public function warmCurrentProduct(): void
    {
        if ($this->routeName() !== self::PRODUCT_ROUTE) {
            return;
        }

        $url = (string) ($this->router->getCurrentRouteRequiredParams()['url'] ?? '');
        if ($url === '' || ProductRoute::getUrlSlugAlias($url)) {
            return;
        }

        $route = $this->entityFactory->get(RouterCacheEntity::class)->findOne(['url' => $url, 'type' => 'product']);
        if (!empty($route->slug_url)) {
            ProductRoute::setUrlSlugAlias($url, $route->slug_url);
        }
    }

    protected function routeName(): ?string
    {
        return Router::getCurrentRouteName();
    }
}
