<?php

declare(strict_types=1);

namespace OpenAPITools\Generator\PSR15\WebHook\Internal;

final readonly class BodyResolutionContext
{
    /**
     * @param list<list<string>> $assumedEnumPropertyPaths
     * @param list<list<string>> $satisfiedEnumPropertyPaths
     * @param list<string>       $assumedPresenceFields
     * @param list<list<string>> $excludeGroupingPropertyPaths
     */
    public function __construct(
        public array $assumedEnumPropertyPaths,
        public array $satisfiedEnumPropertyPaths,
        public array $assumedPresenceFields,
        public array $excludeGroupingPropertyPaths,
    ) {
    }

    /** @param list<string> $fields */
    public function withAssumedPresence(array $fields): self
    {
        if ($fields === []) {
            return $this;
        }

        return new self(
            $this->assumedEnumPropertyPaths,
            $this->satisfiedEnumPropertyPaths,
            [...$this->assumedPresenceFields, ...$fields],
            $this->excludeGroupingPropertyPaths,
        );
    }

    /** @param list<string> $propertyPath */
    public function withEnumValueGroup(array $propertyPath): self
    {
        return new self(
            [...$this->assumedEnumPropertyPaths, $propertyPath],
            [...$this->satisfiedEnumPropertyPaths, $propertyPath],
            $this->assumedPresenceFields,
            [...$this->excludeGroupingPropertyPaths, $propertyPath],
        );
    }
}
