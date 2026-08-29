<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

use OpenAPITools\Representation\Namespaced\Schema;

final readonly class PayloadVariant
{
    /**
     * @param list<string>           $requiredFields
     * @param list<HeaderConstraint> $headerConstraints
     */
    public function __construct(
        public Schema $schema,
        public string $contentType,
        public array $requiredFields,
        public array $headerConstraints,
        public Discriminator $discriminator,
        public string $discriminatorValue,
        /** @var list<EnumFingerprint> */
        public array $enumFingerprints,
    ) {
    }
}
