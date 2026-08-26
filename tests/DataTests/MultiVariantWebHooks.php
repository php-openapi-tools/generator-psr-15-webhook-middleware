<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests;

use OpenAPITools\Utils\File;
use PHPUnit\Framework\Assert;

final class MultiVariantWebHooks implements GeneratedFilesAssertion
{
    /** @param array<string, File> $files */
    public static function assertGeneratedFiles(array $files): void
    {
        Assert::assertArrayHasKey('Internal\WebHook\WebHooks', $files);

        $webHooks = GeneratedFiles::contents($files, 'Internal\WebHook\WebHooks');
        Assert::assertStringContainsString('resolveByHeaders', $webHooks);
        Assert::assertStringContainsString('x-notify-variant', $webHooks);
        Assert::assertStringContainsString("'alpha'", $webHooks);
        Assert::assertStringContainsString("'beta'", $webHooks);
        Assert::assertStringContainsString('array_key_exists', $webHooks);
    }
}
