# Changelog

## 1.6.2 - 2026-11-13

- Consolidate legacy middleware aliases, the API rate limiter, and transactional reservations from the host copies.
- Scope billing replays by team and release reservations after failed or streamed requests.
- Preserve legacy replay headers and enforce 100% source coverage across all implementations.

## 1.6.1 - 2026-11-13

- Cover token scope and expiry policy, idempotency replay, expiry, isolation, and insert races.
- Verify conditional API reads and writes, problem responses, and failure cleanup.
- Handle authenticated actors without an optional current team safely.
- Enforce 100% source line coverage in CI and `composer test:coverage`.

## 1.2.2 - 2026-08-06

- Split CI into the three workflows `CONFORMANCE.md` §3.9 asks for, rather than one calling all
  three. Only `tests` runs on every push; installing from nothing and resolving the lowest allowed
  dependencies are release questions and now run on tags. One caller meant four jobs per push, and
  a 44-repository publish sweep stalled the organisation's Actions queue.

## 1.2.0 - 2026-08-06

- Move the boundary suites to `liberusoftware/package-testbench`: this repository no longer
  ships a test bootstrap or its own metadata and provider tests, and its `phpunit.xml` points
  at the shipped suite instead. A new boundary rule is now a testbench release rather than a
  change made by hand in every package repository.
- Call the reusable workflows in `liberusoftware/.github` rather than restating CI setup.

## 1.1.0 - 2026-08-05

- Conformance wave: package and namespace renames, manifest categories and `default_enabled`
  settled against `liberusoftware/documentation`. See the host's `docs/CONFORMANCE.md` §3.

## 1.0.4 - 2026-08-01

- Add a validated, machine-readable feature catalog for runtime discovery and diagnostics.
- Add standalone tests that keep feature metadata consistent and unique.

## 1.0.0 - 2026-08-01

- Adopt Sanctum token schema and add token/idempotency policy.
