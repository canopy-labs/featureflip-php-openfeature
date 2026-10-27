<?php

declare(strict_types=1);

namespace Featureflip\OpenFeature;

use Featureflip\Config;
use Featureflip\EvaluationDetail;
use Featureflip\FeatureflipClient;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\ResolutionDetails;
use Throwable;

/**
 * An OpenFeature provider backed by the Featureflip PHP server SDK.
 *
 * ```php
 * $client = FeatureflipClient::get($sdkKey, $config);
 * OpenFeatureAPI::getInstance()->setProvider(new FeatureflipProvider($client));
 *
 * $enabled = OpenFeatureAPI::getInstance()
 *     ->getClient()
 *     ->getBooleanValue('new-checkout', false, $context);
 * ```
 *
 * Or let the provider build the client, which also names it to Featureflip
 * (see {@see create()}):
 *
 * ```php
 * OpenFeatureAPI::getInstance()->setProvider(FeatureflipProvider::create($sdkKey, $config));
 * ```
 *
 * There is no key-only form. The PHP SDK's core requires caller-supplied
 * PSR-18, PSR-17 and PSR-16 implementations and throws without them, so the
 * caller's `Config` must carry them; the provider will not choose an HTTP
 * client or a cache on the application's behalf.
 *
 * The provider never closes the client: the caller owns it.
 */
final class FeatureflipProvider extends AbstractProvider
{
    protected static string $NAME = 'featureflip-php';

    /**
     * Sent after the SDK's own token in the User-Agent by clients built with
     * {@see create()} (#4152), so requests made through the provider can be counted
     * apart from direct SDK use.
     */
    public const WRAPPER_NAME = 'featureflip-openfeature-php';

    /**
     * Packagist reads versions from git tags and composer.json carries none, so
     * this is maintained by hand and held equal to CHANGELOG.md's newest heading
     * by tools/check-sdk-versions. Bump both together.
     */
    public const VERSION = '0.2.1';

    public function __construct(private readonly FeatureflipClient $client)
    {
    }

    /**
     * Builds the client from `$config`, naming this provider as the SDK's wrapper so
     * requests read `featureflip-php/<v> featureflip-openfeature-php/<v>`.
     *
     * The wrapper goes on a new `Config`, so one the caller also uses for direct SDK
     * clients is not relabelled, and a `wrapperName` the caller already set is kept:
     * the SDK carries one, and theirs is the more specific. The SDK shares one core
     * per SDK key, so if a client for `$sdkKey` already exists in the process, its
     * configuration (and identity) wins, as with any other option.
     */
    public static function create(string $sdkKey, Config $config): self
    {
        return new self(FeatureflipClient::get($sdkKey, self::identified($config)));
    }

    private static function identified(Config $config): Config
    {
        if ($config->wrapperName !== null && $config->wrapperName !== '') {
            return $config;
        }

        // Every Config property is a promoted constructor parameter, so its public
        // state round-trips as named arguments and a field added later is carried
        // over without touching this line.
        return new Config(...[
            ...get_object_vars($config),
            'wrapperName' => self::WRAPPER_NAME,
            'wrapperVersion' => self::VERSION,
        ]);
    }

    public function resolveBooleanValue(
        string $flagKey,
        bool $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolve(
            $flagKey,
            $defaultValue,
            $context,
            ResolutionMapping::matchesBoolean(...),
            'boolean',
        );
    }

    public function resolveStringValue(
        string $flagKey,
        string $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolve(
            $flagKey,
            $defaultValue,
            $context,
            ResolutionMapping::matchesString(...),
            'string',
        );
    }

    public function resolveIntegerValue(
        string $flagKey,
        int $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolve(
            $flagKey,
            $defaultValue,
            $context,
            ResolutionMapping::matchesInteger(...),
            'integer',
            ResolutionMapping::coerceInteger(...),
        );
    }

    public function resolveFloatValue(
        string $flagKey,
        float $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolve(
            $flagKey,
            $defaultValue,
            $context,
            ResolutionMapping::matchesFloat(...),
            'float',
            static fn (int|float $value): float => (float) $value,
        );
    }

    /** @param array<array-key, mixed> $defaultValue */
    public function resolveObjectValue(
        string $flagKey,
        array $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolve(
            $flagKey,
            $defaultValue,
            $context,
            ResolutionMapping::matchesObject(...),
            'object',
        );
    }

    /**
     * Evaluate one flag and map the result, enforcing the requested type.
     *
     * Reads untyped through variationDetail() rather than a typed accessor.
     * The typed accessors narrow a wrong-typed value to the caller's default,
     * which would make TYPE_MISMATCH indistinguishable from a correct
     * evaluation that happened to return the default. Reading untyped leaves
     * the type decision here, where the requested type is known.
     *
     * @param callable(mixed): bool   $matchesType
     * @param ?callable(mixed): mixed $narrow Applied to a value that passed the
     *                                        guard, where the interface's
     *                                        return type is narrower than what
     *                                        JSON can produce.
     */
    private function resolve(
        string $flagKey,
        mixed $defaultValue,
        ?EvaluationContext $context,
        callable $matchesType,
        string $expectedType,
        ?callable $narrow = null,
    ): ResolutionDetails {
        try {
            $detail = $this->client->variationDetail(
                $flagKey,
                ContextMapping::toFeatureflipContext($context),
                $defaultValue,
            );
        } catch (Throwable $error) {
            // The SDK fails safe internally rather than throwing, so this is
            // belt and braces — but a provider that let an exception escape
            // would take down the caller's request over a flag read.
            return ResolutionMapping::error($defaultValue, ErrorCode::GENERAL(), $error->getMessage());
        }

        // On an error reason the caller's default is substituted downstream, so
        // the guard is skipped: the SDK's error details can carry a wrong-typed
        // off-variation value, and guarding it would report a genuine error as
        // a type mismatch.
        if (ResolutionMapping::errorCodeFor($detail->reason) !== null) {
            return ResolutionMapping::toResolutionDetails($detail, $defaultValue);
        }

        if (!$matchesType($detail->value)) {
            return ResolutionMapping::error(
                $defaultValue,
                ErrorCode::TYPE_MISMATCH(),
                sprintf("Flag '%s' did not resolve to a %s value", $flagKey, $expectedType),
            );
        }

        if ($narrow !== null) {
            $detail = new EvaluationDetail(
                $narrow($detail->value),
                $detail->reason,
                $detail->ruleId,
                $detail->variationKey,
                $detail->prerequisiteKey,
            );
        }

        return ResolutionMapping::toResolutionDetails($detail, $defaultValue);
    }
}
