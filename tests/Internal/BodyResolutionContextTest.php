<?php

declare(strict_types=1);

namespace OpenAPITools\Tests\Generator\PSR15\WebHook\Internal;

use OpenAPITools\Generator\PSR15\WebHook\Internal\BodyResolutionContext;
use PHPUnit\Framework\Attributes\Test;
use WyriHaximus\TestUtilities\TestCase;

final class BodyResolutionContextTest extends TestCase
{
    #[Test]
    public function withAssumedPresenceReturnsSameInstanceForEmptyFields(): void
    {
        $context = new BodyResolutionContext([], [], ['repository'], []);

        self::assertSame($context, $context->withAssumedPresence([]));
    }

    #[Test]
    public function withAssumedPresenceAppendsFields(): void
    {
        $context = new BodyResolutionContext([], [], ['repository'], []);
        $updated = $context->withAssumedPresence(['sender']);

        self::assertSame(['repository', 'sender'], $updated->assumedPresenceFields);
    }

    #[Test]
    public function withEnumValueGroupTracksAssumedAndExcludedPaths(): void
    {
        $context = new BodyResolutionContext([], [], [], []);
        $updated = $context->withEnumValueGroup(['action']);

        self::assertSame([['action']], $updated->assumedEnumPropertyPaths);
        self::assertSame([['action']], $updated->satisfiedEnumPropertyPaths);
        self::assertSame([['action']], $updated->excludeGroupingPropertyPaths);
    }
}
