<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use SilverStripe\Core\Injector\Factory;
use Talaria\SilverStripe\GuzzleClientFactory;
use Talaria\SilverStripe\InjectorFactory;
use Talaria\SilverStripe\LogHandlerFactory;

/**
 * Loads the adapter classes against the installed silverstripe/framework (or the
 * Silverstripe 6-shaped stub when the framework is absent). A signature that
 * drifts from the framework's interface or parent class is a fatal error at
 * load time, so this fails loudly on the framework major that broke it.
 */
final class FrameworkCompatibilityTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string}>
     */
    public static function factories(): iterable
    {
        yield 'InjectorFactory' => [InjectorFactory::class];
        yield 'GuzzleClientFactory' => [GuzzleClientFactory::class];
        yield 'LogHandlerFactory' => [LogHandlerFactory::class];
    }

    /**
     * @dataProvider factories
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('factories')]
    public function testFactoryMatchesSilverstripe6Signature(string $class): void
    {
        self::assertTrue(is_subclass_of($class, Factory::class), $class . ' must implement Factory');

        $method = new \ReflectionMethod($class, 'create');
        $param = $method->getParameters()[0];
        // Untyped (or string) is accepted by both SS4/5 (untyped) and SS6 (string).
        $paramType = $param->getType();
        self::assertTrue(
            $paramType === null || ($paramType instanceof \ReflectionNamedType && $paramType->getName() === 'string'),
            $class . '::create $service must be untyped or string',
        );

        // SS6 declares ?object; a concrete class return type is covariant with it.
        $return = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $return, $class . '::create needs a return type for SS6');
        self::assertFalse($return->isBuiltin() && $return->getName() !== 'object', $class . '::create must return an object type');
    }

    public function testInjectorFactoryCreateReturnsClientWithoutThrowing(): void
    {
        InjectorFactory::reset();
        $previous = ini_get('error_log');
        $log = (string) tempnam(sys_get_temp_dir(), 'talaria-ss');
        ini_set('error_log', $log);
        try {
            $client = (new InjectorFactory())->create(\Talaria\TalariaClient::class);
            self::assertInstanceOf(\Talaria\TalariaClient::class, $client);
        } finally {
            InjectorFactory::reset();
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }

    /**
     * @return iterable<string, array{0: class-string, 1: class-string}>
     */
    public static function frameworkClasses(): iterable
    {
        yield 'HttpMiddleware' => [\Talaria\SilverStripe\HttpMiddleware::class, \SilverStripe\Control\Middleware\HTTPMiddleware::class];
        yield 'FrontendExtension' => [\Talaria\SilverStripe\FrontendExtension::class, \SilverStripe\Core\Extension::class];
        yield 'LeftAndMainExtension' => [\Talaria\SilverStripe\LeftAndMainExtension::class, \SilverStripe\Core\Extension::class];
        yield 'TracingMySQLDatabase' => [\Talaria\SilverStripe\TracingMySQLDatabase::class, \SilverStripe\ORM\Connect\MySQLDatabase::class];
    }

    /**
     * @dataProvider frameworkClasses
     * @param class-string $class
     * @param class-string $parent
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkClasses')]
    public function testFrameworkClassLoads(string $class, string $parent): void
    {
        if (!class_exists($parent) && !interface_exists($parent)) {
            self::markTestSkipped('silverstripe/framework is not installed');
        }

        self::assertTrue(class_exists($class), $class . ' must load against the installed framework');
        self::assertTrue(is_subclass_of($class, $parent));
    }
}
