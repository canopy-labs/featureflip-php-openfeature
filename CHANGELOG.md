# Changelog

## 0.2.1 — 2026-10-26

### Changed

- Requires `featureflip/featureflip-php` 3.7.0 or later, so the evaluation events behind every flag the provider resolves carry the evaluation reason (`rule-match`, `fallthrough`, `flag-disabled`, `prerequisite-failed`, `error` or `flag-not-found`) and the matched rule id. Featureflip's flag Analytics tab uses them to show which part of the targeting served the traffic. (#4268)

## 0.2.0 — 2026-10-20

### Added

- `FeatureflipProvider::create($sdkKey, $config)` builds the client and names the provider in the User-Agent: `featureflip-php/<version> featureflip-openfeature-php/<version>`, so Featureflip can count OpenFeature usage apart from direct SDK use. It works on a new `Config`, so the one you pass is unchanged, and a `wrapperName` you already set is kept. A client passed to the constructor is used as-is; give its `Config` `wrapperName: FeatureflipProvider::WRAPPER_NAME, wrapperVersion: FeatureflipProvider::VERSION` to have it counted. (#4152)

### Changed

- Requires `featureflip/featureflip-php` `^3.5`, the release that added `Config` `wrapperName`.

## 0.1.0

Initial release. OpenFeature provider backed by the Featureflip PHP SDK.

- Implements the five `resolve*Value` methods over `FeatureflipClient`.
- Maps Featureflip reasons onto OpenFeature reasons, surfacing
  `PREREQUISITE_FAILED` verbatim.
- Substitutes the caller's default on error reasons, per the OpenFeature error
  contract.
- Converts `DateTime` context attributes to ISO-8601 so they match date rules.
