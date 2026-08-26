<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\DataTests;

use OpenAPITools\Utils\File;
use PHPUnit\Framework\Assert;

final class BasicWebHooks implements GeneratedFilesAssertion
{
    /** @param array<string, File> $files */
    public static function assertGeneratedFiles(array $files): void
    {
        Assert::assertCount(5, $files);

        Assert::assertArrayHasKey('WebHookMiddleware', $files);
        Assert::assertArrayHasKey('Internal\WebHook\InvalidWebHookRequestException', $files);
        Assert::assertArrayHasKey('Internal\WebHook\WebHooks', $files);
        Assert::assertArrayHasKey('Internal\WebHook\Hydrator', $files);
        Assert::assertArrayHasKey('Internal\WebHook\Event', $files);

        $middleware = GeneratedFiles::contents($files, 'WebHookMiddleware');

        Assert::assertStringContainsString('final readonly class WebHookMiddleware implements Psr\\Http\\Server\\MiddlewareInterface', $middleware);
        Assert::assertStringContainsString('Internal\\WebHook\\WebHooks $webHooks', $middleware);
        Assert::assertStringContainsString('WebHookHandlerInterface $handler', $middleware);
        Assert::assertStringContainsString("private readonly array \$paths = ['/webhook']", $middleware);
        Assert::assertStringContainsString('Missing webhook request body', $middleware);
        Assert::assertStringContainsString('return $this->handler->handle($payload);', $middleware);

        $webHooks = GeneratedFiles::contents($files, 'Internal\WebHook\WebHooks');
        Assert::assertStringContainsString('final class WebHooks', $webHooks);
        Assert::assertStringContainsString('Internal\\WebHook\\Hydrator $hydrator', $webHooks);
        Assert::assertStringContainsString('resolveByHeaders', $webHooks);
        Assert::assertStringContainsString('x-event-type', $webHooks);
        Assert::assertStringContainsString('array_key_exists', $webHooks);

        $hydrator = GeneratedFiles::contents($files, 'Internal\WebHook\Hydrator');
        Assert::assertStringContainsString('public function hydrate(string $className, array $data): object', $hydrator);
    }
}
