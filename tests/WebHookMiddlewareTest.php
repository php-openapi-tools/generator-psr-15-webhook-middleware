<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook;

use cebe\openapi\Reader;
use OpenAPITools\Configuration\Gathering;
use OpenAPITools\Configuration\Package;
use OpenAPITools\Gatherer\Gatherer;
use OpenAPITools\Generator\PSR15\WebHook\WebHookMiddleware;
use OpenAPITools\Representation\Representation;
use OpenAPITools\TestData\DataSet;
use OpenAPITools\TestData\Provider;
use OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests\GeneratedFilesAssertion;
use OpenAPITools\Utils\File;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use WyriHaximus\TestUtilities\TestCase;

use function class_exists;
use function in_array;
use function is_string;
use function is_subclass_of;
use function iterator_to_array;

final class WebHookMiddlewareTest extends TestCase
{
    #[Test]
    #[DataProviderExternal(Provider::class, 'sets')]
    public function generate(DataSet $dataSet): void
    {
        if (! in_array($dataSet->name, ['BasicWebHooks', 'DiscriminatedWebHooks', 'MultiVariantWebHooks'], true)) {
            self::markTestSkipped('No webhook middleware assertion class for dataset: ' . $dataSet->name);
        }

        $representation = $this->loadSpec($dataSet->fileName);

        /** @var class-string<GeneratedFilesAssertion> $testClassName */
        $testClassName = '\OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests\\' . $dataSet->name;
        self::assertTrue(class_exists($testClassName));
        self::assertTrue(is_subclass_of($testClassName, GeneratedFilesAssertion::class));

        $package = new Package(
            new Package\Metadata(
                'WebHooks',
                'WebHook middleware test client',
                [],
            ),
            'api-clients',
            'webhooks',
            null,
            null,
            null,
            new Package\Templates(
                __DIR__ . '/templates',
                [],
            ),
            new Package\Destination(
                'webhooks',
                'src',
                'tests',
            ),
            new Namespace_(
                'ApiClients\Client\WebHooks\\' . $dataSet->name,
                'ApiClients\Tests\Client\WebHooks\\' . $dataSet->name,
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

        $files          = [];
        $generatedFiles = new WebHookMiddleware(new BuilderFactory(), ['/webhook'])->generate($package, $representation->namespace($package->namespace));

        foreach ($generatedFiles as $generatedFile) {
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

        $testClassName::assertGeneratedFiles($files);
    }

    #[Test]
    public function generateNothingWithoutWebHooks(): void
    {
        foreach (Provider::sets() as $name => $dataSets) {
            if ($name !== 'Basic') {
                continue;
            }

            $representation = $this->loadSpec($dataSets[0]->fileName);

            $package = new Package(
                new Package\Metadata(
                    'Basic',
                    'Basic test client',
                    [],
                ),
                'api-clients',
                'basic',
                null,
                null,
                null,
                new Package\Templates(
                    __DIR__ . '/templates',
                    [],
                ),
                new Package\Destination(
                    'basic',
                    'src',
                    'tests',
                ),
                new Namespace_(
                    'ApiClients\Client\Basic',
                    'ApiClients\Tests\Client\Basic',
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

            self::assertSame([], iterator_to_array(new WebHookMiddleware(new BuilderFactory(), ['/webhook'])->generate($package, $representation->namespace($package->namespace)), false));

            return;
        }

        self::fail('Basic dataset not found');
    }

    private function loadSpec(string $dataSetName): Representation
    {
        return Gatherer::gather(
            Reader::readFromYamlFile($dataSetName),
            new Gathering(
                $dataSetName,
                null,
                new Gathering\Schemas(
                    true,
                    true,
                ),
            ),
        );
    }
}
