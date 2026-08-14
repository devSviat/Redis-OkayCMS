# Тести модуля Redis

Тести модульні: сайт не піднімають, у базу не ходять. Вони перевіряють логіку
класів модуля, підставляючи заглушки замість сервісів ядра, тому весь набір іде
за частку секунди.

## 1. Візьміть тести з репозиторію

В архів релізу тека `tests/` **не входить** — вона потрібна лише розробнику.
Взяти її можна з репозиторію:

```bash
git clone https://github.com/devSviat/Redis-OkayCMS.git
```

або «Source code (zip)» на сторінці потрібного релізу.

## 2. Покладіть їх у OkayCMS

Тести мають лежати за адресою, що дзеркалить адресу самого модуля:

```
{OkayCMS_root}/
├── Okay/Modules/Sviat/Redis/     ← модуль
└── tests/Modules/Sviat/Redis/     ← його тести
```

Тека `tests/` у корені OkayCMS уже існує — у ній лежать тести ядра.

```bash
cd {OkayCMS_root}
mkdir -p tests/Modules/Sviat/Redis
cp -r ~/Redis-OkayCMS/tests/. tests/Modules/Sviat/Redis/
```

Лишити тести в теці модуля не вийде: PHPUnit бере тільки те, що вказано в
`phpunit.xml` OkayCMS, а там `<directory>tests</directory>`. Та й класи ядра,
без яких тести не працюють, приходять із автозавантажувача рушія.

## 3. Запустіть

```bash
cd {OkayCMS_root}
vendor/bin/phpunit tests/Modules/Sviat/Redis
```

Очікуваний результат — рядок `OK (N tests, M assertions)`.

Весь набір разом із тестами ядра — просто `vendor/bin/phpunit`.

**Немає `vendor/bin/phpunit`?** Отже, залежності ставили без dev-пакетів.
PHPUnit входить у поставку OkayCMS як `require-dev`:

```bash
composer install
```

**Потрібен PHP 8.0.** OkayCMS працює і на 7.4, але частина тестів написана
синтаксисом PHP 8 (типи-обʼєднання, `mixed`, просування властивостей у
конструкторі) і на 7.4 просто не розпарситься. Якщо на сервері 7.4 — проганяйте
тести локально на 8.0.

## Як дописати свій тест

1. Створіть файл `SomeServiceTest.php` — назва файла має збігатися з назвою
   класу, обидві закінчуються на `Test`, назви методів починаються з `test`.
2. Неймспейс — `Modules\Sviat\Redis`.
3. Хелпери (фейки, спільні базові класи) кладіть поруч і підключайте
   `require_once` — автозавантаження для теки `tests/` немає.

```php
<?php

namespace Modules\Sviat\Redis;

use Okay\Modules\Sviat\Redis\Services\SomeService;
use PHPUnit\Framework\TestCase;

class SomeServiceTest extends TestCase
{
    public function testReturnsZeroWhenEmpty(): void
    {
        self::assertSame(0, (new SomeService())->count());
    }
}
```

Класи ядра (`Okay\Core\…`, `Okay\Entities\…`) доступні — тести йдуть під
автозавантажувачем рушія. Замість справжніх сервісів зазвичай беруть
`$this->createMock(...)`.

Новий файл дописуйте і в репозиторій модуля, у теку `tests/`, — інакше він
загубиться при наступному оновленні.

## Якщо модуль має працювати і на форку OkayCMS

Форк іде на PHP 8.5 із PHPUnit 13, стокова OkayCMS — на PHP 8.0 з PHPUnit 9.5.
Щоб один і той самий тест був зелений і там, і там, є рівно дві домовленості.

**1. Провайдер даних позначається двічі — і атрибутом, і анотацією.**
PHPUnit 9 читає анотацію й не бачить атрибута, PHPUnit 13 — навпаки.

```php
/** @dataProvider numbers */
#[DataProvider('numbers')]
public function testSomething(int $n): void
```

**2. `setAccessible()` викликається лише до PHP 8.1.**
З 8.1 він не потрібен, а з 8.5 застарілий і робить прогін червоним. Без нього
на 8.0 падає `ReflectionException`. Те саме стосується `ReflectionMethod`.

```php
$p = new \ReflectionProperty(Foo::class, 'bar');
if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); }
```

## Що перевіряє CI

Реліз (`Actions` → `Реліз` → `Run workflow`) не збереться, поки тести червоні:

1. `php -l` на всьому коді модуля під 7.4, 8.0 і 8.5;
2. модуль ставиться в справжній рушій — окремо в стокову OkayCMS, окремо у
   форк — і там запускається `phpunit`;
3. лише після цього піднімається версія, ставиться тег і збирається архів.
