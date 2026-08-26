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

final class CoverageGapWebHooksTest extends TestCase
{
    #[Test]
    public function generateCoversRemainingEdgeCases(): void
    {
        $representation = Gatherer::gather(
            Reader::readFromYamlFile(__DIR__ . '/fixtures/CoverageGapWebHooks.yaml'),
            new Gathering(
                __DIR__ . '/fixtures/CoverageGapWebHooks.yaml',
                null,
                new Gathering\Schemas(
                    true,
                    true,
                ),
            ),
        );

        $package = new Package(
            new Package\Metadata(
                'CoverageGapWebHooks',
                'Coverage gap webhook test client',
                [],
            ),
            'api-clients',
            'coverage-gap-webhooks',
            null,
            null,
            null,
            new Package\Templates(
                __DIR__ . '/templates',
                [],
            ),
            new Package\Destination(
                'coverage-gap-webhooks',
                'src',
                'tests',
            ),
            new Namespace_(
                'ApiClients\Client\CoverageGapWebHooks',
                'ApiClients\Tests\Client\CoverageGapWebHooks',
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
        $hydrator = GeneratedFiles::contents($files, 'Internal\WebHook\Hydrator');

        self::assertStringContainsString('TaggedNotify', $webHooks);
        self::assertStringContainsString('PlainNotify', $webHooks);
        self::assertStringContainsString('SharedConstAlpha', $webHooks);
        self::assertStringContainsString('DualContentPayload', $webHooks);
        self::assertStringContainsString('x-notify-const', $webHooks);
        self::assertStringContainsString('x-shared-const', $webHooks);
        self::assertStringContainsString('x-notify-presence', $webHooks);
        self::assertStringContainsString('array_key_exists', $webHooks);
        self::assertStringContainsString('array_map', $hydrator);
        self::assertStringContainsString("'note'] === null ? null", $hydrator);
        $event = GeneratedFiles::contents($files, 'Internal\WebHook\Event');
        self::assertStringContainsString('DualContentPayload2', $event);
    }
}
