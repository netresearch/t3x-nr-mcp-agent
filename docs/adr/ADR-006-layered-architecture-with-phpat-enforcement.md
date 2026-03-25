# ADR-006: Layered Architecture with PHPAt Enforcement

**Status:** Accepted
**Date:** 2026-03-14

## Context

As the codebase grows, accidental dependency inversions (e.g. a Domain class importing a Controller) are easy to introduce and hard to spot in code review. PHP has no built-in module visibility; any class can import any other.

## Decision

Define explicit dependency rules between architectural layers and enforce them automatically via [PHPAt](https://github.com/carlosas/phpat) architecture tests, which run as part of the PHPStan pass in CI:

| Layer | May depend on | Must NOT depend on |
|---|---|---|
| `Domain` | — | `Controller`, `Command` |
| `Service` | `Domain` | `Controller` |
| `Controller` | `Domain`, `Service` | — |
| `Mcp` | `Domain`, `Service` | `Controller` |
| `Command` | `Domain`, `Service` | `Controller` |

Architecture tests live in `Tests/Architecture/LayerDependencyTest.php`.

## Consequences

- Violations are caught at CI time, not during code review.
- The architecture self-documents: the test file is the authoritative dependency map.
- PHPAt runs within the existing PHPStan pipeline — no additional CI step.
- Adding new layers or relaxing rules requires a deliberate test change, making architectural drift visible in pull requests.
