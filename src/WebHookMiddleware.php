<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook;

use OpenAPITools\Contract\FileGenerator;
use OpenAPITools\Contract\Package;
use OpenAPITools\Contract\WebHookHandlerInterface;
use OpenAPITools\Representation;
use OpenAPITools\Utils\ClassString;
use OpenAPITools\Utils\File;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
        $webHooksClassName  = ClassString::factory($package->namespace, 'WebHooks');
        $exceptionClassName = ClassString::factory($package->namespace, 'Internal\\WebHook\\InvalidWebHookRequestException');

        $class = $this->builderFactory
            ->class($className->className)
            ->makeFinal()
            ->makeReadonly()
            ->implement('\\Psr\\Http\\Server\\MiddlewareInterface')
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
                new Stmt\Use_([new Stmt\UseUse(new Name('Psr\\Http\\Server\\MiddlewareInterface'))]),
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
            new Expr\BinaryOp\BooleanAnd(
                new Expr\BinaryOp\NotIdentical(
                    new Expr\PropertyFetch(new Expr\Variable('this'), 'paths'),
                    new Expr\Array_([]),
                ),
                new Expr\BooleanNot(
                    new Expr\FuncCall(new Name('in_array'), [
                        new Arg(new Expr\MethodCall(
                            new Expr\MethodCall(new Expr\Variable('request'), 'getUri'),
                            'getPath',
                        )),
                        new Arg(new Expr\PropertyFetch(new Expr\Variable('this'), 'paths')),
                        new Arg(new Expr\ConstFetch(new Name('true'))),
                    ]),
                ),
            ),
            [
                'stmts' => [
                    new Stmt\Return_(
                        new Expr\MethodCall(
                            new Expr\Variable('handler'),
                            'handle',
                            [new Arg(new Expr\Variable('request'))],
                        ),
                    ),
                ],
            ],
        );
    }

    /** @return list<Stmt> */
    private function buildBodyValidationStatements(): array
    {
        $throwMissingBody = new Stmt\Expression(
            new Expr\Throw_(
                new Expr\New_(
                    new Name('InvalidWebHookRequestException'),
                    [new Arg(new Scalar\String_('Missing webhook request body'))],
                ),
            ),
        );

        $throwInvalidBody = new Stmt\Expression(
            new Expr\Throw_(
                new Expr\New_(
                    new Name('InvalidWebHookRequestException'),
                    [new Arg(new Scalar\String_('Invalid webhook request body'))],
                ),
            ),
        );

        return [
            new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('body'),
                    new Expr\Cast\String_(
                        new Expr\MethodCall(new Expr\Variable('request'), 'getBody'),
                    ),
                ),
            ),
            new Stmt\If_(
                new Expr\BinaryOp\Identical(
                    new Expr\Variable('body'),
                    new Scalar\String_(''),
                ),
                ['stmts' => [$throwMissingBody]],
            ),
            new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('data'),
                    new Expr\FuncCall(new Name('json_decode'), [
                        new Arg(new Expr\Variable('body')),
                        new Arg(new Expr\ConstFetch(new Name('true'))),
                    ]),
                ),
            ),
            new Stmt\If_(
                new Expr\BinaryOp\NotIdentical(
                    new Expr\FuncCall(new Name('is_array'), [new Arg(new Expr\Variable('data'))]),
                    new Expr\ConstFetch(new Name('true')),
                ),
                ['stmts' => [$throwInvalidBody]],
            ),
        ];
    }

    /** @return list<Stmt> */
    private function buildResolveAndHandleStatements(): array
    {
        return [
            new Stmt\Expression(
                new Expr\Assign(
                    new Expr\Variable('headers'),
                    new Expr\Array_([]),
                ),
            ),
            new Stmt\Foreach_(
                new Expr\MethodCall(new Expr\Variable('request'), 'getHeaders'),
                new Expr\Variable('values'),
                [
                    'keyVar' => new Expr\Variable('name'),
                    'stmts' => [
                        new Stmt\Expression(
                            new Expr\Assign(
                                new Expr\ArrayDimFetch(
                                    new Expr\Variable('headers'),
                                    new Expr\FuncCall(new Name('strtolower'), [
                                        new Arg(new Expr\Variable('name')),
                                    ]),
                                ),
                                new Expr\ArrayDimFetch(
                                    new Expr\Variable('values'),
                                    new Scalar\LNumber(0),
                                ),
                            ),
                        ),
                    ],
                ],
            ),
            new Stmt\TryCatch(
                [
                    new Stmt\Expression(
                        new Expr\Assign(
                            new Expr\Variable('payload'),
                            new Expr\MethodCall(
                                new Expr\PropertyFetch(new Expr\Variable('this'), 'webHooks'),
                                'resolve',
                                [
                                    new Arg(new Expr\Variable('headers')),
                                    new Arg(new Expr\Variable('data')),
                                ],
                            ),
                        ),
                    ),
                ],
                [
                    new Stmt\Catch_(
                        [new Name('\\' . Throwable::class)],
                        new Expr\Variable('throwable'),
                        [
                            new Stmt\Expression(
                                new Expr\Throw_(
                                    new Expr\New_(
                                        new Name('InvalidWebHookRequestException'),
                                        [
                                            new Arg(new Scalar\String_('Failed to resolve webhook')),
                                            new Arg(new Scalar\LNumber(0)),
                                            new Arg(new Expr\Variable('throwable')),
                                        ],
                                    ),
                                ),
                            ),
                        ],
                    ),
                ],
            ),
            new Stmt\Return_(
                new Expr\MethodCall(
                    new Expr\PropertyFetch(new Expr\Variable('this'), 'handler'),
                    'handle',
                    [
                        new Arg(new Expr\Variable('payload')),
                    ],
                ),
            ),
        ];
    }

    private function defaultPathsExpression(): Expr\Array_
    {
        return new Expr\Array_(
            array_map(
                static fn (string $path): Expr\ArrayItem => new Expr\ArrayItem(new Scalar\String_($path)),
                $this->defaultPaths,
            ),
        );
    }
}
