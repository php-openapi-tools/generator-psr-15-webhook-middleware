<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

final readonly class HeaderConstraint
{
    public function __construct(
        public string $name,
        public string $value,
        public bool $hasValue,
    ) {
    }

    public static function presence(string $name): self
    {
        return new self($name, '', false);
    }

    public static function fixed(string $name, string $value): self
    {
        return new self($name, $value, true);
    }
}
