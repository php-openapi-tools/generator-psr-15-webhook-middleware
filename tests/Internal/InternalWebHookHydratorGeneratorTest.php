<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\Internal;

use OpenAPITools\Configuration\Package;
use OpenAPITools\Generator\PSR15\WebHook\InternalWebHookHydratorGenerator;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use WyriHaximus\TestUtilities\TestCase;

final class InternalWebHookHydratorGeneratorTest extends TestCase
{
    #[Test]
    public function generateRejectsEmptyPayloadSchemaList(): void
    {
        $package = new Package(
            new Package\Metadata('Test', 'Test', []),
            'api-clients',
            'test',
            null,
            null,
            null,
            new Package\Templates(__DIR__ . '/../../templates', []),
            new Package\Destination('test', 'src', 'tests'),
            new Namespace_('ApiClients\\Client\\Test', 'ApiClients\\Tests\\Client\\Test'),
            new Package\QA(
                phpcs: new Package\QA\Tool(true, null),
                phpstan: new Package\QA\Tool(true, 'etc/phpstan-extension.neon'),
                psalm: new Package\QA\Tool(false, null),
            ),
            new Package\State([]),
            [],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Expected at least one webhook payload schema.');

        new InternalWebHookHydratorGenerator(new BuilderFactory())->generate($package, []);
    }
}
