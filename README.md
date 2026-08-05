# generator-psr-15-webhook-middleware

PSR-15 webhook middleware generator for [OpenAPI Tools](https://github.com/openapi-tools).

Generates a `WebHookMiddleware` class for packages that already emit webhook schemas, hydrators, and a `WebHooks` entry point.

## Generated output

| Class | Visibility |
|-------|------------|
| `WebHookMiddleware` | Public |
| `Internal\WebHook\InvalidWebHookRequestException` | Internal |

## Behaviour

- **Path filter:** when `$paths` is non-empty, only matching request paths are treated as webhooks; all other requests pass through unchanged.
- **Empty paths:** when `$paths` is `[]`, every request is treated as a potential webhook (intended for local development and testing).
- **Strict:** invalid JSON, missing body, or failed `WebHooks::resolve()` throws `InvalidWebHookRequestException`.
- **Happy path:** on success, returns `$this->handler->handle($payload)` directly.

## Usage in `openapi-client-generator`

```yaml
entryPoints:
  webHooks: true
  webHookMiddleware: true
```

Or with explicit paths (overridable at runtime via the middleware constructor):

```yaml
entryPoints:
  webHooks: true
  webHookMiddleware:
    paths:
      - /webhook
```

## Generated middleware

```php
final readonly class WebHookMiddleware implements MiddlewareInterface
{
    public function __construct(
        private WebHooks $webHooks,
        private WebHookHandlerInterface $handler,
        private array $paths = ['/webhook'],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // ...
    }
}
```

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

Stack the middleware in your HTTP application:

```php
$middleware = new WebHookMiddleware(
    $client->webHooks(),
    new MyWebHookHandler(),
    paths: ['/webhook'],
);
```

## Registration

Add the generator to your package configuration:

```php
new WebHookMiddlewareGenerator($builderFactory, ['/webhook']),
```

When `$defaultPaths` is `[]`, the generated constructor default is `private array $paths = []`.
