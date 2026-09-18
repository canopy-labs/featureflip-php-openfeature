<?php

declare(strict_types=1);

namespace Featureflip\OpenFeature\Tests;

use DateTime;
use DateTimeZone;
use Featureflip\OpenFeature\ContextMapping;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use PHPUnit\Framework\TestCase;

final class ContextMappingTest extends TestCase
{
    public function testNullContextBecomesAnEmptyArray(): void
    {
        $this->assertSame([], ContextMapping::toFeatureflipContext(null));
    }

    public function testTargetingKeyIsWrittenUnderBothSpellings(): void
    {
        // The SDK's condition path resolves an attribute by exact key with no
        // userId/user_id aliasing, so the spelling decides whether a rule
        // matches. Node and Python write user_id; writing both is what makes a
        // rule against either spelling resolve through this provider too.
        $context = new EvaluationContext('user-123', new Attributes(['plan' => 'pro']));

        $mapped = ContextMapping::toFeatureflipContext($context);

        $this->assertSame(
            ['plan' => 'pro', 'userId' => 'user-123', 'user_id' => 'user-123'],
            $mapped,
        );
        // One bucket identity, not two: both spellings hold the same string,
        // and bucketing reads exactly one of them.
        $this->assertSame($mapped['userId'], $mapped['user_id']);
    }

    public function testExplicitUserIdWinsOverTargetingKeyWithNoAliasInjected(): void
    {
        // Only the framework-supplied targetingKey gets both spellings. The
        // caller's own data is respected as given — we do not fabricate an
        // alias for it.
        $context = new EvaluationContext('targeting', new Attributes(['userId' => 'explicit']));

        $mapped = ContextMapping::toFeatureflipContext($context);

        $this->assertSame('explicit', $mapped['userId']);
        $this->assertArrayNotHasKey('user_id', $mapped);
    }

    public function testExplicitSnakeCaseUserIdAlsoWinsWithNoAliasInjected(): void
    {
        $context = new EvaluationContext('targeting', new Attributes(['user_id' => 'explicit']));

        $mapped = ContextMapping::toFeatureflipContext($context);

        $this->assertSame('explicit', $mapped['user_id']);
        $this->assertArrayNotHasKey('userId', $mapped);
    }

    public function testTargetingKeyIsNotLeakedUnderItsOwnName(): void
    {
        // What this guarantees: the key read from getTargetingKey() — which
        // upstream stores outside the attribute bag — is never copied in under
        // its own reserved name. That is a bug the .NET provider had to fix.
        //
        // What it deliberately does NOT claim: unlike the .NET, Node and Go
        // providers, this one does not strip a targetingKey attribute a caller
        // puts in the bag itself. Attributes pass through unchanged.
        $context = new EvaluationContext('user-123');

        $this->assertArrayNotHasKey('targetingKey', ContextMapping::toFeatureflipContext($context));
    }

    public function testDateTimeAttributesBecomeIsoStrings(): void
    {
        // PHP is the only SDK whose context type can hold a date object.
        // Every SDK gates its date comparison on the ISO grammar, so a
        // DateTime passed through raw would match nothing.
        $context = new EvaluationContext(null, new Attributes([
            'signedUpAt' => new DateTime('2026-01-15T10:30:00', new DateTimeZone('UTC')),
        ]));

        $this->assertSame(
            '2026-01-15T10:30:00+00:00',
            ContextMapping::toFeatureflipContext($context)['signedUpAt'],
        );
    }

    public function testNestedDateTimeAttributesAreAlsoConverted(): void
    {
        $context = new EvaluationContext(null, new Attributes([
            'trial' => ['endsAt' => new DateTime('2026-02-01T00:00:00', new DateTimeZone('UTC'))],
        ]));

        $this->assertSame(
            '2026-02-01T00:00:00+00:00',
            ContextMapping::toFeatureflipContext($context)['trial']['endsAt'],
        );
    }

    public function testOtherScalarsPassThroughUnchanged(): void
    {
        $context = new EvaluationContext(null, new Attributes([
            'age' => 30,
            'beta' => true,
            'score' => 1.5,
            'tags' => ['a', 'b'],
        ]));

        $this->assertSame(
            ['age' => 30, 'beta' => true, 'score' => 1.5, 'tags' => ['a', 'b']],
            ContextMapping::toFeatureflipContext($context),
        );
    }
}
