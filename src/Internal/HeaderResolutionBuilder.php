<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

use OpenAPITools\Generator\Utils\Builder\ExpressionBuilder;
use OpenAPITools\Generator\Utils\Builder\StatementBuilder;
use PhpParser\Node\Expr;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Stmt;

use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function max;
use function sort;
use function usort;

final readonly class HeaderResolutionBuilder
{
    public function __construct(
        private ResolveExpressionBuilder $resolveExpressionBuilder,
    ) {
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $headerResolvedVariants
     *
     * @return list<Stmt>
     */
    public function buildStatements(array $headerResolvedVariants): array
    {
        $statements = [];

        foreach ($this->groupByContentType($headerResolvedVariants) as $group) {
            foreach ($this->buildContentTypeGroupStatements($group) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return array<string, list<array{variant: PayloadVariant, enumCase: string}>>
     */
    private function groupByContentType(array $variants): array
    {
        $groups = [];

        foreach ($variants as $variant) {
            $groups[$variant['variant']->contentType][] = $variant;
        }

        return $groups;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<Stmt>
     */
    private function buildContentTypeGroupStatements(array $variants): array
    {
        $statements = [];

        foreach ($this->groupPartitionsByHeaderNames($this->partitionByHeaderSignature($variants)) as $partitions) {
            $statements = array_merge($statements, $this->buildHeaderPartitionGroupStatements($partitions));
        }

        return $this->wrapTryCatch($statements);
    }

    /**
     * @param list<list<array{variant: PayloadVariant, enumCase: string}>> $partitions
     *
     * @return list<Stmt>
     */
    private function buildHeaderPartitionGroupStatements(array $partitions): array
    {
        if ($partitions === []) {
            return [];
        }

        return $this->nestSortedHeaders($this->headerNamesSortedByUsage($partitions), $partitions);
    }

    /**
     * @param list<string>                                                 $sortedHeaderNames
     * @param list<list<array{variant: PayloadVariant, enumCase: string}>> $partitions
     *
     * @return list<Stmt>
     */
    private function nestSortedHeaders(array $sortedHeaderNames, array $partitions): array
    {
        if ($sortedHeaderNames === []) {
            return $this->buildInnermostPartitionStatements($partitions);
        }

        $foldedHeaderNames = $this->collectFoldableHeaderNames($sortedHeaderNames, $partitions);
        if ($foldedHeaderNames !== []) {
            $remainingHeaderNames = array_slice($sortedHeaderNames, count($foldedHeaderNames));
            $inner                = $this->nestSortedHeaders($remainingHeaderNames, $partitions);
            if ($inner === []) {
                return [];
            }

            $precondition = $this->resolveExpressionBuilder->headerPresencePreconditions($foldedHeaderNames);
            if (! $precondition instanceof Expr) {
                return $inner;
            }

            return [
                new Stmt\If_($precondition, ['stmts' => $inner]),
            ];
        }

        $currentHeader                           = $sortedHeaderNames[0];
        $remainingHeaders                        = array_slice($sortedHeaderNames, 1);
        [$valuePartitions, $remainingPartitions] = $this->splitPartitionsByHeaderValue($partitions, $currentHeader);

        $inner = [];

        if ($valuePartitions !== []) {
            $inner = array_merge($inner, $this->buildHeaderValueMatchStatements($currentHeader, $valuePartitions));
        }

        if ($remainingPartitions !== []) {
            $inner = array_merge($inner, $this->nestSortedHeaders($remainingHeaders, $remainingPartitions));
        }

        if ($inner === []) {
            return [];
        }

        return [
            new Stmt\If_(
                ExpressionBuilder::funcCall('array_key_exists', [
                    ExpressionBuilder::literalString($currentHeader),
                    'headers',
                ]),
                ['stmts' => $inner],
            ),
        ];
    }

    /**
     * @param list<string>                                                 $sortedHeaderNames
     * @param list<list<array{variant: PayloadVariant, enumCase: string}>> $partitions
     *
     * @return list<string>
     */
    private function collectFoldableHeaderNames(array $sortedHeaderNames, array $partitions): array
    {
        $foldedHeaderNames = [];

        foreach ($sortedHeaderNames as $headerName) {
            [$valuePartitions] = $this->splitPartitionsByHeaderValue($partitions, $headerName);
            if ($valuePartitions !== []) {
                break;
            }

            $foldedHeaderNames[] = $headerName;
        }

        return $foldedHeaderNames;
    }

    /**
     * @param list<list<array{variant: PayloadVariant, enumCase: string}>> $partitions
     *
     * @return list<Stmt>
     */
    private function buildInnermostPartitionStatements(array $partitions): array
    {
        $inner = [];

        foreach ($partitions as $partition) {
            $bodyStatements = $this->buildGroupedStatements($partition, skipHeaderChecks: true);
            if ($bodyStatements === []) {
                continue;
            }

            $headerValuePreconditions = $this->resolveExpressionBuilder->headerValuePreconditions(
                $partition[0]['variant']->headerConstraints,
            );

            if ($headerValuePreconditions instanceof Expr) {
                $inner[] = new Stmt\If_($headerValuePreconditions, ['stmts' => $bodyStatements]);
                continue;
            }

            $inner = array_merge($inner, $bodyStatements);
        }

        return $inner;
    }

    /**
     * @param list<list<array{variant: PayloadVariant, enumCase: string}>> $partitions
     *
     * @return array{
     *     0: array<string, list<array{variant: PayloadVariant, enumCase: string}>>,
     *     1: list<list<array{variant: PayloadVariant, enumCase: string}>>
     * }
     */
    private function splitPartitionsByHeaderValue(array $partitions, string $headerName): array
    {
        $valuePartitions = [];
        $remaining       = [];

        foreach ($partitions as $partition) {
            $value = $this->headerConstraintValue($partition[0]['variant']->headerConstraints, $headerName);
            if ($value !== null && count($partition) === 1) {
                /** @phpstan-ignore cast.useless (normalize numeric header values for match arms) */
                $valuePartitions[(string) $value] = $partition;
                continue;
            }

            $remaining[] = $partition;
        }

        return [$valuePartitions, $remaining];
    }

    /**
     * @param list<list<array{variant: PayloadVariant, enumCase: string}>> $partitions
     *
     * @return list<string>
     */
    private function headerNamesSortedByUsage(array $partitions): array
    {
        $usageCounts          = [];
        $valuePartitionCounts = [];

        foreach ($partitions as $partition) {
            foreach ($partition as $variant) {
                foreach ($variant['variant']->headerConstraints as $constraint) {
                    $usageCounts[$constraint->name] = ($usageCounts[$constraint->name] ?? 0) + 1;
                }
            }

            if (count($partition) !== 1) {
                continue;
            }

            foreach ($partition[0]['variant']->headerConstraints as $constraint) {
                if (! $constraint->hasValue) {
                    continue;
                }

                $valuePartitionCounts[$constraint->name] = ($valuePartitionCounts[$constraint->name] ?? 0) + 1;
            }
        }

        $headerNames = array_keys($usageCounts);

        usort(
            $headerNames,
            static function (string $left, string $right) use ($usageCounts, $valuePartitionCounts): int {
                $valuePartitionCountLeft  = $valuePartitionCounts[$left] ?? 0;
                $valuePartitionCountRight = $valuePartitionCounts[$right] ?? 0;
                if ($valuePartitionCountLeft !== $valuePartitionCountRight) {
                    return $valuePartitionCountRight <=> $valuePartitionCountLeft;
                }

                $usageCountLeft  = $usageCounts[$left];
                $usageCountRight = $usageCounts[$right];
                if ($usageCountLeft !== $usageCountRight) {
                    return $usageCountRight <=> $usageCountLeft;
                }

                return $left <=> $right;
            },
        );

        return $headerNames;
    }

    /**
     * @param array<string, list<array{variant: PayloadVariant, enumCase: string}>> $valueToPartition
     *
     * @return list<Stmt>
     */
    private function buildHeaderValueMatchStatements(string $headerName, array $valueToPartition): array
    {
        $matchArms = [];

        foreach ($valueToPartition as $value => $partition) {
            $matchArms[] = new MatchArm(
                /** @phpstan-ignore cast.useless (PHP may coerce numeric header match arms to int keys) */
                [ExpressionBuilder::literalString((string) $value)],
                ExpressionBuilder::thisMethod('validatedHydrate', [
                    ExpressionBuilder::classConstant($partition[0]['variant']->schema->className->fullyQualified->source),
                    'data',
                ]),
            );
        }

        $matchArms[] = new MatchArm(null, ExpressionBuilder::null());

        return [
            StatementBuilder::assign(
                'resolvedPayload',
                new Expr\Match_(
                    ExpressionBuilder::arrayFetch('headers', $headerName),
                    $matchArms,
                ),
            ),
            new Stmt\If_(
                new Expr\BooleanNot(
                    ExpressionBuilder::identical(
                        ExpressionBuilder::var('resolvedPayload'),
                        ExpressionBuilder::null(),
                    ),
                ),
                [
                    'stmts' => [
                        new Stmt\Return_(ExpressionBuilder::var('resolvedPayload')),
                    ],
                ],
            ),
        ];
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     * @param list<list<string>>                                     $assumedEnumPropertyPaths
     * @param list<list<string>>                                     $satisfiedEnumPropertyPaths
     * @param list<list<string>>                                     $excludeGroupingPropertyPaths
     *
     * @return list<Stmt>
     */
    private function buildGroupedStatements(
        array $variants,
        bool $skipHeaderChecks = false,
        array $assumedEnumPropertyPaths = [],
        array $satisfiedEnumPropertyPaths = [],
        array $excludeGroupingPropertyPaths = [],
    ): array {
        return $this->resolveBodyVariants(
            $variants,
            new BodyResolutionContext(
                $assumedEnumPropertyPaths,
                $satisfiedEnumPropertyPaths,
                [],
                $excludeGroupingPropertyPaths,
            ),
            $skipHeaderChecks,
        );
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<Stmt>
     */
    private function resolveBodyVariants(
        array $variants,
        BodyResolutionContext $context,
        bool $skipHeaderChecks,
    ): array {
        if ($variants === []) {
            return [];
        }

        if (count($variants) === 1) {
            $statement = $this->buildVariantStatement(
                $variants[0]['variant'],
                $skipHeaderChecks,
                $context,
                $this->uniquePropertyNamesAmong($variants[0]['variant'], $variants),
                allowBareReturn: true,
            );

            return $statement instanceof Stmt ? [$statement] : [];
        }

        if ($context->assumedEnumPropertyPaths !== []) {
            $groupingPath = $this->findEnumValueGroupingPath(
                $variants,
                $context->assumedEnumPropertyPaths,
                $context->excludeGroupingPropertyPaths,
            );
            if ($groupingPath !== null) {
                return $this->buildEnumValueGroupedStatements(
                    $groupingPath,
                    $variants,
                    $context,
                    $skipHeaderChecks,
                );
            }
        }

        $partialEnumSplit = $this->findPartialEnumSplit($variants);
        if ($partialEnumSplit !== null) {
            return $this->buildPartialBodyEnumMatchStatements(
                $partialEnumSplit['propertyPath'],
                $partialEnumSplit['uniqueVariantsByValue'],
                $partialEnumSplit['collidingVariants'],
                $skipHeaderChecks,
                $context,
            );
        }

        $groupingPath = $this->findEnumValueGroupingPath(
            $variants,
            $context->assumedEnumPropertyPaths,
            $context->excludeGroupingPropertyPaths,
        );
        if ($groupingPath !== null) {
            return $this->buildEnumValueGroupedStatements(
                $groupingPath,
                $variants,
                $context,
                $skipHeaderChecks,
            );
        }

        $foldablePresenceFields = $this->findFoldablePresenceFields($variants, $context);
        if ($foldablePresenceFields !== []) {
            $inner = $this->resolveBodyVariants(
                $variants,
                $context->withAssumedPresence($foldablePresenceFields),
                $skipHeaderChecks,
            );
            if ($inner === []) {
                return [];
            }

            $precondition = $this->resolveExpressionBuilder->dataPresencePreconditions($foldablePresenceFields);
            if (! $precondition instanceof Expr) {
                return $inner;
            }

            return [
                new Stmt\If_($precondition, ['stmts' => $inner]),
            ];
        }

        $presenceSplitField = $this->findBestPresenceSplitField($variants, $context);
        if ($presenceSplitField !== null) {
            [$withField, $withoutField] = $this->partitionVariantsByRequiredPresence($variants, $context, $presenceSplitField);
            $statements                 = [];

            if ($withField !== []) {
                $inner = $this->resolveBodyVariants(
                    $withField,
                    $context->withAssumedPresence([$presenceSplitField]),
                    $skipHeaderChecks,
                );
                if ($inner !== []) {
                    $precondition = $this->resolveExpressionBuilder->dataPresencePreconditions([$presenceSplitField]);
                    if ($precondition instanceof Expr) {
                        $statements[] = new Stmt\If_($precondition, ['stmts' => $inner]);
                    } else {
                        $statements = array_merge($statements, $inner);
                    }
                }
            }

            if ($withoutField !== []) {
                $statements = array_merge(
                    $statements,
                    $this->resolveBodyVariants($withoutField, $context, $skipHeaderChecks),
                );
            }

            if ($statements !== []) {
                return $statements;
            }
        }

        return $this->buildLinearVariantStatements($variants, $context, $skipHeaderChecks);
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return array{
     *     propertyPath: list<string>,
     *     uniqueVariantsByValue: array<string, array{variant: PayloadVariant, enumCase: string}>,
     *     collidingVariants: list<array{variant: PayloadVariant, enumCase: string}>
     * }|null
     */
    private function findPartialEnumSplit(array $variants): array|null
    {
        $maxDepth = 0;

        foreach ($variants as $variant) {
            foreach ($variant['variant']->enumFingerprints as $fingerprint) {
                $maxDepth = max($maxDepth, count($fingerprint->propertyPath));
            }
        }

        for ($depth = 1; $depth <= $maxDepth; ++$depth) {
            foreach ($this->enumPathsAtDepth($variants, $depth) as $propertyPath) {
                $split = $this->splitVariantsByEnumPath($variants, $propertyPath);
                if ($split === null) {
                    continue;
                }

                return [
                    'propertyPath'          => $propertyPath,
                    'uniqueVariantsByValue' => $split['uniqueVariantsByValue'],
                    'collidingVariants'     => $split['collidingVariants'],
                ];
            }
        }

        return null;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<list<string>>
     */
    private function enumPathsAtDepth(array $variants, int $depth): array
    {
        $paths = [];

        foreach ($variants as $variant) {
            foreach ($variant['variant']->enumFingerprints as $fingerprint) {
                if (count($fingerprint->propertyPath) !== $depth) {
                    continue;
                }

                $encoded = $fingerprint->dottedPath();
                if (array_key_exists($encoded, $paths)) {
                    continue;
                }

                $paths[$encoded] = $fingerprint->propertyPath;
            }
        }

        return array_values($paths);
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     * @param list<string>                                           $propertyPath
     *
     * @return array{
     *     uniqueVariantsByValue: array<string, array{variant: PayloadVariant, enumCase: string}>,
     *     collidingVariants: list<array{variant: PayloadVariant, enumCase: string}>
     * }|null
     */
    private function splitVariantsByEnumPath(array $variants, array $propertyPath): array|null
    {
        $groups = [];

        foreach ($variants as $variant) {
            $fingerprint = $this->fingerprintAtPath($variant['variant'], $propertyPath);
            if (! $fingerprint instanceof EnumFingerprint) {
                $groups['__missing__'][] = $variant;
                continue;
            }

            $groups[$fingerprint->value][] = $variant;
        }

        $uniqueVariantsByValue = [];
        $collidingVariants     = $groups['__missing__'] ?? [];

        unset($groups['__missing__']);

        foreach ($groups as $value => $group) {
            if (count($group) === 1) {
                $uniqueVariantsByValue[$value] = $group[0];
                continue;
            }

            foreach ($group as $variant) {
                $collidingVariants[] = $variant;
            }
        }

        if ($uniqueVariantsByValue === []) {
            return null;
        }

        return [
            'uniqueVariantsByValue' => $uniqueVariantsByValue,
            'collidingVariants'     => $collidingVariants,
        ];
    }

    /** @param list<string> $propertyPath */
    private function fingerprintAtPath(PayloadVariant $variant, array $propertyPath): EnumFingerprint|null
    {
        foreach ($variant->enumFingerprints as $fingerprint) {
            if ($fingerprint->propertyPath === $propertyPath) {
                return $fingerprint;
            }
        }

        return null;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return array<string, list<array{variant: PayloadVariant, enumCase: string}>>
     */
    private function partitionByHeaderSignature(array $variants): array
    {
        $partitions = [];

        foreach ($variants as $variant) {
            $partitions[$this->headerSignature($variant['variant'])][] = $variant;
        }

        return $partitions;
    }

    /**
     * @param array<string, list<array{variant: PayloadVariant, enumCase: string}>> $partitions
     *
     * @return list<list<list<array{variant: PayloadVariant, enumCase: string}>>>
     */
    private function groupPartitionsByHeaderNames(array $partitions): array
    {
        $groups = [];

        foreach ($partitions as $partition) {
            if ($partition === []) {
                continue;
            }

            $groups[implode("\0", $this->headerNames($partition[0]['variant']->headerConstraints))][] = $partition;
        }

        return array_values($groups);
    }

    private function headerSignature(PayloadVariant $variant): string
    {
        $parts = [];

        foreach ($variant->headerConstraints as $constraint) {
            $parts[] = $constraint->name . ':' . ($constraint->hasValue ? $constraint->value : '');
        }

        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param list<HeaderConstraint> $headerConstraints
     *
     * @return list<string>
     */
    private function headerNames(array $headerConstraints): array
    {
        $names = [];

        foreach ($headerConstraints as $constraint) {
            $names[] = $constraint->name;
        }

        return $names;
    }

    /** @param list<HeaderConstraint> $headerConstraints */
    private function headerConstraintValue(array $headerConstraints, string $headerName): string|null
    {
        foreach ($headerConstraints as $constraint) {
            if ($constraint->name === $headerName) {
                return $constraint->hasValue ? $constraint->value : null;
            }
        }

        return null;
    }

    private function resolutionSignature(PayloadVariant $variant): string
    {
        $headerValues = [];
        foreach ($variant->headerConstraints as $constraint) {
            $headerValues[] = $constraint->name . ':' . ($constraint->hasValue ? $constraint->value : '');
        }

        sort($headerValues);

        $enumValues = [];
        foreach ($variant->enumFingerprints as $fingerprint) {
            $enumValues[] = $fingerprint->dottedPath() . ':' . $fingerprint->value;
        }

        sort($enumValues);

        $requiredFields = $variant->requiredFields;
        sort($requiredFields);

        return implode('|', [
            $variant->contentType,
            implode(',', $headerValues),
            implode(',', $enumValues),
            implode(',', $requiredFields),
        ]);
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<Stmt>
     */
    private function buildLinearVariantStatements(
        array $variants,
        BodyResolutionContext $context,
        bool $skipHeaderChecks,
    ): array {
        $statements = [];
        $seen       = [];

        foreach ($variants as $variant) {
            $signature = $this->resolutionSignature($variant['variant']);
            if (array_key_exists($signature, $seen)) {
                continue;
            }

            $seen[$signature] = true;
            $statement        = $this->buildVariantStatement(
                $variant['variant'],
                $skipHeaderChecks,
                $context,
                $this->uniquePropertyNamesAmong($variant['variant'], $variants),
            );
            if (! ($statement instanceof Stmt)) {
                continue;
            }

            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<string>
     */
    private function findFoldablePresenceFields(array $variants, BodyResolutionContext $context): array
    {
        if (count($variants) < 2) {
            return [];
        }

        $intersection = [];

        foreach ($variants as $index => $variant) {
            $fields = $this->variantRequiredPresenceFields($variant['variant'], $context);
            sort($fields);

            if ($index === 0) {
                $intersection = $fields;
                continue;
            }

            $intersection = array_values(array_intersect($intersection, $fields));
        }

        return $intersection;
    }

    /** @param list<array{variant: PayloadVariant, enumCase: string}> $variants */
    private function findBestPresenceSplitField(array $variants, BodyResolutionContext $context): string|null
    {
        if (count($variants) < 2) {
            return null;
        }

        $counts = [];

        foreach ($variants as $variant) {
            foreach ($this->variantRequiredPresenceFields($variant['variant'], $context) as $field) {
                $counts[$field] = ($counts[$field] ?? 0) + 1;
            }
        }

        $bestField = null;
        $bestCount = 0;

        foreach ($counts as $field => $count) {
            if ($count < 2 || $count <= $bestCount) {
                continue;
            }

            if ($count === $bestCount && $bestField !== null && $field >= $bestField) {
                continue;
            }

            $bestField = $field;
            $bestCount = $count;
        }

        return $bestField;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return array{
     *     0: list<array{variant: PayloadVariant, enumCase: string}>,
     *     1: list<array{variant: PayloadVariant, enumCase: string}>
     * }
     */
    private function partitionVariantsByRequiredPresence(
        array $variants,
        BodyResolutionContext $context,
        string $field,
    ): array {
        $withField    = [];
        $withoutField = [];

        foreach ($variants as $variant) {
            if (in_array($field, $this->variantRequiredPresenceFields($variant['variant'], $context), true)) {
                $withField[] = $variant;
                continue;
            }

            $withoutField[] = $variant;
        }

        return [$withField, $withoutField];
    }

    /** @return list<string> */
    private function variantRequiredPresenceFields(PayloadVariant $variant, BodyResolutionContext $context): array
    {
        $enumPropertyPaths = [];
        foreach ($variant->enumFingerprints as $fingerprint) {
            $enumPropertyPaths[$fingerprint->rootProperty()] = true;
        }

        $assumedRequiredFields = [];
        foreach ([...$context->assumedEnumPropertyPaths, ...$context->satisfiedEnumPropertyPaths] as $propertyPath) {
            if ($propertyPath === []) {
                continue;
            }

            $assumedRequiredFields[$propertyPath[0]] = true;
        }

        $fields = [];

        foreach ($variant->requiredFields as $field) {
            if (array_key_exists($field, $enumPropertyPaths)) {
                continue;
            }

            if (array_key_exists($field, $assumedRequiredFields)) {
                continue;
            }

            if (in_array($field, $context->assumedPresenceFields, true)) {
                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<string>
     */
    private function uniquePropertyNamesAmong(PayloadVariant $variant, array $variants): array
    {
        if (count($variants) < 2) {
            return [];
        }

        $names = [];
        foreach ($variant->schema->properties as $property) {
            $names[$property->sourceName] = true;
        }

        foreach ($variants as $entry) {
            if ($entry['variant'] === $variant) {
                continue;
            }

            foreach ($entry['variant']->schema->properties as $property) {
                unset($names[$property->sourceName]);
            }
        }

        return array_keys($names);
    }

    /**
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     * @param list<list<string>>                                     $assumedEnumPropertyPaths
     * @param list<list<string>>                                     $excludeGroupingPropertyPaths
     *
     * @return list<string>|null
     */
    private function findEnumValueGroupingPath(
        array $variants,
        array $assumedEnumPropertyPaths,
        array $excludeGroupingPropertyPaths,
    ): array|null {
        $candidatePaths = [];

        foreach ($assumedEnumPropertyPaths as $propertyPath) {
            if (count($propertyPath) !== 1 || $this->isExcludedGroupingPath($propertyPath, $excludeGroupingPropertyPaths)) {
                continue;
            }

            $candidatePaths[implode("\0", $propertyPath)] = $propertyPath;
        }

        foreach ($variants as $variant) {
            foreach ($variant['variant']->enumFingerprints as $fingerprint) {
                if (count($fingerprint->propertyPath) !== 1) {
                    continue;
                }

                if ($this->isExcludedGroupingPath($fingerprint->propertyPath, $excludeGroupingPropertyPaths)) {
                    continue;
                }

                $candidatePaths[implode("\0", $fingerprint->propertyPath)] = $fingerprint->propertyPath;
            }
        }

        $bestPath  = null;
        $bestScore = 0;

        foreach ($candidatePaths as $propertyPath) {
            $score = 0;

            foreach ($this->groupVariantsByEnumValue($variants, $propertyPath) as $value => $group) {
                if ($value === '__missing__') {
                    continue;
                }

                if (count($group) < 2) {
                    continue;
                }

                $score += count($group);
            }

            if ($score <= $bestScore) {
                continue;
            }

            $bestScore = $score;
            $bestPath  = $propertyPath;
        }

        if ($bestPath === null || $bestScore < 2) {
            return null;
        }

        return $bestPath;
    }

    /**
     * @param list<string>                                           $propertyPath
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return list<Stmt>
     */
    private function buildEnumValueGroupedStatements(
        array $propertyPath,
        array $variants,
        BodyResolutionContext $context,
        bool $skipHeaderChecks,
    ): array {
        $statements = [];

        foreach ($this->groupVariantsByEnumValue($variants, $propertyPath) as $value => $group) {
            if ($value === '__missing__') {
                $statements = array_merge(
                    $statements,
                    $this->resolveBodyVariants($group, $context, $skipHeaderChecks),
                );
                continue;
            }

            if (count($group) < 2) {
                $statement = $this->buildVariantStatement(
                    $group[0]['variant'],
                    $skipHeaderChecks,
                    $context,
                    $this->uniquePropertyNamesAmong($group[0]['variant'], $variants),
                    allowBareReturn: true,
                );
                if ($statement instanceof Stmt) {
                    $statements[] = $statement;
                }

                continue;
            }

            $innerStatements = $this->resolveBodyVariants(
                $group,
                $context->withEnumValueGroup($propertyPath),
                $skipHeaderChecks,
            );

            if ($innerStatements === []) {
                continue;
            }

            $statements[] = new Stmt\If_(
                ExpressionBuilder::identical(
                    $this->resolveExpressionBuilder->nestedArrayFetch('data', $propertyPath),
                    ExpressionBuilder::literalString($value),
                ),
                ['stmts' => $innerStatements],
            );
        }

        return $statements;
    }

    /**
     * @param list<string>                                           $propertyPath
     * @param list<array{variant: PayloadVariant, enumCase: string}> $variants
     *
     * @return array<string, list<array{variant: PayloadVariant, enumCase: string}>>
     */
    private function groupVariantsByEnumValue(array $variants, array $propertyPath): array
    {
        $groups = [];

        foreach ($variants as $variant) {
            $fingerprint = $this->fingerprintAtPath($variant['variant'], $propertyPath);
            if (! $fingerprint instanceof EnumFingerprint) {
                $groups['__missing__'][] = $variant;
                continue;
            }

            $groups[$fingerprint->value][] = $variant;
        }

        return $groups;
    }

    /**
     * @param list<string>       $propertyPath
     * @param list<list<string>> $excludeGroupingPropertyPaths
     */
    private function isExcludedGroupingPath(array $propertyPath, array $excludeGroupingPropertyPaths): bool
    {
        return in_array($propertyPath, $excludeGroupingPropertyPaths, true);
    }

    /**
     * @param array<string, array{variant: PayloadVariant, enumCase: string}> $uniqueVariantsByValue
     * @param list<array{variant: PayloadVariant, enumCase: string}>          $collidingVariants
     * @param list<string>                                                    $propertyPath
     *
     * @return list<Stmt>
     */
    private function buildPartialBodyEnumMatchStatements(
        array $propertyPath,
        array $uniqueVariantsByValue,
        array $collidingVariants,
        bool $skipHeaderChecks,
        BodyResolutionContext $context,
    ): array {
        $matchArms = [];

        foreach ($uniqueVariantsByValue as $value => $variant) {
            $matchArms[] = new MatchArm(
                [ExpressionBuilder::literalString($value)],
                ExpressionBuilder::thisMethod('validatedHydrate', [
                    ExpressionBuilder::classConstant($variant['variant']->schema->className->fullyQualified->source),
                    'data',
                ]),
            );
        }

        $valueExpression = $this->resolveExpressionBuilder->nestedArrayFetch('data', $propertyPath);

        if ($collidingVariants === []) {
            $matchArms[] = new MatchArm(null, new Expr\Throw_(ExpressionBuilder::var('error')));

            return [
                new Stmt\If_(
                    $this->resolveExpressionBuilder->nestedArrayKeyExists('data', $propertyPath),
                    [
                        'stmts' => [
                            new Stmt\Return_(
                                new Expr\Match_($valueExpression, $matchArms),
                            ),
                        ],
                    ],
                ),
            ];
        }

        $matchArms[] = new MatchArm(null, ExpressionBuilder::null());

        $colliderAssumedPaths = [...$context->assumedEnumPropertyPaths, $propertyPath];
        $colliderContext      = new BodyResolutionContext(
            $colliderAssumedPaths,
            $context->satisfiedEnumPropertyPaths,
            $context->assumedPresenceFields,
            $context->satisfiedEnumPropertyPaths,
        );
        $colliderStatements   = $this->resolveBodyVariants(
            $collidingVariants,
            $colliderContext,
            $skipHeaderChecks,
        );

        $matchStatements = [
            StatementBuilder::assign('resolvedPayload', new Expr\Match_($valueExpression, $matchArms)),
            new Stmt\If_(
                new Expr\BooleanNot(
                    ExpressionBuilder::identical(
                        ExpressionBuilder::var('resolvedPayload'),
                        ExpressionBuilder::null(),
                    ),
                ),
                [
                    'stmts' => [
                        new Stmt\Return_(ExpressionBuilder::var('resolvedPayload')),
                    ],
                ],
            ),
        ];

        if (count($propertyPath) === 1) {
            return [
                new Stmt\If_(
                    $this->resolveExpressionBuilder->nestedArrayKeyExists('data', $propertyPath),
                    [
                        'stmts' => array_merge($matchStatements, $colliderStatements),
                    ],
                ),
            ];
        }

        return [
            new Stmt\If_(
                $this->resolveExpressionBuilder->nestedArrayKeyExists('data', $propertyPath),
                ['stmts' => $matchStatements],
            ),
            ...$colliderStatements,
        ];
    }

    /** @param list<string> $presenceFields */
    private function buildVariantStatement(
        PayloadVariant $variant,
        bool $skipHeaderChecks,
        BodyResolutionContext $context,
        array $presenceFields = [],
        bool $allowBareReturn = false,
    ): Stmt|null {
        $preconditions = $this->variantPreconditions(
            $variant,
            $skipHeaderChecks,
            $context,
            $presenceFields,
        );
        $schemaClass   = $variant->schema->className->fullyQualified->source;
        $hydrateReturn = new Stmt\Return_(
            ExpressionBuilder::thisMethod('validatedHydrate', [
                ExpressionBuilder::classConstant($schemaClass),
                'data',
            ]),
        );

        if ($preconditions instanceof Expr) {
            return new Stmt\If_($preconditions, ['stmts' => [$hydrateReturn]]);
        }

        if (
            $allowBareReturn && (
            $context->assumedEnumPropertyPaths !== []
            || $context->satisfiedEnumPropertyPaths !== []
            || $context->assumedPresenceFields !== []
            )
        ) {
            return $hydrateReturn;
        }

        return null;
    }

    /** @param list<string> $presenceFields */
    private function variantPreconditions(
        PayloadVariant $variant,
        bool $skipHeaderChecks,
        BodyResolutionContext $context,
        array $presenceFields = [],
    ): Expr|null {
        $conditions = [];

        if (! $skipHeaderChecks) {
            $headerPreconditions = $this->resolveExpressionBuilder->headerPreconditions($variant->headerConstraints);
            if ($headerPreconditions instanceof Expr) {
                $conditions[] = $headerPreconditions;
            }
        }

        $bodyPreconditions = $this->resolveExpressionBuilder->bodyPreconditions(
            $variant,
            $context->assumedEnumPropertyPaths,
            $context->satisfiedEnumPropertyPaths,
            $presenceFields,
            $context->assumedPresenceFields,
        );
        if ($bodyPreconditions instanceof Expr) {
            $conditions[] = $bodyPreconditions;
        }

        if ($conditions === []) {
            return null;
        }

        return ExpressionBuilder::andAll($conditions);
    }

    /**
     * @param list<Stmt> $statements
     *
     * @return list<Stmt>
     */
    private function wrapTryCatch(array $statements): array
    {
        if ($statements === []) {
            return [];
        }

        return [
            new Stmt\TryCatch(
                $statements,
                [
                    ExpressionBuilder::catchThrowable('throwable', 'error'),
                ],
            ),
        ];
    }
}
