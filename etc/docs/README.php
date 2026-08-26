<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use DTL\Docbot\Article\Article;
use DTL\Docbot\Extension\Core\Block\CreateFileBlock;
use DTL\Docbot\Extension\Core\Block\SectionBlock;
use DTL\Docbot\Extension\Core\Block\TextBlock;
use OpenAPITools\Configuration\Gathering;
use OpenAPITools\Configuration\Package;
use OpenAPITools\Gatherer\Gatherer;
use OpenAPITools\Generator\PSR15\WebHook\WebHookMiddleware;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$exampleInputPath = __DIR__ . '/example-input.yaml';

/** @phpstan-ignore wyrihaximus.reactphp.blocking.function.fileGetContents */
$exampleInput = file_get_contents($exampleInputPath);
if (! is_string($exampleInput)) {
    throw new RuntimeException('Could not read example-input.yaml');
}

$representation = Gatherer::gather(
    Reader::readFromYamlFile($exampleInputPath),
    new Gathering(
        $exampleInputPath,
        null,
        new Gathering\Schemas(
            allowDuplication: true,
            useAliasesForDuplication: true,
        ),
    ),
);

$package = new Package(
    new Package\Metadata('Example', 'Example API client', []),
    'api-clients',
    'example',
    'git@example.com:example.git',
    'v1',
    null,
    new Package\Templates(__DIR__ . '/templates', []),
    new Package\Destination('example', 'src', 'tests'),
    new Namespace_('ApiClients\Client\Example', 'ApiClients\Tests\Client\Example'),
    new Package\QA(
        phpcs: new Package\QA\Tool(false, null),
        phpstan: new Package\QA\Tool(false, null),
        psalm: new Package\QA\Tool(false, null),
    ),
    new Package\State([]),
    [],
);

$namespaced = $representation->namespace($package->namespace);
$printer    = new Standard();
$highlight  = [
    'WebHookMiddleware',
    'Internal\\WebHook\\Event',
    'Internal\\WebHook\\WebHooks',
    'Internal\\WebHook\\Hydrator',
];

/** @var list<SectionBlock> $generatedFileSections */
$generatedFileSections = [];

foreach (new WebHookMiddleware(new BuilderFactory(), ['/webhook'])->generate($package, $namespaced) as $generatedFile) {
    if (! in_array($generatedFile->fqcn, $highlight, true)) {
        continue;
    }

    $contents = $generatedFile->contents;
    if (! $contents instanceof Node) {
        continue;
    }

    $source = '<?php ' . $printer->prettyPrint([
        new Node\Stmt\Declare_([
            new Node\Stmt\DeclareDeclare('strict_types', new Node\Scalar\LNumber(1)),
        ]),
        $contents,
    ]);

    $relativePath = $generatedFile->pathPrefix . '/' . str_replace('\\', '/', $generatedFile->fqcn) . '.php';

    $generatedFileSections[] = new SectionBlock($relativePath, [
        <<<PHP
        ```php
        {$source}
        ```
        PHP,
    ]);
}

