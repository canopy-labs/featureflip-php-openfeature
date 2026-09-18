<?php

declare(strict_types=1);

namespace Featureflip\OpenFeature\Tests;

use Featureflip\EvaluationDetail;
use Featureflip\OpenFeature\ResolutionMapping;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use PHPUnit\Framework\TestCase;

final class ResolutionMappingTest extends TestCase
{
    public function testRuleMatchBecomesTargetingMatch(): void
    {
        $details = ResolutionMapping::toResolutionDetails(
            new EvaluationDetail('blue', 'RULE_MATCH', 'rule-7', 'variant-b'),
            'control',
        );

        $this->assertSame('blue', $details->getValue());
        $this->assertSame(Reason::TARGETING_MATCH, $details->getReason());
        $this->assertSame('variant-b', $details->getVariant());
        $this->assertNull($details->getError());
    }

    public function testFallthroughBecomesDefault(): void
    {
        $details = ResolutionMapping::toResolutionDetails(
            new EvaluationDetail(true, 'FALLTHROUGH'),
            false,
        );

        $this->assertTrue($details->getValue());
        $this->assertSame(Reason::DEFAULT, $details->getReason());
    }

    public function testFlagDisabledBecomesDisabled(): void
    {
        $details = ResolutionMapping::toResolutionDetails(
            new EvaluationDetail(false, 'FLAG_DISABLED'),
            true,
        );

        $this->assertSame(Reason::DISABLED, $details->getReason());
    }

    public function testPrerequisiteFailedIsSurfacedVerbatim(): void
    {
        $details = ResolutionMapping::toResolutionDetails(
            new EvaluationDetail(false, 'PREREQUISITE_FAILED', null, null, 'parent-flag'),
            true,
        );

        $this->assertSame('PREREQUISITE_FAILED', $details->getReason());
    }

    public function testUnrecognisedReasonBecomesUnknown(): void
    {
        $details = ResolutionMapping::toResolutionDetails(
            new EvaluationDetail('x', 'SOMETHING_NEW'),
            'y',
        );

        $this->assertSame(Reason::UNKNOWN, $details->getReason());
    }

    public function testFlagNotFoundSubstitutesTheCallerDefault(): void
    {
        // The SDK's error details can carry a flag's off-variation value, which
        // is wrong-typed. The OpenFeature error contract prescribes the
        // caller's default instead.
        $details = ResolutionMapping::toResolutionDetails(
            new EvaluationDetail('off-variation-leak', 'FLAG_NOT_FOUND'),
            'caller-default',
        );

        $this->assertSame('caller-default', $details->getValue());
        $this->assertSame(Reason::ERROR, $details->getReason());
        $this->assertTrue(
            ErrorCode::FLAG_NOT_FOUND()->equals($details->getError()?->getResolutionErrorCode()),
        );
    }

    public function testErrorReasonBecomesGeneral(): void
    {
        $details = ResolutionMapping::toResolutionDetails(
            new EvaluationDetail(null, 'ERROR'),
            'caller-default',
        );

        $this->assertSame('caller-default', $details->getValue());
        $this->assertTrue(
            ErrorCode::GENERAL()->equals($details->getError()?->getResolutionErrorCode()),
        );
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function integerCases(): iterable
    {
        yield 'int' => [42, true];
        yield 'whole float' => [1.0, true];
        yield 'exponent-form whole float' => [1e2, true];
        yield 'fractional float' => [1.5, false];
        yield 'infinity' => [INF, false];
        yield 'nan' => [NAN, false];
        yield 'numeric string' => ['42', false];
        yield 'bool' => [true, false];
        // 1e20 is a whole number but not representable as an int. Accepting it
        // used to hand the caller (int) 1e20 == 7766279631452241920 plus a PHP
        // 8.5 warning, instead of TYPE_MISMATCH.
        yield 'whole float above the int range' => [1e20, false];
        yield 'whole float below the int range' => [-1e20, false];
        // Exactly 2**63 == PHP_INT_MAX + 1: the first float that no longer fits.
        yield 'float at 2**63' => [9.2233720368547758e18, false];
        // Exactly -(2**63) == PHP_INT_MIN, which does fit and round-trips.
        yield 'float at PHP_INT_MIN' => [-9.2233720368547758e18, true];
        // The largest float strictly below 2**63 (2**63 - 1024).
        yield 'largest float inside the int range' => [9223372036854774784.0, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('integerCases')]
    public function testMatchesInteger(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, ResolutionMapping::matchesInteger($value));
    }

    public function testCoerceIntegerNarrowsAWholeFloat(): void
    {
        // Not cosmetic: OpenFeatureClient runs a ValueTypeValidator over the
        // resolved value, and a float handed back for an integer read fails it
        // — the client then returns the caller's default with reason ERROR and
        // error code GENERAL, silently, rather than throwing.
        $this->assertSame(1, ResolutionMapping::coerceInteger(1.0));
        $this->assertSame(100, ResolutionMapping::coerceInteger(1e2));
        $this->assertSame(42, ResolutionMapping::coerceInteger(42));
    }

    public function testMatchesFloatAcceptsIntegralValues(): void
    {
        $this->assertTrue(ResolutionMapping::matchesFloat(1));
        $this->assertTrue(ResolutionMapping::matchesFloat(1.5));
        $this->assertFalse(ResolutionMapping::matchesFloat('1.5'));
        $this->assertFalse(ResolutionMapping::matchesFloat(true));
    }

    public function testMatchesObjectAcceptsArraysOnly(): void
    {
        $this->assertTrue(ResolutionMapping::matchesObject(['a' => 1]));
        $this->assertTrue(ResolutionMapping::matchesObject([1, 2, 3]));
        $this->assertFalse(ResolutionMapping::matchesObject('string'));
        $this->assertFalse(ResolutionMapping::matchesObject(null));
    }

    public function testMatchesBooleanAndString(): void
    {
        $this->assertTrue(ResolutionMapping::matchesBoolean(false));
        $this->assertFalse(ResolutionMapping::matchesBoolean(0));
        $this->assertTrue(ResolutionMapping::matchesString('x'));
        $this->assertFalse(ResolutionMapping::matchesString(1));
    }
}
