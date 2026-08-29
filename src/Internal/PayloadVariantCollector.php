<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

use cebe\openapi\spec\Schema as BaseSchema;
use OpenAPITools\Representation\Namespaced\Property;
use OpenAPITools\Representation\Namespaced\Schema;
use OpenAPITools\Representation\Namespaced\WebHook;
use OpenAPITools\Representation\Namespaced\WebHookEvent;
use OpenAPITools\Utils\Utils;

use function array_values;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

final class PayloadVariantCollector
{
    /**
     * @param array<Schema> $allSchemas
     *
     * @return list<PayloadVariant>
     */
    public static function fromEvent(WebHookEvent $webHookEvent, array $allSchemas): array
    {
        $variants = [];

        foreach ($webHookEvent->webHooks as $webHook) {
            foreach ($webHook->schema as $contentType => $schema) {
                foreach (self::expandSchemaVariants($schema, $contentType, self::headerConstraints($webHook), $allSchemas) as $variant) {
                    $variants[self::variantKey($variant)] = $variant;
                }
            }
        }

        return array_values($variants);
    }

    private static function variantKey(PayloadVariant $variant): string
    {
        return $variant->schema->className->fullyQualified->source . '|' . $variant->contentType;
    }

    /** @return list<HeaderConstraint> */
    private static function headerConstraints(WebHook $webHook): array
    {
        $constraints = [];

        foreach ($webHook->headers as $header) {
            $value         = self::headerSchemaValue($header->schema->schema);
            $constraints[] = $value !== null
                ? HeaderConstraint::fixed(strtolower($header->name), $value)
                : HeaderConstraint::presence(strtolower($header->name));
        }

        return $constraints;
    }

    private static function headerSchemaValue(BaseSchema $schema): string|null
    {
        /** @phpstan-ignore property.notFound (OpenAPI schema dynamic property) */
        $const = $schema->const ?? null;
        if (is_string($const) || is_int($const)) {
            return (string) $const;
        }

        $enum = $schema->enum ?? [];
        if (count($enum) !== 1) {
            return null;
        }

        $value = $enum[0];
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param array<Schema>          $allSchemas
     * @param list<HeaderConstraint> $headerConstraints
     *
     * @return list<PayloadVariant>
     */
    private static function expandSchemaVariants(Schema $schema, string $contentType, array $headerConstraints, array $allSchemas): array
    {
        $discriminator = self::discriminator($schema->schema, $allSchemas);
        if ($discriminator->propertyName !== '') {
            $variants = [];
            foreach ($discriminator->mapping as $discriminatorValue => $className) {
                $mappedSchema = self::findSchemaByClassName($allSchemas, $className);
                if (! $mappedSchema instanceof Schema) {
                    continue;
                }

                $variants[] = self::payloadVariant(
                    $mappedSchema,
                    $contentType,
                    $headerConstraints,
                    $discriminator,
                    $discriminatorValue,
                );
            }

            if ($variants !== []) {
                return $variants;
            }
        }

        if (count($schema->schema->oneOf ?? []) > 0) {
            $variants = [];
            foreach ($schema->schema->oneOf as $member) {
                if (! ($member instanceof BaseSchema)) {
                    continue;
                }

                $memberSchema = self::findSchemaByBaseSchema($allSchemas, $member);
                if (! $memberSchema instanceof Schema) {
                    continue;
                }

                $variants[] = self::payloadVariant(
                    $memberSchema,
                    $contentType,
                    $headerConstraints,
                    self::discriminator($memberSchema->schema, $allSchemas),
                    '',
                );
            }

            if ($variants !== []) {
                return $variants;
            }
        }

        return [
            self::payloadVariant(
                $schema,
                $contentType,
                $headerConstraints,
                Discriminator::none(),
                '',
            ),
        ];
    }

    /** @param list<HeaderConstraint> $headerConstraints */
    private static function payloadVariant(
        Schema $schema,
        string $contentType,
        array $headerConstraints,
        Discriminator $discriminator,
        string $discriminatorValue,
    ): PayloadVariant {
        return new PayloadVariant(
            $schema,
            $contentType,
            self::requiredFields($schema->schema),
            $headerConstraints,
            $discriminator,
            $discriminatorValue,
            self::enumFingerprints($schema),
        );
    }

    /**
     * @param list<string> $pathPrefix
     *
     * @return list<EnumFingerprint>
     */
    private static function enumFingerprints(Schema $schema, array $pathPrefix = []): array
    {
        $fingerprints = [];
        $required     = self::requiredFields($schema->schema);

        foreach ($schema->properties as $property) {
            $propertyPath = [...$pathPrefix, $property->sourceName];
            $isRequired   = in_array($property->sourceName, $required, true);

            if (count($property->enum) === 1) {
                $value = $property->enum[0];
                if (is_string($value) || is_int($value)) {
                    $fingerprints[] = new EnumFingerprint($propertyPath, (string) $value);
                }
            }

            if (! $isRequired) {
                continue;
            }

            $nestedSchema = self::nestedObjectSchema($property);
            if (! ($nestedSchema instanceof Schema)) {
                continue;
            }

            foreach (self::enumFingerprints($nestedSchema, $propertyPath) as $nestedFingerprint) {
                $fingerprints[] = $nestedFingerprint;
            }
        }

        return $fingerprints;
    }

    private static function nestedObjectSchema(Property $property): Schema|null
    {
        if ($property->type->type !== 'object') {
            return null;
        }

        if ($property->type->payload instanceof Schema) {
            return $property->type->payload;
        }

        return null;
    }

    /** @return list<string> */
    private static function requiredFields(BaseSchema $schema): array
    {
        return array_values($schema->required ?? []);
    }

    /** @param array<Schema> $allSchemas */
    private static function discriminator(BaseSchema $schema, array $allSchemas): Discriminator
    {
        $discriminator = $schema->discriminator;
        if ($discriminator === null || $discriminator->propertyName === '') {
            return Discriminator::none();
        }

        $mapping = [];
        foreach ($discriminator->mapping ?? [] as $value => $ref) {
            $resolved = self::findSchemaByRef($allSchemas, $ref);
            if (! $resolved instanceof Schema) {
                continue;
            }

            $mapping[(string) $value] = $resolved->className->fullyQualified->source;
        }

        if ($mapping === []) {
            return Discriminator::none();
        }

        return new Discriminator($discriminator->propertyName, $mapping);
    }

    /** @param array<Schema> $allSchemas */
    private static function findSchemaByRef(array $allSchemas, string $ref): Schema|null
    {
        if (! str_starts_with($ref, '#/components/schemas/')) {
            return null;
        }

        $suffix = substr($ref, strlen('#/components/schemas/'));

        foreach ($allSchemas as $schema) {
            if (str_ends_with($schema->className->relative, '\\' . Utils::className($suffix))) {
                return $schema;
            }
        }

        return null;
    }

    /** @param array<Schema> $allSchemas */
    private static function findSchemaByClassName(array $allSchemas, string $className): Schema|null
    {
        foreach ($allSchemas as $schema) {
            if ($schema->className->fullyQualified->source === $className) {
                return $schema;
            }
        }

        return null;
    }

    /** @param array<Schema> $allSchemas */
    private static function findSchemaByBaseSchema(array $allSchemas, BaseSchema $member): Schema|null
    {
        $memberData = $member->getSerializableData();

        foreach ($allSchemas as $schema) {
            if ($schema->schema->getSerializableData() === $memberData) {
                return $schema;
            }
        }

        return null;
    }
}
