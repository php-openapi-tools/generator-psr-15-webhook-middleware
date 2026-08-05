<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests;

use OpenAPITools\Utils\File;

interface GeneratedFilesAssertion
{
    /** @param array<string, File> $files */
    public static function assertGeneratedFiles(array $files): void;
}
