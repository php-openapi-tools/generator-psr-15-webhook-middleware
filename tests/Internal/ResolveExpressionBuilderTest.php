<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\Internal;

use cebe\openapi\Reader;
use InvalidArgumentException;
use OpenAPITools\Configuration\Gathering;
use OpenAPITools\Gatherer\Gatherer;
use OpenAPITools\Generator\PSR15\WebHook\Internal\Discriminator;
use OpenAPITools\Generator\PSR15\WebHook\Internal\HeaderConstraint;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariant;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariantCollector;
use OpenAPITools\Generator\PSR15\WebHook\Internal\ResolveExpressionBuilder;
use OpenAPITools\Representation\Namespaced\Schema;
use OpenAPITools\Utils\Namespace_;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use WyriHaximus\TestUtilities\TestCase;

use function array_find;

final class ResolveExpressionBuilderTest extends TestCase
{
    private ResolveExpressionBuilder $builder;

    private Namespace_ $namespace;

    protected function setUp(): void
    {
        $this->builder   = new ResolveExpressionBuilder();
        $this->namespace = new Namespace_(
            'ApiClients\\Client\\ResolveExpressionBuilderWebHooks',
            'ApiClients\\Tests\\Client\\ResolveExpressionBuilderWebHooks',
        );
    }

    #[Test]
    public function headerPreconditionsCombinesPresenceAndValueChecks(): void
    {
        $expression = $this->builder->headerPreconditions([
            HeaderConstraint::presence('x-event'),
            HeaderConstraint::fixed('x-kind', 'ping'),
        ]);

        self::assertNotNull($expression);
    }

    #[Test]
    public function headerPreconditionsReturnsNullForEmptyConstraints(): void
    {
        self::assertNull($this->builder->headerPreconditions([]));
    }

    #[Test]
    public function headerPresencePreconditionsReturnsNullForEmptyHeaderNames(): void
    {
        self::assertNull($this->builder->headerPresencePreconditions([]));
    }

    #[Test]
    public function headerValuePreconditionsBuildsValueChecks(): void
    {
        $expression = $this->builder->headerValuePreconditions([
            HeaderConstraint::fixed('x-kind', 'ping'),
            HeaderConstraint::presence('x-event'),
        ]);

        self::assertNotNull($expression);
    }

    #[Test]
    public function headerValuePreconditionsReturnsNullWithoutFixedValues(): void
    {
        self::assertNull($this->builder->headerValuePreconditions([
            HeaderConstraint::presence('x-event'),
        ]));
    }

    #[Test]
    public function dataPresencePreconditionsReturnsNullForEmptyFields(): void
    {
        self::assertNull($this->builder->dataPresencePreconditions([]));
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function nestedArrayKeyExistsSupportsNestedPaths(): void
    {
        $this->builder->nestedArrayKeyExists('data', ['metadata', 'kind']);
    }

    #[Test]
    public function nestedArrayKeyExistsRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->nestedArrayKeyExists('data', []);
    }

    #[Test]
    public function bodyPreconditionsHandlesDiscriminatorEnumAndRequiredFields(): void
    {
        $variant = $this->firstVariantFromFixture(__DIR__ . '/../fixtures/ResolveExpressionBuilderWebHooks.yaml');

        self::assertNotNull($this->builder->bodyPreconditions($variant));
        self::assertNotNull($this->builder->bodyPreconditions(
            $variant,
            assumedEnumPropertyPaths: [['metadata', 'kind']],
            satisfiedEnumPropertyPaths: [['action']],
            presenceFields: ['sender'],
            assumedPresenceFields: ['repository'],
        ));
        self::assertNotNull($this->builder->bodyPreconditions(
            $variant,
            presenceFields: ['repository'],
        ));
    }

    #[Test]
    public function bodyPreconditionsReturnsNullWhenNothingIsRequired(): void
    {
        $variant = new PayloadVariant(
            $this->schemaNamed('EmptyPayload'),
            'application/json',
            [],
            [],
            Discriminator::none(),
            '',
            [],
        );

        self::assertNull($this->builder->bodyPreconditions($variant));
    }

    #[Test]
    public function bodyPreconditionsIncludesDiscriminatorValue(): void
    {
        $variants             = $this->variantsFromFixture(__DIR__ . '/../../vendor/openapi-tools/test-data/src/DataSets/DiscriminatedWebHooks.yaml');
        $discriminatedVariant = array_find($variants, static fn (PayloadVariant $variant): bool => $variant->discriminatorValue !== '');

        self::assertInstanceOf(PayloadVariant::class, $discriminatedVariant);
        self::assertNotNull($this->builder->bodyPreconditions($discriminatedVariant));
    }

    /** @return list<PayloadVariant> */
    private function variantsFromFixture(string $fixturePath): array
    {
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

        $namespaced = $representation->namespace($this->namespace);
        $variants   = [];

        foreach ($namespaced->webHooks as $webHookEvent) {
            foreach (PayloadVariantCollector::fromEvent($webHookEvent, $namespaced->schemas) as $variant) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    private function firstVariantFromFixture(string $fixturePath): PayloadVariant
    {
        $variants = $this->variantsFromFixture($fixturePath);

        self::assertNotEmpty($variants);

        return $variants[0];
    }

    private function schemaNamed(string $className): Schema
    {
        $representation = Gatherer::gather(
            Reader::readFromYamlFile(__DIR__ . '/../fixtures/ResolveExpressionBuilderWebHooks.yaml'),
            new Gathering(
                __DIR__ . '/../fixtures/ResolveExpressionBuilderWebHooks.yaml',
                null,
                new Gathering\Schemas(
                    true,
                    true,
                ),
            ),
        );

        foreach ($representation->namespace($this->namespace)->schemas as $schema) {
            if ($schema->className->className === $className) {
                return $schema;
            }
        }

        self::fail('Schema not found: ' . $className);
    }
}
