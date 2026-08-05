<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook;

use League\OpenAPIValidation\Schema\SchemaValidator;
use OpenAPITools\Contract\Package;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariant;
use OpenAPITools\Generator\PSR15\WebHook\Internal\PayloadVariantCollector;
use OpenAPITools\Generator\PSR15\WebHook\Internal\ResolveExpressionBuilder;
use OpenAPITools\Generator\Utils\Builder\ExpressionBuilder;
use OpenAPITools\Generator\Utils\Builder\HydrationBuilder;
use OpenAPITools\Generator\Utils\Builder\StatementBuilder;
use OpenAPITools\Representation\Namespaced\Schema;
use OpenAPITools\Representation\Namespaced\WebHookEvent;
use OpenAPITools\Utils\ClassString;
use OpenAPITools\Utils\File;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt;
use PhpParser\Node\UnionType;
use RuntimeException;

use function array_filter;
use function array_values;
use function in_array;
use function ucfirst;

final readonly class InternalWebHooksGenerator
{
    private const string EVENT_ENUM = 'Event';

    public function __construct(
        private BuilderFactory $builderFactory,
        private ResolveExpressionBuilder $resolveExpressionBuilder,
    ) {
    }

    /**
     * @param iterable<WebHookEvent> $webHookEvents
     * @param array<Schema>          $allSchemas
     *
     * @return iterable<File>
     */
    public function generate(Package $package, iterable $webHookEvents, array $allSchemas): iterable
    {
        /** @var Package&object{namespace: Namespace_, destination: object{source: string}} $typedPackage */
        $typedPackage = $package;
        $variants     = $this->collectVariants($webHookEvents, $allSchemas);

        if ($variants === []) {
            throw new RuntimeException('Expected at least one webhook payload variant');
        }

        yield $this->generateEventEnum($typedPackage, $variants);
        yield $this->generateWebHooksClass($typedPackage, $variants);
    }

    /**
     * @param iterable<WebHookEvent> $webHookEvents
     * @param array<Schema>          $allSchemas
     *
     * @return list<array{variant: PayloadVariant, enumCase: string}>
     */
    private function collectVariants(iterable $webHookEvents, array $allSchemas): array
    {
        $variants  = [];
        $enumCases = [];

        foreach ($webHookEvents as $webHookEvent) {
            foreach (PayloadVariantCollector::fromEvent($webHookEvent, $allSchemas) as $variant) {
                $enumCase    = $this->enumCaseName($variant, $enumCases);
                $enumCases[] = $enumCase;
                $variants[]  = [
                    'variant'  => $variant,
                    'enumCase' => $enumCase,
                ];
            }
        }

        return $variants;
    }

    /** @param list<string> $usedEnumCases */
    private function enumCaseName(PayloadVariant $variant, array $usedEnumCases): string
    {
        $baseName = $variant->schema->className->className;

        if (! in_array($baseName, $usedEnumCases, true)) {
            return $baseName;
        }

        if ($variant->discriminatorValue !== '') {
            $candidate = $baseName . ucfirst($variant->discriminatorValue);
            if (! in_array($candidate, $usedEnumCases, true)) {
                return $candidate;
            }
        }

        $suffix = 2;
        do {
            $candidate = $baseName . (string) $suffix;
            ++$suffix;
        } while (in_array($candidate, $usedEnumCases, true));

        return $candidate;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<array{variant: PayloadVariant, enumCase: string}>
     */
    private function discriminatedVariants(array $variants): array
    {
        return array_values(array_filter(
            $variants,
            static fn (array $variant): bool => $variant['variant']->discriminatorValue !== '',
        ));
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<array{variant: PayloadVariant, enumCase: string}>
     */
    private function headerResolvedVariants(array $variants): array
    {
        return array_values(array_filter(
            $variants,
            static fn (array $variant): bool => $variant['variant']->discriminatorValue === '',
        ));
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return array<string, list<array{variant: PayloadVariant, enumCase: string}>>
     */
    private function discriminatedGroups(array $variants): array
    {
        $groups = [];

        foreach ($this->discriminatedVariants($variants) as $variant) {
            $propertyName            = $variant['variant']->discriminator->propertyName;
            $groups[$propertyName][] = $variant;
        }

        return $groups;
    }

    /**
     * @param Package&object{namespace: Namespace_, destination: object{source: string}} $package
     * @param list<array{variant: PayloadVariant, enumCase: string}>                     $variants
     */
    private function generateEventEnum(Package $package, array $variants): File
    {
        $className = ClassString::factory($package->namespace, 'Internal\\WebHook\\' . self::EVENT_ENUM);
        $enum      = $this->builderFactory->enum($className->className);

        foreach ($variants as $variant) {
            $enum->addStmt($this->builderFactory->enumCase($variant['enumCase'])->getNode());
        }

        return new File(
            $package->destination->source,
            $className->relative,
            $this->builderFactory->namespace($className->namespace->source)->addStmt($enum->getNode())->getNode(),
            File::DO_LOAD_ON_WRITE,
        );
    }

    /**
     * @param Package&object{namespace: Namespace_, destination: object{source: string}} $package
     * @param list<array{variant: PayloadVariant, enumCase: string}>                     $variants
     */
    private function generateWebHooksClass(Package $package, array $variants): File
    {
        $className     = ClassString::factory($package->namespace, 'Internal\\WebHook\\WebHooks');
        $hydratorClass = ClassString::factory($package->namespace, 'Internal\\WebHook\\Hydrator');

        $class = $this->builderFactory->class($className->className)
            ->makeFinal()
            ->addStmt(
                $this->builderFactory->method('__construct')->makePublic()->addParams([
                    $this->builderFactory->param('requestSchemaValidator')->makePrivate()->makeReadonly()->setType('\\' . SchemaValidator::class),
                    $this->builderFactory->param('hydrator')->makePrivate()->makeReadonly()->setType($hydratorClass->fullyQualified->source),
                ]),
            )
            ->addStmt($this->buildResolveMethod($variants));

        if ($this->discriminatedVariants($variants) !== []) {
            $class->addStmt($this->buildDetectDiscriminatedEventMethod($variants));
        }

        if ($this->headerResolvedVariants($variants) !== []) {
            $class->addStmt($this->buildResolveByHeadersMethod($variants));
        }

        $class->addStmt($this->buildValidatedHydrateMethod());

        return new File(
            $package->destination->source,
            $className->relative,
            $this->builderFactory->namespace($className->namespace->source)->addStmt($class->getNode())->getNode(),
            File::DO_LOAD_ON_WRITE,
        );
    }

    /** @param list<array{variant: PayloadVariant, enumCase: string}> $variants */
    private function buildResolveMethod(array $variants): Stmt\ClassMethod
    {
        $resolve = $this->builderFactory->method('resolve')
            ->makePublic()
            ->setReturnType('object')
            ->addParams([
                $this->builderFactory->param('headers')->setType('array'),
                $this->builderFactory->param('data')->setType('array'),
            ]);

        $resolve->addStmt($this->normalizeHeadersStatement());

        $discriminatedVariants = $this->discriminatedVariants($variants);
        $headerVariants        = $this->headerResolvedVariants($variants);

        if ($discriminatedVariants !== [] && $headerVariants !== []) {
            $resolve->addStmt(
                new Stmt\Return_(
                    $this->discriminatedMatchExpression(
                        ExpressionBuilder::thisMethod('detectDiscriminatedEvent', ['data']),
                        $discriminatedVariants,
                        ExpressionBuilder::thisMethod('resolveByHeaders', ['headers', 'data']),
                    ),
                ),
            );
        } elseif ($discriminatedVariants !== []) {
            $resolve->addStmt(
                new Stmt\Return_(
                    $this->discriminatedMatchExpression(
                        ExpressionBuilder::thisMethod('detectDiscriminatedEvent', ['data']),
                        $discriminatedVariants,
                        new Expr\Throw_(
                            ExpressionBuilder::newInstance(
                                '\\' . RuntimeException::class,
                                [ExpressionBuilder::literalString('No webhook matching given headers and data')],
                            ),
                        ),
                    ),
                ),
            );
        } else {
            $resolve->addStmt(
                new Stmt\Return_(ExpressionBuilder::thisMethod('resolveByHeaders', ['headers', 'data'])),
            );
        }

        $returnTypes = [];
        foreach ($variants as $variant) {
            $returnTypes[$variant['variant']->schema->className->fullyQualified->source] = new Name(
                $variant['variant']->schema->className->fullyQualified->source,
            );
        }

        $resolve->setReturnType(new UnionType(array_values($returnTypes)));

        return $resolve->getNode();
    }

    /** @param list<array{variant: PayloadVariant, enumCase: string}> $discriminatedVariants */
    private function discriminatedMatchExpression(Expr $discriminated, array $discriminatedVariants, Expr $defaultArm): Expr\Match_
    {
        $matchArms = [];
        foreach ($discriminatedVariants as $variant) {
            $matchArms[] = new MatchArm(
                [$this->enumCaseConstFetch($variant['enumCase'])],
                ExpressionBuilder::thisMethod('validatedHydrate', [
                    ExpressionBuilder::classConstant($variant['variant']->schema->className->fullyQualified->source),
                    'data',
                ]),
            );
        }

        $matchArms[] = new MatchArm(null, $defaultArm);

        return new Expr\Match_($discriminated, $matchArms);
    }

    /** @param list<array{variant: PayloadVariant, enumCase: string}> $variants */
    private function buildDetectDiscriminatedEventMethod(array $variants): Stmt\ClassMethod
    {
        $detect = $this->builderFactory->method('detectDiscriminatedEvent')
            ->makePrivate()
            ->setReturnType(new NullableType(new Name(self::EVENT_ENUM)))
            ->addParams([
                $this->builderFactory->param('data')->setType('array'),
            ]);

        foreach ($this->discriminatedGroups($variants) as $propertyName => $groupVariants) {
            $discriminatorMatchArms = [];
            foreach ($groupVariants as $variant) {
                $discriminatorMatchArms[] = new MatchArm(
                    [ExpressionBuilder::literalString($variant['variant']->discriminatorValue)],
                    $this->enumCaseConstFetch($variant['enumCase']),
                );
            }

            $discriminatorMatchArms[] = new MatchArm(null, ExpressionBuilder::null());

            $detect->addStmt(
                new Stmt\If_(
                    ExpressionBuilder::funcCall('array_key_exists', [
                        ExpressionBuilder::literalString($propertyName),
                        'data',
                    ]),
                    [
                        'stmts' => [
                            StatementBuilder::assign(
                                'event',
                                new Expr\Match_(
                                    ExpressionBuilder::arrayFetch('data', $propertyName),
                                    $discriminatorMatchArms,
                                ),
                            ),
                            new Stmt\If_(
                                new Expr\Instanceof_(ExpressionBuilder::var('event'), new Name(self::EVENT_ENUM)),
                                [
                                    'stmts' => [
                                        new Stmt\Return_(ExpressionBuilder::var('event')),
                                    ],
                                ],
                            ),
                        ],
                    ],
                ),
            );
        }

        $detect->addStmt(new Stmt\Return_(ExpressionBuilder::null()));

        return $detect->getNode();
    }

    /** @param list<array{variant: PayloadVariant, enumCase: string}> $variants */
    private function buildResolveByHeadersMethod(array $variants): Stmt\ClassMethod
    {
        $method = $this->builderFactory->method('resolveByHeaders')
            ->makePrivate()
            ->setReturnType('object')
            ->addParams([
                $this->builderFactory->param('headers')->setType('array'),
                $this->builderFactory->param('data')->setType('array'),
            ]);

        $method->addStmt(
            StatementBuilder::assign(
                'error',
                ExpressionBuilder::newRuntimeException('No webhook matching given headers and data'),
            ),
        );

        foreach ($this->headerResolvedVariants($variants) as $variant) {
            $preconditions = $this->resolveExpressionBuilder->preconditions($variant['variant']);
            $schemaClass   = $variant['variant']->schema->className->fullyQualified->source;

            $tryBody = $preconditions instanceof Expr
                ? [
                    new Stmt\If_($preconditions, [
                        'stmts' => [
                            new Stmt\Return_(
                                ExpressionBuilder::thisMethod('validatedHydrate', [
                                    ExpressionBuilder::classConstant($schemaClass),
                                    'data',
                                ]),
                            ),
                        ],
                    ]),
                ]
                : [
                    new Stmt\Return_(
                        ExpressionBuilder::thisMethod('validatedHydrate', [
                            ExpressionBuilder::classConstant($schemaClass),
                            'data',
                        ]),
                    ),
                ];

            $method->addStmt(
                new Stmt\TryCatch(
                    $tryBody,
                    [
                        ExpressionBuilder::catchThrowable('throwable', 'error'),
                    ],
                ),
            );
        }

        $method->addStmt(StatementBuilder::throwVariable('error'));

        $returnTypes = [];
        foreach ($this->headerResolvedVariants($variants) as $variant) {
            $returnTypes[$variant['variant']->schema->className->fullyQualified->source] = new Name(
                $variant['variant']->schema->className->fullyQualified->source,
            );
        }

        $method->setReturnType(new UnionType(array_values($returnTypes)));

        return $method->getNode();
    }

    private function buildValidatedHydrateMethod(): Stmt\ClassMethod
    {
        $method = $this->builderFactory->method('validatedHydrate')
            ->makePrivate()
            ->setReturnType('object')
            ->addParams([
                $this->builderFactory->param('className')->setType('string'),
                $this->builderFactory->param('data')->setType('array'),
            ]);

        $method->addStmt(
            new Stmt\Expression(
                ExpressionBuilder::methodCall(
                    ExpressionBuilder::thisProperty('requestSchemaValidator'),
                    'validate',
                    [
                        'data',
                        new Expr\StaticCall(new Name('\\cebe\\openapi\\Reader'), 'readFromJson', [
                            new Arg(ExpressionBuilder::objectClassConstant('className', 'SCHEMA_JSON')),
                            new Arg(ExpressionBuilder::classConstant('\\cebe\\openapi\\spec\\Schema')),
                        ]),
                    ],
                ),
            ),
        );

        $method->addStmt(
            new Stmt\Return_(
                HydrationBuilder::hydrateCall(
                    'className',
                    ExpressionBuilder::var('data'),
                    ExpressionBuilder::thisProperty('hydrator'),
                ),
            ),
        );

        return $method->getNode();
    }

    private function normalizeHeadersStatement(): Stmt\Expression
    {
        return StatementBuilder::assign(
            'headers',
            ExpressionBuilder::invoke(
                new Expr\Closure([
                    'static' => true,
                    'params' => [$this->builderFactory->param('headers')->setType('array')->getNode()],
                    'returnType' => new Identifier('array'),
                    'stmts' => [
                        StatementBuilder::assign('loweredHeaders', new Expr\Array_([])),
                        new Stmt\Foreach_(
                            ExpressionBuilder::var('headers'),
                            ExpressionBuilder::var('value'),
                            [
                                'keyVar' => ExpressionBuilder::var('key'),
                                'stmts' => [
                                    StatementBuilder::assign(
                                        ExpressionBuilder::arrayFetchKey(
                                            'loweredHeaders',
                                            ExpressionBuilder::funcCall('strtolower', ['key']),
                                        ),
                                        ExpressionBuilder::var('value'),
                                    ),
                                ],
                            ],
                        ),
                        new Stmt\Return_(ExpressionBuilder::var('loweredHeaders')),
                    ],
                ]),
                ['headers'],
            ),
        );
    }

    private function enumCaseConstFetch(string $enumCase): Expr\ClassConstFetch
    {
        return new Expr\ClassConstFetch(new Name(self::EVENT_ENUM), $enumCase);
    }
}
