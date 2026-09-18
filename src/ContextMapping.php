<?php

declare(strict_types=1);

namespace Featureflip\OpenFeature;

use DateTimeInterface;
use OpenFeature\interfaces\flags\EvaluationContext;

/**
 * Maps an OpenFeature evaluation context onto the plain array the Featureflip
 * SDK takes as its context.
 */
final class ContextMapping
{
    /** @return array<string, mixed> */
    public static function toFeatureflipContext(?EvaluationContext $context): array
    {
        if ($context === null) {
            return [];
        }

        /** @var array<string, mixed> $mapped */
        $mapped = self::convertDates($context->getAttributes()->toArray());

        // targetingKey is written under BOTH spellings, because the spelling
        // decides whether a targeting rule matches. The SDK's condition path
        // (Evaluation/ConditionEvaluator) resolves an attribute with an exact
        // array_key_exists() and no aliasing — the userId/user_id alias lives
        // only in Evaluation/Evaluator and only for bucketing. So a rule
        // written against user_id, the canonical wire field that the Node and
        // Python providers write and our PHP onboarding snippet teaches, would
        // silently never match through this provider if only userId were set.
        // Writing both is what gives PHP behavioural parity across that gap.
        //
        // This does not split a percentage rollout's identity: bucketing reads
        // exactly one key ($serve->bucketBy, defaulting to userId), and both
        // spellings hold the identical string.
        //
        // The asymmetry below is deliberate: an explicit userId OR user_id
        // supplied by the caller still wins outright and nothing is injected.
        // We respect the caller's own data rather than fabricating an alias
        // for it; only the framework-supplied targetingKey gets both
        // spellings.
        //
        // It is never copied under its own name: targetingKey is OpenFeature's
        // reserved key and leaking it into the attribute bag is a bug the .NET
        // provider had to fix.
        $targetingKey = $context->getTargetingKey();
        if (
            $targetingKey !== null
            && !array_key_exists('userId', $mapped)
            && !array_key_exists('user_id', $mapped)
        ) {
            $mapped['userId'] = $targetingKey;
            $mapped['user_id'] = $targetingKey;
        }

        return $mapped;
    }

    /**
     * Render every DateTime in the bag as ISO-8601, recursing into nested
     * arrays.
     *
     * PHP's Attributes may hold a DateTime, which no other SDK's context type
     * can. Every SDK gates its date-comparison branch on the ISO grammar, so a
     * DateTime left as an object would stringify unpredictably — or not at all
     * — and silently match no date rule.
     *
     * @param array<array-key, mixed> $attributes
     * @return array<array-key, mixed>
     */
    private static function convertDates(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $attributes[$key] = $value->format(DateTimeInterface::ATOM);
            } elseif (is_array($value)) {
                $attributes[$key] = self::convertDates($value);
            }
        }

        return $attributes;
    }
}
