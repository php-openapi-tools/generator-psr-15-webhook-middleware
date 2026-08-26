<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

use function implode;

final readonly class EnumFingerprint
{
    /** @param list<string> $propertyPath */
    public function __construct(
        public array $propertyPath,
        public string $value,
    ) {
    }

    public function rootProperty(): string
    {
        return $this->propertyPath[0];
    }

    public function dottedPath(): string
    {
        return implode('.', $this->propertyPath);
    }
}
