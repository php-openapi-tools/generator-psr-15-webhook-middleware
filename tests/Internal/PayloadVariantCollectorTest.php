<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\Internal;

use cebe\openapi\Reader;
use OpenAPITools\Configuration\Gathering;
use OpenAPITools\Gatherer\Gatherer;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariantCollector;
use OpenAPITools\Utils\Namespace_;
use PHPUnit\Framework\Attributes\Test;
use WyriHaximus\TestUtilities\TestCase;

use function str_replace;

final class PayloadVariantCollectorTest extends TestCase
{
    #[Test]
    public function fromEventExpandsDiscriminatedAndHeaderConstraints(): void
    {
        $fixturePath = __DIR__ . '/../../vendor/openapi-tools/test-data/src/DataSets/DiscriminatedWebHooks.yaml';
        $namespace   = new Namespace_(
            'ApiClients\\Client\\WebHooks\\DiscriminatedWebHooks',
            'ApiClients\\Tests\\Client\\WebHooks\\DiscriminatedWebHooks',
        );

        $representation = Gatherer::gather(
            Reader::readFromYamlFile($fixturePath),
            new Gathering(
                $fixturePath,
                null,
                new Gathering\Schemas(
                    true,
                    true,
                ),
            ),
        );

        $namespaced = $representation->namespace($namespace);
        $variants   = PayloadVariantCollector::fromEvent($namespaced->webHooks[0], $namespaced->schemas);

        self::assertCount(2, $variants);
        self::assertSame('ping', $variants[0]->discriminatorValue);
        self::assertSame('push', $variants[1]->discriminatorValue);
    }

    #[Test]
    public function fromEventExpandsVariantsFromBasicAndMultiVariantFixtures(): void
    {
        $fixturePaths = [
            __DIR__ . '/../../vendor/openapi-tools/test-data/src/DataSets/BasicWebHooks.yaml' => 'ApiClients\\Client\\WebHooks\\BasicWebHooks',
            __DIR__ . '/../../vendor/openapi-tools/test-data/src/DataSets/MultiVariantWebHooks.yaml' => 'ApiClients\\Client\\WebHooks\\MultiVariantWebHooks',
        ];

        foreach ($fixturePaths as $fixturePath => $clientNamespace) {
            $namespace = new Namespace_(
                $clientNamespace,
                str_replace('\\Client\\', '\\Tests\\Client\\', $clientNamespace),
            );

            $representation = Gatherer::gather(
                Reader::readFromYamlFile($fixturePath),
                new Gathering(
                    $fixturePath,
                    null,
                    new Gathering\Schemas(
                        true,
                        true,
                    ),
                ),
            );

            $namespaced = $representation->namespace($namespace);
            $variants   = PayloadVariantCollector::fromEvent($namespaced->webHooks[0], $namespaced->schemas);

            self::assertNotEmpty($variants);
        }
    }

    #[Test]
    public function fromEventCollectsFixedHeaderValues(): void
    {
        $fixturePath = __DIR__ . '/../fixtures/ResolveExpressionBuilderWebHooks.yaml';
        $namespace   = new Namespace_(
            'ApiClients\\Client\\ResolveExpressionBuilderWebHooks',
            'ApiClients\\Tests\\Client\\ResolveExpressionBuilderWebHooks',
        );

        $representation = Gatherer::gather(
            Reader::readFromYamlFile($fixturePath),
            new Gathering(
                $fixturePath,
                null,
                new Gathering\Schemas(
                    true,
                    true,
                ),
            ),
        );

        $namespaced = $representation->namespace($namespace);
        $variants   = PayloadVariantCollector::fromEvent($namespaced->webHooks[0], $namespaced->schemas);

        self::assertCount(1, $variants);
        self::assertTrue($variants[0]->headerConstraints[0]->hasValue);
        self::assertSame('payload', $variants[0]->headerConstraints[0]->value);
    }
}
