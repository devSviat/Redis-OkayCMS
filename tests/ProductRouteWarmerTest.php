<?php

namespace Modules\Sviat\Redis;

use Okay\Core\EntityFactory;
use Okay\Core\Router;
use Okay\Core\Routes\AbstractRoute;
use Okay\Core\Routes\ProductRoute;
use Okay\Entities\RouterCacheEntity;
use Okay\Modules\Sviat\Redis\Extenders\ProductRouteWarmer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PrivateAccess.php';

class ProductRouteWarmerTest extends TestCase
{
    use PrivateAccess;

    private array $lookups = [];

    protected function setUp(): void
    {
        self::accessible(new \ReflectionProperty(AbstractRoute::class, 'routeAliases'))->setValue(null, []);
        $this->lookups = [];
    }

    private function warmer(string $routeName, array $params, ?object $row): ProductRouteWarmer
    {
        $router = $this->createStub(Router::class);
        $router->method('getCurrentRouteRequiredParams')->willReturn($params);

        $entity = $this->createStub(RouterCacheEntity::class);
        $entity->method('findOne')->willReturnCallback(function (array $filter) use ($row) {
            $this->lookups[] = $filter;
            return $row;
        });
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return new class ($router, $factory, $routeName) extends ProductRouteWarmer {
            public function __construct(Router $router, EntityFactory $factory, private string $name)
            {
                parent::__construct($router, $factory);
            }

            protected function routeName(): ?string
            {
                return $this->name;
            }
        };
    }

    public function testProductPageGetsAliasByPointLookup(): void
    {
        $this->warmer('product', ['url' => 'kettle'], (object) ['slug_url' => 'kitchen/kettle'])->warmCurrentProduct();

        self::assertSame('kitchen/kettle', ProductRoute::getUrlSlugAlias('kettle'));
        self::assertSame([['url' => 'kettle', 'type' => 'product']], $this->lookups);
    }

    public function testOtherRoutesDoNoWork(): void
    {
        $this->warmer('category', ['url' => 'kitchen'], (object) ['slug_url' => 'x'])->warmCurrentProduct();

        self::assertSame([], $this->lookups);
    }

    public function testKnownAliasIsNotQueriedAgain(): void
    {
        ProductRoute::setUrlSlugAlias('kettle', 'kitchen/kettle');

        $this->warmer('product', ['url' => 'kettle'], null)->warmCurrentProduct();

        self::assertSame([], $this->lookups);
    }

    public function testMissingRouteLeavesCoreFallback(): void
    {
        $this->warmer('product', ['url' => 'new-product'], null)->warmCurrentProduct();

        self::assertEmpty(ProductRoute::getUrlSlugAlias('new-product'));
    }
}
