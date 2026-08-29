<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook;

use cebe\openapi\Reader;
use OpenAPITools\Configuration\Gathering;
use OpenAPITools\Configuration\Package;
use OpenAPITools\Gatherer\Gatherer;
use OpenAPITools\Generator\PSR15\WebHook\WebHookMiddleware;
use OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests\GeneratedFiles;
use OpenAPITools\Utils\File;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\Test;
use WyriHaximus\TestUtilities\TestCase;

use function is_string;

final class ExampleInputWebHooksTest extends TestCase
{
    #[Test]
    public function generateResolvesMixedHeaderAndDiscriminatedWebhooks(): void
    {
        $representation = Gatherer::gather(
            Reader::readFromYamlFile(__DIR__ . '/../etc/docs/example-input.yaml'),
            new Gathering(
                __DIR__ . '/../etc/docs/example-input.yaml',
                null,
                new Gathering\Schemas(
                    true,
                    true,
                ),
            ),
        );

        $package = new Package(
            new Package\Metadata(
                'ExampleInputWebHooks',
                'Example input webhook test client',
                [],
            ),
            'api-clients',
            'example-input-webhooks',
            null,
            null,
            null,
            new Package\Templates(
                __DIR__ . '/templates',
                [],
            ),
            new Package\Destination(
                'example-input-webhooks',
                'src',
                'tests',
            ),
            new Namespace_(
                'ApiClients\Client\ExampleInputWebHooks',
                'ApiClients\Tests\Client\ExampleInputWebHooks',
            ),
            new Package\QA(
                phpcs: new Package\QA\Tool(true, null),
                phpstan: new Package\QA\Tool(
                    true,
                    'etc/phpstan-extension.neon',
                ),
                psalm: new Package\QA\Tool(false, null),
            ),
            new Package\State(
                [
                    'composer.json',
                    'composer.lock',
                ],
            ),
            [],
        );

        $files = [];
        foreach (new WebHookMiddleware(new BuilderFactory(), ['/webhook'])->generate($package, $representation->namespace($package->namespace)) as $generatedFile) {
            $files[$generatedFile->fqcn] = new File(
                $generatedFile->pathPrefix,
                $generatedFile->fqcn,
                is_string($generatedFile->contents) ? $generatedFile->contents : new Standard()->prettyPrint([
                    new Node\Stmt\Declare_([
                        new Node\Stmt\DeclareDeclare('strict_types', new Node\Scalar\LNumber(1)),
                    ]),
                    $generatedFile->contents,
                ]),
                File::DO_LOAD_ON_WRITE,
            );
        }

        $webHooks = GeneratedFiles::contents($files, 'Internal\WebHook\WebHooks');

        self::assertStringContainsString('resolveByHeaders', $webHooks);
        self::assertStringContainsString('detectDiscriminatedEvent', $webHooks);
        self::assertStringContainsString('x-petstore-event', $webHooks);
        self::assertStringContainsString("match (\$headers['x-petstore-event'])", $webHooks);
        self::assertStringContainsString("'healthCheck'", $webHooks);
        self::assertStringContainsString("'inventoryUpdate'", $webHooks);
        self::assertStringContainsString("match (\$data['action'])", $webHooks);
        self::assertStringContainsString("match (\$data['eventType'])", $webHooks);
        self::assertStringContainsString('resolvedPayload', $webHooks);
    }
}
