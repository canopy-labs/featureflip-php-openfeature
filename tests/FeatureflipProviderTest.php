<?php

declare(strict_types=1);

namespace Featureflip\OpenFeature\Tests;

use Featureflip\EvaluationEvent;
use Featureflip\FeatureflipClient;
use Featureflip\OpenFeature\FeatureflipProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use PHPUnit\Framework\TestCase;

final class FeatureflipProviderTest extends TestCase
{
    /** @param array<string, mixed> $flags */
    private function provider(array $flags): FeatureflipProvider
    {
        return new FeatureflipProvider(FeatureflipClient::forTesting($flags));
    }

    public function testMetadataNamesTheProvider(): void
    {
        $this->assertSame('featureflip-php', $this->provider([])->getMetadata()->getName());
    }

    public function testResolvesABooleanFlag(): void
    {
        $details = $this->provider(['new-checkout' => true])
            ->resolveBooleanValue('new-checkout', false);

        $this->assertTrue($details->getValue());
        $this->assertSame(Reason::DEFAULT, $details->getReason());
        $this->assertNull($details->getError());
    }

    public function testResolvesAStringFlag(): void
    {
        $details = $this->provider(['theme' => 'dark'])->resolveStringValue('theme', 'light');

        $this->assertSame('dark', $details->getValue());
    }

    public function testResolvesAnObjectFlag(): void
    {
        $details = $this->provider(['limits' => ['max' => 10]])
            ->resolveObjectValue('limits', []);

        $this->assertSame(['max' => 10], $details->getValue());
    }

    public function testMissingFlagReturnsFlagNotFoundWithTheCallerDefault(): void
    {
        $details = $this->provider([])->resolveBooleanValue('absent', true);

        $this->assertTrue($details->getValue());
        $this->assertSame(Reason::ERROR, $details->getReason());
        $this->assertTrue(
            ErrorCode::FLAG_NOT_FOUND()->equals($details->getError()?->getResolutionErrorCode()),
        );
    }

    public function testWrongTypeIsATypeMismatchCarryingTheCallerDefault(): void
    {
        $details = $this->provider(['theme' => 'dark'])->resolveBooleanValue('theme', false);

        $this->assertFalse($details->getValue());
        $this->assertSame(Reason::ERROR, $details->getReason());
        $this->assertTrue(
            ErrorCode::TYPE_MISMATCH()->equals($details->getError()?->getResolutionErrorCode()),
        );
    }

    public function testWholeFloatSatisfiesAnIntegerReadAndComesBackAsAnInt(): void
    {
        // A JSON-sourced integer flag can arrive as 1.0. OpenFeatureClient runs
        // a ValueTypeValidator over the resolved value, so returning the float
        // unchanged would fail it and the client would hand the caller its
        // default back with reason ERROR / code GENERAL — silently.
        $details = $this->provider(['retries' => 3.0])->resolveIntegerValue('retries', 0);

        $this->assertSame(3, $details->getValue());
    }

    public function testFractionalFloatIsATypeMismatchForAnIntegerRead(): void
    {
        $details = $this->provider(['ratio' => 1.5])->resolveIntegerValue('ratio', 0);

        $this->assertSame(0, $details->getValue());
        $this->assertTrue(
            ErrorCode::TYPE_MISMATCH()->equals($details->getError()?->getResolutionErrorCode()),
        );
    }

    public function testIntegralValueSatisfiesAFloatRead(): void
    {
        $details = $this->provider(['ratio' => 2])->resolveFloatValue('ratio', 0.0);

        $this->assertSame(2.0, $details->getValue());
    }

    public function testContextReachesTheClient(): void
    {
        // forTesting()'s in-memory flags ignore the context, so asserting on
        // the resolved value proves nothing — it would be true however the
        // provider mangled the context. Observe the array the client was
        // actually handed, via the inspector hook variationDetail() fires.
        $seen = [];
        $client = FeatureflipClient::forTesting(
            ['x' => true],
            [
                static function (EvaluationEvent $event) use (&$seen): void {
                    $seen[] = $event->context;
                },
            ],
        );

        $context = new EvaluationContext('user-123', new Attributes(['plan' => 'pro']));

        (new FeatureflipProvider($client))->resolveBooleanValue('x', false, $context);

        $this->assertCount(1, $seen);
        $this->assertSame(
            ['plan' => 'pro', 'userId' => 'user-123', 'user_id' => 'user-123'],
            $seen[0],
        );
    }

    public function testClosedClientFailsSafeRatherThanThrowing(): void
    {
        $client = FeatureflipClient::forTesting(['x' => true]);
        $client->close();

        $details = (new FeatureflipProvider($client))->resolveBooleanValue('x', false);

        $this->assertFalse($details->getValue());
        $this->assertSame(Reason::ERROR, $details->getReason());
    }
}
