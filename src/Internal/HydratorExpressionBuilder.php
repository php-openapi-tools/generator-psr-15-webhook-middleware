<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

use OpenAPITools\Generator\Utils\Builder\ExpressionBuilder;
use OpenAPITools\Generator\Utils\Builder\HydrationBuilder;
use OpenAPITools\Representation\Namespaced\Property;
use OpenAPITools\Representation\Namespaced\Property\Type;
use OpenAPITools\Representation\Namespaced\Schema;
use PhpParser\BuilderFactory;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use RuntimeException;

use function array_key_exists;
use function is_array;
use function str_replace;

final readonly class HydratorExpressionBuilder
{
    public function __construct(private BuilderFactory $builderFactory)
    {
    }

    public function hydrateExpression(Schema $schema, Expr $dataExpr): New_
    {
        $args = [];
        foreach ($schema->properties as $property) {
            $args[] = new Arg(
                $this->propertyValueExpression($property, ExpressionBuilder::arrayFetch($dataExpr, $property->sourceName)),
                false,
                false,
                [],
                new Identifier($property->name),
            );
        }

        return ExpressionBuilder::newInstance($schema->className->fullyQualified->source, $args);
    }

    private function propertyValueExpression(Property $property, Expr $valueExpr): Expr
    {
        if ($property->nullable) {
            return ExpressionBuilder::nullSafe($valueExpr, $this->nonNullPropertyValueExpression($property, $valueExpr));
        }

        return $this->nonNullPropertyValueExpression($property, $valueExpr);
    }

    private function nonNullPropertyValueExpression(Property $property, Expr $valueExpr): Expr
    {
        return match ($property->type->type) {
            'object' => $this->objectExpression($property->type, $valueExpr),
            'array'  => $this->arrayExpression($property->type, $valueExpr),
            default  => $valueExpr,
        };
    }

    private function objectExpression(Type $type, Expr $valueExpr): Expr\MethodCall
    {
        if (! ($type->payload instanceof Schema)) {
            throw new RuntimeException('Expected schema payload for object property.');
        }

        return HydrationBuilder::hydrateCall($type->payload->className->fullyQualified->source, $valueExpr, ExpressionBuilder::var('this'));
    }

    private function arrayExpression(Type $type, Expr $valueExpr): Expr
    {
        if (! is_array($type->payload) || ! array_key_exists(0, $type->payload)) {
            return $valueExpr;
        }

        $itemType = $type->payload[0];
        if ($itemType->type === 'object' && $itemType->payload instanceof Schema) {
            return ExpressionBuilder::funcCall('array_map', [
                new Expr\Closure([
                    'params' => [$this->builderFactory->param('item')->getNode()],
                    'stmts' => [
                        new Stmt\Return_(
                            HydrationBuilder::hydrateCall($itemType->payload->className->fullyQualified->source, ExpressionBuilder::var('item'), ExpressionBuilder::var('this')),
                        ),
                    ],
                ]),
                $valueExpr,
            ]);
        }

        return $valueExpr;
    }

    public function hydrateMethod(Schema $schema): Stmt\ClassMethod
    {
        $method = $this->builderFactory->method('hydrate' . str_replace('\\', '', $schema->className->relative))
            ->makePrivate()
            ->setReturnType($schema->className->fullyQualified->source)
            ->addParam($this->builderFactory->param('data')->setType('array'));

        $method->addStmt(new Stmt\Return_($this->hydrateExpression($schema, ExpressionBuilder::var('data'))));

        return $method->getNode();
    }
}
