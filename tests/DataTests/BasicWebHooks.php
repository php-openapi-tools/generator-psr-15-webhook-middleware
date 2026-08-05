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
        Assert::assertCount(2, $files);

        Assert::assertArrayHasKey('WebHookMiddleware', $files);
        Assert::assertArrayHasKey('Internal\WebHook\InvalidWebHookRequestException', $files);

        $middleware = GeneratedFiles::contents($files, 'WebHookMiddleware');

        Assert::assertStringContainsString('final readonly class WebHookMiddleware implements \\Psr\\Http\\Server\\MiddlewareInterface', $middleware);
        Assert::assertStringContainsString('OpenAPITools\Contract\WebHookHandlerInterface $handler', $middleware);
        Assert::assertStringContainsString('WebHooks $webHooks', $middleware);
        Assert::assertStringContainsString("private readonly array \$paths = ['/webhook']", $middleware);
        Assert::assertStringContainsString('$this->paths !== []', $middleware);
        Assert::assertStringContainsString('in_array($request->getUri()->getPath(), $this->paths, true)', $middleware);
        Assert::assertStringContainsString('Missing webhook request body', $middleware);
        Assert::assertStringContainsString('Invalid webhook request body', $middleware);
        Assert::assertStringContainsString('Failed to resolve webhook', $middleware);
        Assert::assertStringContainsString('return $this->handler->handle($payload);', $middleware);

        $exception = GeneratedFiles::contents($files, 'Internal\WebHook\InvalidWebHookRequestException');

        Assert::assertStringContainsString('final class InvalidWebHookRequestException extends \RuntimeException', $exception);
    }
}
