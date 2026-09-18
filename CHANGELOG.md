# Changelog

## 0.1.0

Initial release. OpenFeature provider backed by the Featureflip PHP SDK.

- Implements the five `resolve*Value` methods over `FeatureflipClient`.
- Maps Featureflip reasons onto OpenFeature reasons, surfacing
  `PREREQUISITE_FAILED` verbatim.
- Substitutes the caller's default on error reasons, per the OpenFeature error
  contract.
- Converts `DateTime` context attributes to ISO-8601 so they match date rules.
