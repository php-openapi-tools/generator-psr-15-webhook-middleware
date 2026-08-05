<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook;

use OpenAPITools\Contract\FileGenerator;
use OpenAPITools\Contract\Package;
use OpenAPITools\Contract\WebHookHandlerInterface;
use OpenAPITools\Generator\Utils\Builder\ExpressionBuilder;
use OpenAPITools\Generator\Utils\Builder\StatementBuilder;
use OpenAPITools\Representation;
use OpenAPITools\Utils\ClassString;
use OpenAPITools\Utils\File;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_map;

/** @api */
final readonly class WebHookMiddleware implements FileGenerator
{
    private const string WEB_HOOK_HANDLER_INTERFACE = '\\' . WebHookHandlerInterface::class;

    /** @param list<string> $defaultPaths */
    public function __construct(
        private BuilderFactory $builderFactory,
        private array $defaultPaths,
    ) {
    }

    /** @return iterable<File> */
    public function generate(Package $package, Representation\Namespaced\Representation $representation): iterable
    {
        if ($representation->webHooks === []) {
            return;
        }

        /** @var Package&object{namespace: Namespace_, destination: object{source: string}} $typedPackage */
        $typedPackage = $package;

        $payloadSchemas = Internal\ResolveExpressionBuilder::collectPayloadSchemas(
            $representation->schemas,
            $representation->webHooks,
        );

        yield new InternalWebHookHydratorGenerator($this->builderFactory)->generate($typedPackage, $payloadSchemas);

        yield from new InternalWebHooksGenerator(
            $this->builderFactory,
            new Internal\ResolveExpressionBuilder(),
        )->generate(
            $typedPackage,
            $representation->webHooks,
            $representation->schemas,
        );

        yield $this->generateInvalidWebHookRequestException($typedPackage);
        yield $this->generateMiddleware($typedPackage);
    }

    /** @param Package&object{namespace: Namespace_, destination: object{source: string}} $package */
    private function generateInvalidWebHookRequestException(Package $package): File
    {
        $className = ClassString::factory($package->namespace, 'Internal\\WebHook\\InvalidWebHookRequestException');

        $class = $this->builderFactory
            ->class($className->className)
            ->makeFinal()
            ->extend('\\RuntimeException')
            ->getNode();

        return new File(
            $package->destination->source,
            $className->relative,
            $this->builderFactory->namespace($className->namespace->source)->addStmt($class)->getNode(),
            File::DO_LOAD_ON_WRITE,
        );
    }

    /** @param Package&object{namespace: Namespace_, destination: object{source: string}} $package */
    private function generateMiddleware(Package $package): File
    {
        $className          = ClassString::factory($package->namespace, 'WebHookMiddleware');
        $webHooksClassName  = ClassString::factory($package->namespace, 'Internal\\WebHook\\WebHooks');
        $exceptionClassName = ClassString::factory($package->namespace, 'Internal\\WebHook\\InvalidWebHookRequestException');

        $class = $this->builderFactory
            ->class($className->className)
            ->makeFinal()
            ->makeReadonly()
            ->implement(MiddlewareInterface::class)
            ->addStmt(
                $this->builderFactory->method('__construct')->makePublic()->addParams([
                    $this->builderFactory->param('webHooks')
                        ->setType($webHooksClassName->fullyQualified->source)
                        ->makePrivate()
                        ->makeReadonly(),
                    $this->builderFactory->param('handler')
                        ->setType(self::WEB_HOOK_HANDLER_INTERFACE)
                        ->makePrivate()
                        ->makeReadonly(),
                    $this->builderFactory->param('paths')
                        ->setType('array')
                        ->setDefault($this->defaultPathsExpression())
                        ->makePrivate()
                        ->makeReadonly(),
                ]),
            )
            ->addStmt($this->buildProcessMethod());

        return new File(
            $package->destination->source,
            $className->relative,
            $this->builderFactory->namespace($className->namespace->source)->addStmts([
                new Stmt\Use_([new Stmt\UseUse(new Name(WebHookHandlerInterface::class))]),
                new Stmt\Use_([new Stmt\UseUse(new Name($exceptionClassName->fullyQualified->source, ['alias' => 'InvalidWebHookRequestException']))]),
                new Stmt\Use_([new Stmt\UseUse(new Name($webHooksClassName->fullyQualified->source, ['alias' => 'WebHooks']))]),
                new Stmt\Use_([new Stmt\UseUse(new Name(ResponseInterface::class))]),
                new Stmt\Use_([new Stmt\UseUse(new Name(ServerRequestInterface::class))]),
                new Stmt\Use_([new Stmt\UseUse(new Name(MiddlewareInterface::class))]),
                new Stmt\Use_([new Stmt\UseUse(new Name(RequestHandlerInterface::class))]),
                $class->getNode(),
            ])->getNode(),
            File::DO_LOAD_ON_WRITE,
        );
    }

    private function buildProcessMethod(): Stmt\ClassMethod
    {
        $method = $this->builderFactory->method('process')
            ->makePublic()
            ->setReturnType('ResponseInterface')
            ->addParams([
                $this->builderFactory->param('request')->setType('ServerRequestInterface'),
                $this->builderFactory->param('handler')->setType('RequestHandlerInterface'),
            ]);

        $method->addStmt($this->buildPathGuardStatement());
        foreach ($this->buildBodyValidationStatements() as $statement) {
            $method->addStmt($statement);
        }

        foreach ($this->buildResolveAndHandleStatements() as $statement) {
            $method->addStmt($statement);
        }

        return $method->getNode();
    }

    private function buildPathGuardStatement(): Stmt\If_
    {
        return new Stmt\If_(
            ExpressionBuilder::andAll([
                new Expr\BinaryOp\NotIdentical(
                    ExpressionBuilder::thisProperty('paths'),
                    new Expr\Array_([]),
                ),
                new Expr\BooleanNot(
                    ExpressionBuilder::funcCall('in_array', [
                        ExpressionBuilder::methodCall(
                            ExpressionBuilder::methodCall(ExpressionBuilder::var('request'), 'getUri'),
                            'getPath',
                        ),
                        ExpressionBuilder::thisProperty('paths'),
                        ExpressionBuilder::true(),
                    ]),
                ),
            ]),
            [
                'stmts' => [
                    StatementBuilder::returnMethodCall('handler', 'handle', ['request']),
                ],
            ],
        );
    }

    /** @return list<Stmt> */
    private function buildBodyValidationStatements(): array
    {
        return [
            StatementBuilder::assign(
                'body',
                new Expr\Cast\String_(ExpressionBuilder::methodCall(ExpressionBuilder::var('request'), 'getBody')),
            ),
            new Stmt\If_(
                ExpressionBuilder::identical(ExpressionBuilder::var('body'), ExpressionBuilder::literalString('')),
                ['stmts' => [ExpressionBuilder::throwNew('InvalidWebHookRequestException', [ExpressionBuilder::literalString('Missing webhook request body')])]],
            ),
            StatementBuilder::assign(
                'data',
                ExpressionBuilder::funcCall('json_decode', ['body', ExpressionBuilder::true()]),
            ),
            new Stmt\If_(
                new Expr\BinaryOp\NotIdentical(
                    ExpressionBuilder::funcCall('is_array', ['data']),
                    ExpressionBuilder::true(),
                ),
                ['stmts' => [ExpressionBuilder::throwNew('InvalidWebHookRequestException', [ExpressionBuilder::literalString('Invalid webhook request body')])]],
            ),
        ];
    }

    /** @return list<Stmt> */
    private function buildResolveAndHandleStatements(): array
    {
        return [
            StatementBuilder::assign('headers', new Expr\Array_([])),
            new Stmt\Foreach_(
                ExpressionBuilder::methodCall(ExpressionBuilder::var('request'), 'getHeaders'),
                ExpressionBuilder::var('values'),
                [
                    'keyVar' => ExpressionBuilder::var('name'),
                    'stmts' => [
                        StatementBuilder::assign(
                            ExpressionBuilder::arrayFetchKey(
                                'headers',
                                ExpressionBuilder::funcCall('strtolower', ['name']),
                            ),
                            ExpressionBuilder::arrayFetchKey('values', ExpressionBuilder::literalInt(0)),
                        ),
                    ],
                ],
            ),
            new Stmt\TryCatch(
                [
                    StatementBuilder::assign(
                        'payload',
                        ExpressionBuilder::methodCall(
                            ExpressionBuilder::thisProperty('webHooks'),
                            'resolve',
                            ['headers', 'data'],
                        ),
                    ),
                ],
                [
                    new Stmt\Catch_(
                        [new Name('\\' . Throwable::class)],
                        ExpressionBuilder::var('throwable'),
                        [
                            ExpressionBuilder::throwNew('InvalidWebHookRequestException', [
                                ExpressionBuilder::literalString('Failed to resolve webhook'),
                                ExpressionBuilder::literalInt(0),
                                'throwable',
                            ]),
                        ],
                    ),
                ],
            ),
            new Stmt\Return_(
                ExpressionBuilder::methodCall(
                    ExpressionBuilder::thisProperty('handler'),
                    'handle',
                    ['payload'],
                ),
            ),
        ];
    }

    private function defaultPathsExpression(): Expr\Array_
    {
        return new Expr\Array_(
            array_map(
                static fn (string $path): Expr\ArrayItem => new Expr\ArrayItem(ExpressionBuilder::literalString($path)),
                $this->defaultPaths,
            ),
        );
    }
}
