# Featureflip OpenFeature Provider (PHP)

[OpenFeature](https://openfeature.dev/) provider for
[Featureflip](https://featureflip.io), backed by the Featureflip PHP server
SDK. Use the vendor-neutral OpenFeature API while Featureflip serves your
flags.

## Installation

```bash
composer require featureflip/featureflip-openfeature
```

Composer resolves the latest compatible release, so no version is pinned here.

## Quickstart

Unlike the Java, .NET and Go providers, there is no SDK-key constructor —
build a `FeatureflipClient` yourself and pass it in:

```php
use Featureflip\FeatureflipClient;
use Featureflip\Config;
use Featureflip\OpenFeature\FeatureflipProvider;
use OpenFeature\OpenFeatureAPI;

$client = FeatureflipClient::get('your-server-sdk-key', new Config(
    cache: $psrCache,
    httpClient: $psrHttpClient,
    requestFactory: $psrRequestFactory,
    streamFactory: $psrStreamFactory,
));

OpenFeatureAPI::getInstance()->setProvider(new FeatureflipProvider($client));

$enabled = OpenFeatureAPI::getInstance()
    ->getClient()
    ->getBooleanValue('new-checkout', false, $context);
```

The PHP SDK's core requires caller-supplied PSR-18, PSR-17 and PSR-16
implementations and throws without them, so a key-only constructor would have
to choose an HTTP client and a cache on the application's behalf. Passing the
client in is also the idiomatic PHP shape — it is a service-container object,
and an application evaluating flags through both the SDK directly and through
OpenFeature should share one.

To let the provider build the client from that same `Config` instead, use
`FeatureflipProvider::create('your-server-sdk-key', $config)`. Requests then
carry `featureflip-openfeature-php/<version>` after the SDK's own User-Agent
token, which is how Featureflip counts OpenFeature usage. A client you build
yourself is sent without it unless its `Config` names the provider:
`wrapperName: FeatureflipProvider::WRAPPER_NAME, wrapperVersion: FeatureflipProvider::VERSION`.

The provider never closes the client it is given: the caller owns it and is
responsible for calling `$client->close()` at shutdown.

## Context mapping

| OpenFeature context | Featureflip context |
|---|---|
| `targetingKey` | both `user_id` and `userId` (the latter is the rollout bucketing field) |
| Any other attribute | Passed through unchanged |
| A `DateTime`-valued attribute | Rendered as ISO-8601 |

The targeting key is written under both spellings because the Featureflip PHP
SDK resolves a condition's attribute by exact key, with no aliasing between
`user_id` and `userId` — writing both is what lets a targeting rule against
either spelling resolve. Both hold the same string, so a percentage rollout
still has exactly one bucketing identity.

An explicit `userId` or `user_id` attribute takes precedence over
`targetingKey` and is passed through exactly as you supplied it, with no alias
added: the caller naming one is a stronger signal than the framework supplying
the other. `targetingKey` itself is never copied into the attribute bag under
its own name.

OpenFeature's `Attributes` bag may hold a `DateTime`, which no other SDK's
context type can. Every SDK gates its date-comparison rules on the ISO
grammar, so a `DateTime` left as an object would stringify unpredictably (or
not at all) and silently match no date rule — the provider converts it for
you, recursing into nested arrays.

## Evaluation reasons

| Featureflip reason | OpenFeature reason |
|---|---|
| `RULE_MATCH` | `TARGETING_MATCH` |
| `FALLTHROUGH` | `DEFAULT` |
| `FLAG_DISABLED` | `DISABLED` |
| `PREREQUISITE_FAILED` | `PREREQUISITE_FAILED` (custom) |
| `FLAG_NOT_FOUND` | `ERROR` + `FLAG_NOT_FOUND` |
| `ERROR` | `ERROR` + `GENERAL` |

No standard OpenFeature reason models an unmet prerequisite. Reasons are open
strings, so the provider surfaces `PREREQUISITE_FAILED` verbatim rather than
mislabelling it as `DEFAULT` or `DISABLED`.

On an error reason the provider returns **your default value**, never the
flag's off-variation value — that value can be wrong-typed (a prerequisite
chain past the depth limit, or an errored prerequisite), and the caller's
default is what the OpenFeature error contract prescribes.

## Type handling

Featureflip does not type-check a flag's value against the accessor you call,
so the provider enforces OpenFeature's `TYPE_MISMATCH` contract itself. A
mismatched read returns your default with reason `ERROR` and error code
`TYPE_MISMATCH`.

| Accessor | Accepts |
|---|---|
| `resolveBooleanValue` | booleans only |
| `resolveStringValue` | strings only |
| `resolveIntegerValue` | integers, and whole-number floats (`1.0`) |
| `resolveFloatValue` | any JSON number, integers included |
| `resolveObjectValue` | objects and arrays only — not strings |

A whole-number float satisfying an integer read is narrowed to a real `int`
before being returned. Without that narrowing the OpenFeature client's own
`ValueTypeValidator` rejects the float, and the client hands you your default
value back with reason `ERROR` and error code `GENERAL` — a silent wrong
answer rather than a loud failure, which is why the narrowing is required
rather than cosmetic.

## Limitations

- **No provider events.** The `open-feature/sdk` PHP package has no provider
  events API at all (no `PROVIDER_READY`/`PROVIDER_CONFIGURATION_CHANGED`
  equivalent to subscribe to, unlike the Node and Python providers). Flag
  changes are therefore picked up passively, on the underlying SDK's poll
  interval, rather than pushed to your application — there is nothing to add a
  listener to.
- **No flag metadata at this version.** The released `open-feature/sdk` (^2.3,
  currently 2.3.0) has no flag-metadata API — `ResolutionDetailsBuilder` has no
  `withMetadata()`. `ruleId` and `prerequisiteKey` are therefore not exposed;
  they will be added once the floor moves to a release that supports it.

## License

Apache-2.0
