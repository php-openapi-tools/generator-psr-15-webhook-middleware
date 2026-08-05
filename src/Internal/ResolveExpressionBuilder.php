<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariant as PayloadVariantDto;
use OpenAPITools\Generator\Utils\Builder\ExpressionBuilder;
use OpenAPITools\Representation\Namespaced\Schema;
use OpenAPITools\Representation\Namespaced\WebHookEvent;
use PhpParser\Node\Expr;

use function array_values;

final class ResolveExpressionBuilder
{
    public function preconditions(PayloadVariantDto $variant): Expr|null
    {
        $conditions = [];

        foreach ($variant->headerConstraints as $constraint) {
            $conditions[] = ExpressionBuilder::identical(
                ExpressionBuilder::arrayFetch('headers', $constraint->name),
                ExpressionBuilder::literalString($constraint->expectedValue),
            );
        }

        if ($variant->discriminatorValue !== '') {
            $conditions[] = ExpressionBuilder::identical(
                ExpressionBuilder::arrayFetch('data', $variant->discriminator->propertyName),
                ExpressionBuilder::literalString($variant->discriminatorValue),
            );
        }

        foreach ($variant->requiredFields as $field) {
            $conditions[] = ExpressionBuilder::funcCall('array_key_exists', [
                ExpressionBuilder::literalString($field),
                'data',
            ]);
        }

        if ($conditions === []) {
            return null;
        }

        return ExpressionBuilder::andAll($conditions);
    }

    /**
     * @param array<Schema>       $schemas
     * @param array<WebHookEvent> $webHookEvents
     *
     * @return list<Schema>
     */
    public static function collectPayloadSchemas(array $schemas, array $webHookEvents): array
    {
        $payloadSchemas = [];

        foreach ($webHookEvents as $webHookEvent) {
            foreach (PayloadVariantCollector::fromEvent($webHookEvent, $schemas) as $variant) {
                $payloadSchemas[$variant->schema->className->fullyQualified->source] = $variant->schema;
            }
        }

        return array_values($payloadSchemas);
    }
}
