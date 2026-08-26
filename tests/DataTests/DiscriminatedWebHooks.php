<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests;

use OpenAPITools\Utils\File;
use PHPUnit\Framework\Assert;

final class DiscriminatedWebHooks implements GeneratedFilesAssertion
{
    /** @param array<string, File> $files */
    public static function assertGeneratedFiles(array $files): void
    {
        Assert::assertArrayHasKey('Internal\WebHook\WebHooks', $files);
        Assert::assertArrayHasKey('Internal\WebHook\Event', $files);

        $webHooks = GeneratedFiles::contents($files, 'Internal\WebHook\WebHooks');
        Assert::assertStringContainsString('detectDiscriminatedEvent', $webHooks);
        Assert::assertStringContainsString('match ($data[\'eventType\'])', $webHooks);
        Assert::assertStringContainsString("'ping'", $webHooks);
        Assert::assertStringContainsString("'push'", $webHooks);
        Assert::assertStringContainsString('match ($this->detectDiscriminatedEvent($data))', $webHooks);
    }
}
