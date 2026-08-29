<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\Internal;

use cebe\openapi\Reader;
use OpenAPITools\Configuration\Gathering;
use OpenAPITools\Gatherer\Gatherer;
use OpenAPITools\Generator\PSR15\WebHook\Internal\HeaderResolutionBuilder;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariant;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariantCollector;
use OpenAPITools\Generator\PSR15\WebHook\Internal\ResolveExpressionBuilder;
use OpenAPITools\Utils\Namespace_;
use PHPUnit\Framework\Attributes\Test;
use WyriHaximus\TestUtilities\TestCase;

use function in_array;
use function md5;
use function ucfirst;

final class HeaderResolutionBuilderTest extends TestCase
{
    #[Test]
    public function buildStatementsHandlesCombinedFixtureVariants(): void
    {
        $fixturePaths = [
            __DIR__ . '/../fixtures/PartialActionEnumWebHooks.yaml',
            __DIR__ . '/../fixtures/CoverageGapWebHooks.yaml',
            __DIR__ . '/../fixtures/ResolveExpressionBuilderWebHooks.yaml',
            __DIR__ . '/../../etc/docs/example-input.yaml',
            __DIR__ . '/../../vendor/openapi-tools/test-data/src/DataSets/BasicWebHooks.yaml',
            __DIR__ . '/../../vendor/openapi-tools/test-data/src/DataSets/MultiVariantWebHooks.yaml',
            __DIR__ . '/../../vendor/openapi-tools/test-data/src/DataSets/DiscriminatedWebHooks.yaml',
        ];

        $variants = [];

        foreach ($fixturePaths as $fixturePath) {
            $variants = [...$variants, ...$this->collectVariants($fixturePath)];
        }

        self::assertNotEmpty($variants);

        $statements = new HeaderResolutionBuilder(new ResolveExpressionBuilder())->buildStatements($variants);

        self::assertNotEmpty($statements);
    }

    /** @return list<array{variant: PayloadVariant, enumCase: string}> */
    private function collectVariants(string $fixturePath): array
    {
        $namespace = new Namespace_(
            'ApiClients\\Client\\Combined\\' . md5($fixturePath),
            'ApiClients\\Tests\\Client\\Combined\\' . md5($fixturePath),
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
        $variants   = [];
        $enumCases  = [];

        foreach ($namespaced->webHooks as $webHookEvent) {
            foreach (PayloadVariantCollector::fromEvent($webHookEvent, $namespaced->schemas) as $variant) {
                $enumCase    = $this->enumCaseName($variant, $enumCases);
                $enumCases[] = $enumCase;
                $variants[]  = [
                    'variant'  => $variant,
                    'enumCase' => $enumCase,
                ];
            }
        }

        return $variants;
    }

    /** @param list<string> $usedEnumCases */
    private function enumCaseName(PayloadVariant $variant, array $usedEnumCases): string
    {
        $baseName = $variant->schema->className->className;

        if (! in_array($baseName, $usedEnumCases, true)) {
            return $baseName;
        }

        if ($variant->discriminatorValue !== '') {
            $candidate = $baseName . ucfirst($variant->discriminatorValue);
            if (! in_array($candidate, $usedEnumCases, true)) {
                return $candidate;
            }
        }

        $suffix = 2;
        do {
            $candidate = $baseName . (string) $suffix;
            ++$suffix;
        } while (in_array($candidate, $usedEnumCases, true));

        return $candidate;
    }
}