return Article::create('../../README', 'generator-psr-15-webhook-middleware', [
    <<<'TEXT'
    [`FileGenerator`](https://github.com/php-openapi-tools/contract) for [OpenAPI Tools](https://github.com/php-openapi-tools) that emits a PSR-15 webhook middleware stack: an enum-backed resolver, custom hydrator, and public middleware. When `entryPoints.webHookMiddleware` is enabled, it replaces the public `WebHooks` entry point and EventSauce webhook hydrators for that mode.

    ![Continuous Integration](https://github.com/php-openapi-tools/generator-psr-15-webhook-middleware/workflows/Continuous%20Integration/badge.svg)
    [![Latest Stable Version](https://poser.pugx.org/openapi-tools/generator-psr-15-webhook-middleware/v/stable.png)](https://packagist.org/packages/openapi-tools/generator-psr-15-webhook-middleware)
    [![Total Downloads](https://poser.pugx.org/openapi-tools/generator-psr-15-webhook-middleware/downloads.png)](https://packagist.org/packages/openapi-tools/generator-psr-15-webhook-middleware/stats)
    [![License](https://poser.pugx.org/openapi-tools/generator-psr-15-webhook-middleware/license.png)](https://packagist.org/packages/openapi-tools/generator-psr-15-webhook-middleware)
    TEXT,
    new SectionBlock('Installation', [
        <<<'TEXT'
        ```
        composer require openapi-tools/generator-psr-15-webhook-middleware
        ```
        TEXT,
    ]),
    new SectionBlock('Where it fits', [
        <<<'TEXT'
        This package runs after [`gatherer`](https://github.com/php-openapi-tools/gatherer) has built a [`representation`](https://github.com/php-openapi-tools/representation) and [`generator-schema`](https://github.com/php-openapi-tools/generator-schema) has emitted payload schema classes. Register `WebHookMiddleware` when middleware mode is enabled; set `includeWebHookHydrators: false` on [`generator-hydrator`](https://github.com/php-openapi-tools/generator-hydrator) so webhook hydration is owned here instead.

        ```mermaid
        flowchart LR
          spec[OpenAPI spec] --> gatherer[Gatherer]
          gatherer --> rep[Representation]
          rep --> schema[Schema generator]
          schema --> wh[WebHook middleware generator]
          wh --> middleware[WebHookMiddleware]
          wh --> resolver[Internal WebHooks + Event enum]
        ```
        TEXT,
    ]),
    new SectionBlock('Example', [
        <<<'TEXT'
        The snippet below is generated by running the webhook middleware generator against a real OpenAPI input when you run `make generate-readme`. The example driver lives in [`etc/docs/README.php`](etc/docs/README.php).

        TEXT,
        new TextBlock(
            '**Input** — OpenAPI webhooks (`%path%`):',
            context: new CreateFileBlock('example-input.yaml', 'yaml', $exampleInput),
        ),
        new SectionBlock('Output', [
            'Running gatherer + `WebHookMiddleware::generate()` against the input above emits these files. The spec defines four webhooks: `healthCheck` and `inventoryUpdate` are resolved by matching headers (and field fingerprints); `petLifecycle` and `storePolicy` use an `Event` enum with discriminator `match` on `eventType` and `action`. All resolution lives in `Internal\\WebHook\\WebHooks`:',
            ...$generatedFileSections,
        ]),
        <<<'TEXT'
        For each payload variant in the spec, the generator emits:

        | Class | Visibility |
        | --- | --- |
        | `WebHookMiddleware` | Public |
        | `Internal\WebHook\WebHooks` | Internal — enum `match` for discriminators, header matching fallback otherwise |
        | `Internal\WebHook\Event` | Internal — one enum case per payload variant |
        | `Internal\WebHook\Hydrator` | Internal |
        | `Internal\WebHook\InvalidWebHookRequestException` | Internal |

        Schema classes (`Schema\Ping`, `Schema\Push`, …) are still emitted by [`generator-schema`](https://github.com/php-openapi-tools/generator-schema).

        ### Resolution order

        For each webhook delivery, generated code resolves the payload in this order:

        1. **Headers** — match declared webhook header parameters (e.g. `X-Event-Type`)
        2. **Discriminator** — when the spec defines `discriminator`, match `$data[$propertyName]`
        3. **Field fingerprint** — required fields must be present in `$data`
        4. **Validation** — `SchemaValidator` against generated `SCHEMA_JSON`
        5. **Hydration** — `Internal\WebHook\Hydrator` maps arrays to readonly schema objects (no EventSauce)

        ### Behaviour

        - **Path filter:** when `$paths` is non-empty, only matching request paths are treated as webhooks; all other requests pass through unchanged.
        - **Empty paths:** when `$paths` is `[]`, every request is treated as a potential webhook (intended for local development and testing).
        - **Strict:** invalid JSON, missing body, or failed resolve throws `InvalidWebHookRequestException`.
        - **Happy path:** on success, returns `$this->handler->handle($payload)` directly.

        ### Usage in `openapi-client-generator`

        ```yaml
        entryPoints:
          webHookMiddleware: true
        ```

        Or with explicit paths (overridable at runtime via the middleware constructor):

        ```yaml
        entryPoints:
          webHookMiddleware:
            paths:
              - /webhook
              - /hooks/github
        ```

        When middleware mode is enabled, `webHooks: true` is implied but the public `WebHooks` / `WebHook` generators are not registered.

        Implement `OpenAPITools\Contract\WebHookHandlerInterface` to handle resolved payloads:

        ```php
        final readonly class MyWebHookHandler implements WebHookHandlerInterface
        {
            public function handle(object $payload): ResponseInterface
            {
                return match ($payload::class) {
                    Ping::class => $this->ping($payload),
                    default => new EmptyResponse(404),
                };
            }
        }
        ```

        Wire the middleware in your HTTP application:

        ```php
        $middleware = new WebHookMiddleware(
            new Internal\WebHook\WebHooks($requestSchemaValidator, new Internal\WebHook\Hydrator()),
            new MyWebHookHandler(),
            paths: ['/webhook'],
        );
        ```

        In a full client package, `WebHookMiddleware` is wired into the generator run loop alongside other `FileGenerator` implementations. A minimal direct invocation looks like this:

        ```php
        use OpenAPITools\Generator\PSR15\WebHook\WebHookMiddleware;
        use PhpParser\BuilderFactory;

        $generator = new WebHookMiddleware(new BuilderFactory(), defaultPaths: ['/webhook']);

        foreach ($generator->generate($package, $representation->namespace($package->namespace)) as $file) {
            // $file->pathPrefix  — e.g. "src"
            // $file->fqcn        — e.g. "WebHookMiddleware"
            // $file->contents    — PhpParser Node
        }
        ```

        See [`openapi-tools/generator`](https://github.com/php-openapi-tools/generator#configuration) for a complete package configuration.
        TEXT,
    ]),
    new SectionBlock('Related packages', [
        <<<'TEXT'
        | Package | Relationship |
        | --- | --- |
        | [`contract`](https://github.com/php-openapi-tools/contract) | `FileGenerator`, `WebHookHandlerInterface`, and `Package` interfaces |
        | [`representation`](https://github.com/php-openapi-tools/representation) | Input model consumed by this generator |
        | [`gatherer`](https://github.com/php-openapi-tools/gatherer) | Builds the representation from OpenAPI |
        | [`generator-schema`](https://github.com/php-openapi-tools/generator-schema) | Emits payload schema classes this generator hydrates |
        | [`generator-hydrator`](https://github.com/php-openapi-tools/generator-hydrator) | Disable webhook hydrators when this package is active |
        | [`generator-utils`](https://github.com/php-openapi-tools/generator-utils) | AST builders used by this generator |
        | [`generator`](https://github.com/php-openapi-tools/generator) | CLI and run loop that orchestrates all generators |
        TEXT,
    ]),
    new SectionBlock('Contributing', ['Please see [CONTRIBUTING](CONTRIBUTING.md) for details.']),
    new SectionBlock('License', [
        <<<'TEXT'
        The MIT License (MIT)

        Copyright (c) 2026 Cees-Jan Kiewiet

        Permission is hereby granted, free of charge, to any person obtaining a copy
        of this software and associated documentation files (the "Software"), to deal
        in the Software without restriction, including without limitation the rights
        to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
        copies of the Software, and to permit persons to whom the Software is
        furnished to do so, subject to the following conditions:

        The above copyright notice and this permission notice shall be included in all
        copies or substantial portions of the Software.

        THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
        IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
        FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
        AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
        LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
        OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
        SOFTWARE.
        TEXT,
    ]),
]);
