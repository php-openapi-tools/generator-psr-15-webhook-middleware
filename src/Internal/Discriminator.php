<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

final readonly class Discriminator
{
    /** @param array<string, string> $mapping discriminator value => fully-qualified schema class name */
    public function __construct(
        public string $propertyName,
        public array $mapping,
    ) {
    }

    public static function none(): self
    {
        return new self('', []);
    }
}
