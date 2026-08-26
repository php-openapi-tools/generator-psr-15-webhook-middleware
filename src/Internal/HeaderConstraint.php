<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

final readonly class HeaderConstraint
{
    public function __construct(
        public string $name,
        public string $expectedValue,
    ) {
    }
}
