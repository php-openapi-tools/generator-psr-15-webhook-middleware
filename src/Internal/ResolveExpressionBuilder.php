<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

use InvalidArgumentException;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariant as PayloadVariantDto;
use OpenAPITools\Generator\Utils\Builder\ExpressionBuilder;
use OpenAPITools\Representation\Namespaced\Schema;
use OpenAPITools\Representation\Namespaced\WebHookEvent;
use PhpParser\Node\Expr;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function in_array;

final class ResolveExpressionBuilder
{
    /** @param list<HeaderConstraint> $headerConstraints */
    public function headerPreconditions(array $headerConstraints): Expr|null
    {
        $conditions = [];

        foreach ($headerConstraints as $constraint) {
            $conditions[] = ExpressionBuilder::funcCall('array_key_exists', [
                ExpressionBuilder::literalString($constraint->name),
                'headers',
            ]);

            if (! $constraint->hasValue) {
                continue;
            }

            $conditions[] = ExpressionBuilder::identical(
                ExpressionBuilder::arrayFetch('headers', $constraint->name),
                ExpressionBuilder::literalString($constraint->value),
            );
        }

        if ($conditions === []) {
            return null;
        }

        return ExpressionBuilder::andAll($conditions);
    }

    /** @param list<string> $headerNames */
    public function headerPresencePreconditions(array $headerNames): Expr|null
    {
        $conditions = [];

        foreach ($headerNames as $headerName) {
            $conditions[] = ExpressionBuilder::funcCall('array_key_exists', [
                ExpressionBuilder::literalString($headerName),
                'headers',
            ]);
        }

        if ($conditions === []) {
            return null;
        }

        return ExpressionBuilder::andAll($conditions);
    }

    /** @param list<HeaderConstraint> $headerConstraints */
    public function headerValuePreconditions(array $headerConstraints): Expr|null
    {
        $conditions = [];

        foreach ($headerConstraints as $constraint) {
            if (! $constraint->hasValue) {
                continue;
            }

            $conditions[] = ExpressionBuilder::identical(
                ExpressionBuilder::arrayFetch('headers', $constraint->name),
                ExpressionBuilder::literalString($constraint->value),
            );
        }

        if ($conditions === []) {
            return null;
        }

        return ExpressionBuilder::andAll($conditions);
    }

    /**
     * @param list<list<string>> $assumedEnumPropertyPaths
     * @param list<list<string>> $satisfiedEnumPropertyPaths
     * @param list<string>       $presenceFields
     * @param list<string>       $assumedPresenceFields
     */
    public function bodyPreconditions(
        PayloadVariantDto $variant,
        array $assumedEnumPropertyPaths = [],
        array $satisfiedEnumPropertyPaths = [],
        array $presenceFields = [],
        array $assumedPresenceFields = [],
    ): Expr|null {
        $conditions  = [];
        $addedFields = [];

        if ($variant->discriminatorValue !== '') {
            $conditions[] = ExpressionBuilder::identical(
                ExpressionBuilder::arrayFetch('data', $variant->discriminator->propertyName),
                ExpressionBuilder::literalString($variant->discriminatorValue),
            );
        }

        foreach ($variant->enumFingerprints as $fingerprint) {
            if ($this->isAssumedPresent($fingerprint->propertyPath, $satisfiedEnumPropertyPaths)) {
                continue;
            }

            if ($this->isAssumedPresent($fingerprint->propertyPath, $assumedEnumPropertyPaths)) {
                $conditions[] = ExpressionBuilder::identical(
                    $this->nestedArrayFetch('data', $fingerprint->propertyPath),
                    ExpressionBuilder::literalString($fingerprint->value),
                );
                continue;
            }

            $conditions[] = $this->nestedArrayKeyExists('data', $fingerprint->propertyPath);
            $conditions[] = ExpressionBuilder::identical(
                $this->nestedArrayFetch('data', $fingerprint->propertyPath),
                ExpressionBuilder::literalString($fingerprint->value),
            );
        }

        $enumPropertyPaths = [];
        foreach ($variant->enumFingerprints as $fingerprint) {
            $enumPropertyPaths[$fingerprint->rootProperty()] = true;
        }

        $assumedRequiredFields = [];
        foreach ([...$assumedEnumPropertyPaths, ...$satisfiedEnumPropertyPaths] as $propertyPath) {
            if ($propertyPath === []) {
                continue;
            }

            $assumedRequiredFields[$propertyPath[0]] = true;
        }

        foreach ($variant->requiredFields as $field) {
            if (array_key_exists($field, $enumPropertyPaths)) {
                continue;
            }

            if (array_key_exists($field, $assumedRequiredFields)) {
                continue;
            }

            if (in_array($field, $assumedPresenceFields, true)) {
                continue;
            }

            if (array_key_exists($field, $addedFields)) {
                continue;
            }

            $addedFields[$field] = true;
            $conditions[]        = ExpressionBuilder::funcCall('array_key_exists', [
                ExpressionBuilder::literalString($field),
                'data',
            ]);
        }

        foreach ($presenceFields as $field) {
            if (array_key_exists($field, $enumPropertyPaths)) {
                continue;
            }

            if (array_key_exists($field, $assumedRequiredFields)) {
                continue;
            }

            if (in_array($field, $assumedPresenceFields, true)) {
                continue;
            }

            if (array_key_exists($field, $addedFields)) {
                continue;
            }

            $addedFields[$field] = true;
            $conditions[]        = ExpressionBuilder::funcCall('array_key_exists', [
                ExpressionBuilder::literalString($field),
                'data',
            ]);
        }

        if ($conditions === []) {
            return null;
        }

        return ExpressionBuilder::andAll($conditions);
    }

    /** @param list<string> $fields */
    public function dataPresencePreconditions(array $fields): Expr|null
    {
        $conditions = [];

        foreach ($fields as $field) {
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
     * @param list<string>       $propertyPath
     * @param list<list<string>> $assumedEnumPropertyPaths
     */
    private function isAssumedPresent(array $propertyPath, array $assumedEnumPropertyPaths): bool
    {
        return in_array($propertyPath, $assumedEnumPropertyPaths, true);
    }

    /** @param list<string> $propertyPath */
    public function nestedArrayFetch(string $root, array $propertyPath): Expr
    {
        $expression = ExpressionBuilder::var($root);

        foreach ($propertyPath as $segment) {
            $expression = ExpressionBuilder::arrayFetch($expression, $segment);
        }

        return $expression;
    }

    /** @param list<string> $propertyPath */
    public function nestedArrayKeyExists(string $root, array $propertyPath): Expr
    {
        if ($propertyPath === []) {
            throw new InvalidArgumentException('Property path must not be empty');
        }

        if (count($propertyPath) === 1) {
            return ExpressionBuilder::funcCall('array_key_exists', [
                ExpressionBuilder::literalString($propertyPath[0]),
                ExpressionBuilder::var($root),
            ]);
        }

        $parentPath = array_slice($propertyPath, 0, -1);
        $leaf       = $propertyPath[count($propertyPath) - 1];

        return ExpressionBuilder::andAll([
            $this->nestedArrayKeyExists($root, $parentPath),
            ExpressionBuilder::funcCall('array_key_exists', [
                ExpressionBuilder::literalString($leaf),
                $this->nestedArrayFetch($root, $parentPath),
            ]),
        ]);
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
