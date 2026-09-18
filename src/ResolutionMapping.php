<?php

declare(strict_types=1);

namespace Featureflip\OpenFeature;

use Featureflip\EvaluationDetail;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\interfaces\provider\ResolutionDetails;

/**
 * Translation between Featureflip evaluation details and OpenFeature
 * resolution details.
 *
 * Separate from the provider so the reason table and the type rules — where
 * the cross-SDK contract actually lives — can be tested by constructing an
 * {@see EvaluationDetail} directly, with no client involved.
 *
 * `ruleId` and `prerequisiteKey` are deliberately NOT surfaced as flag
 * metadata at 0.1.0: the released `open-feature/sdk` (^2.3, currently 2.3.0)
 * has no flag-metadata API at all — `ResolutionDetailsBuilder::withMetadata()`
 * doesn't exist yet. It ships in an unreleased upstream commit; once the
 * floor moves to ^2.4 these two fields return. Don't re-add the
 * `withMetadata()` call without bumping the composer.json constraint first.
 */
final class ResolutionMapping
{
    /**
     * No standard OpenFeature reason models an unmet prerequisite. Reasons are
     * open strings, so surface it verbatim rather than mislabelling it as
     * DEFAULT or DISABLED — the same choice every sibling provider made.
     */
    public const PREREQUISITE_FAILED = 'PREREQUISITE_FAILED';

    /** Featureflip reason => OpenFeature reason, for reasons carrying a usable value. */
    private const REASONS = [
        'RULE_MATCH' => Reason::TARGETING_MATCH,
        'FALLTHROUGH' => Reason::DEFAULT,
        'FLAG_DISABLED' => Reason::DISABLED,
        'PREREQUISITE_FAILED' => self::PREREQUISITE_FAILED,
    ];

    /**
     * The error code for a reason that means "no usable value", or null when
     * the reason carries one.
     *
     * A method rather than a const array because ErrorCode is a MyCLabs enum
     * whose members are objects, which a class constant cannot hold.
     */
    public static function errorCodeFor(string $reason): ?ErrorCode
    {
        return match ($reason) {
            'FLAG_NOT_FOUND' => ErrorCode::FLAG_NOT_FOUND(),
            'ERROR' => ErrorCode::GENERAL(),
            default => null,
        };
    }

    /**
     * Map a Featureflip detail onto OpenFeature resolution details.
     *
     * On an error reason the caller's $defaultValue is substituted rather than
     * passing $detail->value through: the SDK's error details can carry a
     * flag's off-variation value — from a prerequisite chain past the depth
     * limit, or an errored prerequisite — which would be wrong-typed, and is
     * not what the OpenFeature error contract prescribes.
     *
     * Callers must run the matching type guard before calling this on a
     * non-error reason; the guard is skipped here deliberately, because on an
     * error reason the substituted default is correctly typed by construction.
     */
    public static function toResolutionDetails(
        EvaluationDetail $detail,
        mixed $defaultValue,
    ): ResolutionDetails {
        $errorCode = self::errorCodeFor($detail->reason);
        if ($errorCode !== null) {
            return self::error(
                $defaultValue,
                $errorCode,
                sprintf('Featureflip returned %s', $detail->reason),
            );
        }

        $builder = (new ResolutionDetailsBuilder())
            ->withValue($detail->value)
            ->withReason(self::REASONS[$detail->reason] ?? Reason::UNKNOWN);

        if ($detail->variationKey !== null) {
            $builder->withVariant($detail->variationKey);
        }

        return $builder->build();
    }

    /** An error resolution carrying the caller's default. */
    public static function error(mixed $value, ErrorCode $code, string $message): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder())
            ->withValue($value)
            ->withReason(Reason::ERROR)
            ->withError(new ResolutionError($code, $message))
            ->build();
    }

    public static function matchesBoolean(mixed $value): bool
    {
        return is_bool($value);
    }

    public static function matchesString(mixed $value): bool
    {
        return is_string($value);
    }

    /**
     * Whole-number floats satisfy an integer read as well as ints.
     *
     * Flag values arrive as JSON, which does not distinguish 1 from 1.0, and
     * the engine renders whole floats with a decimal part — so rejecting 1.0
     * would make an integer flag's readability depend on how its value
     * happened to serialize.
     *
     * Unlike Python's equivalent there is no bool carve-out: PHP's bool is not
     * a subtype of int, so is_int(true) is already false.
     *
     * A whole-valued float outside the int range is rejected rather than
     * truncated: 1e20 is a whole number, but (int) 1e20 is a meaningless value
     * (and emits a warning on PHP 8.5), so the caller would get a wrong number
     * instead of TYPE_MISMATCH. The range check has to run BEFORE the cast, or
     * the cast itself warns.
     *
     * The bounds are written as float literals, not as (float) PHP_INT_MAX:
     * that cast rounds UP to 2**63, which would re-admit the overflow this
     * excludes. -9.2233720368547758e18 is exactly -(2**63) == PHP_INT_MIN, and
     * 9.2233720368547758e18 is exactly 2**63 == PHP_INT_MAX + 1.
     */
    public static function matchesInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_float($value)
            && is_finite($value)
            && $value >= -9.2233720368547758e18
            && $value < 9.2233720368547758e18
            && (float) (int) $value === $value;
    }

    /**
     * Narrow a value that passed {@see matchesInteger} to a real int.
     *
     * Required, not cosmetic — and the failure it avoids is silent, not loud.
     * OpenFeatureClient runs a ValueTypeValidator over the resolved value; a
     * float handed back for an integer read fails it, the client throws
     * InvalidResolutionValueError internally, catches it, and returns the
     * CALLER'S DEFAULT with reason ERROR and error code GENERAL. No TypeError
     * ever surfaces, so without this narrowing a healthy integer flag that
     * happened to serialize as 1.0 would quietly resolve to the default.
     */
    public static function coerceInteger(int|float $value): int
    {
        return (int) $value;
    }

    /** Any JSON number satisfies a float read, an integral one included. */
    public static function matchesFloat(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * Objects and arrays only, matching the `array` the interface is typed
     * against. PHP decodes JSON objects to associative arrays, so both shapes
     * land here.
     */
    public static function matchesObject(mixed $value): bool
    {
        return is_array($value);
    }
}
