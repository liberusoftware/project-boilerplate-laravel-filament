# Changelog

## 1.10.3 - 2026-11-14

- Discover tenant models from the running host application so team backups and imports retain model records when the package is linked or lives outside the host tree.
- Preserve package-relative discovery before Laravel binds its base path.
- Test concrete and inherited tenant models, duplicate paths, unscoped and abstract models, missing classes and invalid host bindings.

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

## 1.0.4 - 2026-08-01

- Add durable organizations, membership lifecycle, hardened invitations, context resolution, and ownership transfer.

## 1.0.0 - 2026-08-01

- Extract Jetstream-compatible team models, migrations, and policy.
