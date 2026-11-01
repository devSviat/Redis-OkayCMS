<?php

namespace Modules\Sviat\Redis;

use PHPUnit\Framework\TestCase;

/**
 * Хук на метод, якого немає, не дає помилки на вітрині: кеш просто не
 * скидається. А непідключений on*-метод виглядає робочим лише в коді.
 */
class InitRegistrationTest extends TestCase
{
    private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/Redis';
    private const string EXTENDERS = 'Okay\\Modules\\Sviat\\Redis\\Extenders\\';

    /** @return list<array{string, string, string, string}> ціль і екстендер кожної реєстрації */
    private function registrations(): array
    {
        $source = (string) file_get_contents(self::MODULE . '/Init/Init.php');
        preg_match_all('/^use ([\\\\\w]+);/m', $source, $uses);
        $imports = [];
        foreach ($uses[1] as $fqcn) {
            $imports[substr((string) strrchr('\\' . $fqcn, '\\'), 1)] = $fqcn;
        }

        $pair = "\\[\\s*(?:'class'\\s*=>\\s*)?(\\w+)::class,\\s*(?:'method'\\s*=>\\s*)?'(\\w+)'\\s*\\]";
        preg_match_all("/register(?:Queue|Chain)Extension\\(\\s*{$pair},\\s*{$pair}\\s*\\)/", $source, $matches, PREG_SET_ORDER);

        return array_map(
            fn ($m) => [$imports[$m[1]], $m[2], $imports[$m[3]], $m[4]],
            $matches
        );
    }

    public function testRegistrationsAreFound(): void
    {
        $source = (string) file_get_contents(self::MODULE . '/Init/Init.php');

        self::assertSame(substr_count($source, 'Extension('), count($this->registrations()), 'регулярка мусить бачити кожну реєстрацію');
    }

    public function testEveryHookPointsAtExistingMethods(): void
    {
        foreach ($this->registrations() as [$target, $targetMethod, $extender, $extenderMethod]) {
            self::assertTrue(method_exists($target, $targetMethod), "$target::$targetMethod");
            self::assertTrue(method_exists($extender, $extenderMethod), "$extender::$extenderMethod");
        }
    }

    /** method_exists тут замало: хук на метод без ExtenderFacade просто ніколи не викликається. */
    public function testEveryHookedMethodIsActuallyExtendable(): void
    {
        foreach ($this->registrations() as [$target, $targetMethod]) {
            self::assertTrue($this->reachesExtender(new \ReflectionMethod($target, $targetMethod)), "$target::$targetMethod");
        }
    }

    /** Сутності перевизначають update() і кличуть parent::, а ExtenderFacade лежить у базовому CRUD. */
    private function reachesExtender(\ReflectionMethod $method): bool
    {
        $source = implode('', array_slice(
            file((string) $method->getFileName()),
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1
        ));
        if (str_contains($source, 'ExtenderFacade::execute')) {
            return true;
        }

        $parent = $method->getDeclaringClass()->getParentClass();

        return $parent !== false
            && str_contains($source, 'parent::' . $method->getName())
            && $this->reachesExtender($parent->getMethod($method->getName()));
    }

    public function testEveryInvalidatorHandlerIsRegistered(): void
    {
        $registered = [];
        foreach ($this->registrations() as [, , $extender, $method]) {
            $registered["$extender::$method"] = true;
        }

        foreach (glob(self::MODULE . '/Extenders/*Invalidator.php') as $file) {
            $class = self::EXTENDERS . basename($file, '.php');
            foreach (get_class_methods($class) as $method) {
                if (str_starts_with($method, 'on')) {
                    self::assertArrayHasKey("$class::$method", $registered);
                }
            }
        }
    }

    public function testEveryExtenderIsADeclaredService(): void
    {
        $services = (string) file_get_contents(self::MODULE . '/Init/services.php');

        foreach (glob(self::MODULE . '/Extenders/*.php') as $file) {
            self::assertStringContainsString(basename($file, '.php') . '::class', $services, basename($file));
        }
    }
}
