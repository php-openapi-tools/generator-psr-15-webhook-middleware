<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests;

use OpenAPITools\Utils\File;
use PHPUnit\Framework\Assert;

final class GeneratedFiles
{
    /** @param array<string, File> $files */
    public static function contents(array $files, string $key): string
    {
        $contents = $files[$key]->contents;
        Assert::assertIsString($contents);

        return $contents;
    }
}
