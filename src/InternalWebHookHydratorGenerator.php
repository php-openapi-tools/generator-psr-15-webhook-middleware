<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook;

use OpenAPITools\Contract\Package;
use OpenAPITools\Generator\PSR15\WebHook\Internal\HydratorExpressionBuilder;
use OpenAPITools\Generator\Utils\Builder\ExpressionBuilder;
use OpenAPITools\Generator\Utils\Builder\MatchBuilder;
use OpenAPITools\Representation\Namespaced\Schema;
use OpenAPITools\Utils\ClassString;
use OpenAPITools\Utils\File;
use OpenAPITools\Utils\Namespace_;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt;
use RuntimeException;

use function str_replace;

final readonly class InternalWebHookHydratorGenerator
{
    public function __construct(private BuilderFactory $builderFactory)
    {
    }

    /** @param array<Schema> $payloadSchemas */
    public function generate(Package $package, array $payloadSchemas): File
    {
        /** @var Package&object{namespace: Namespace_, destination: object{source: string}} $typedPackage */
        $typedPackage = $package;
        $className    = ClassString::factory($typedPackage->namespace, 'Internal\\WebHook\\Hydrator');
        $builder      = new HydratorExpressionBuilder($this->builderFactory);

        $hydrateMethod = $this->builderFactory->method('hydrate')
            ->makePublic()
            ->setReturnType('object')
            ->addParam($this->builderFactory->param('className')->setType('string'))
            ->addParam($this->builderFactory->param('data')->setType('array'));

        if ($payloadSchemas === []) {
            throw new RuntimeException('Expected at least one webhook payload schema.');
        }

        $matchArms = [];
        foreach ($payloadSchemas as $schema) {
            $methodName  = 'hydrate' . str_replace('\\', '', $schema->className->relative);
            $matchArms[] = [
                'conditions' => [ExpressionBuilder::classConstant($schema->className->fullyQualified->source)],
                'body' => ExpressionBuilder::thisMethod($methodName, ['data']),
            ];
        }

        $hydrateMethod->addStmt(new Stmt\Return_(MatchBuilder::onVariable(
            'className',
            $matchArms,
            new Throw_(ExpressionBuilder::newRuntimeException('Unknown webhook payload class')),
        )));

        $class = $this->builderFactory->class($className->className)
            ->makeFinal()
            ->addStmt($hydrateMethod);

        foreach ($payloadSchemas as $schema) {
            $class->addStmt($builder->hydrateMethod($schema));
        }

        return new File(
            $typedPackage->destination->source,
            $className->relative,
            $this->builderFactory->namespace($className->namespace->source)->addStmt($class->getNode())->getNode(),
            File::DO_LOAD_ON_WRITE,
        );
    }
}
