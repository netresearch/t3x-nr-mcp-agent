<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 -->

# AGENTS.md — .github/workflows/

## Overview

CI/CD is built from thin callers of shared reusable workflows (`netresearch/typo3-ci-workflows` and `netresearch/.github`). Most files here are governed by the `typo3-extension` template in `netresearch/.github/templates/` and drift-checked; per-extension values live only where `.github/template.yaml` declares intentional drift.

## Workflow files

| File | Role |
|------|------|
| `ci.yml` | Test matrix (PHP 8.2–8.4 × TYPO3 ^13.4, SQLite functionals) — **intentional drift**, customize here |
| `checks.yml` | Security/quality jobs — byte-identical to the template, do NOT edit in-repo |
| `check-template-drift.yml` | Fails CI when template-governed files diverge |
| `release.yml` | TER/release packaging — intentional drift (extension key etc.) |
| `mutation.yml` | Infection via the shared `php-mutation` reusable |
| `docs.yml` | docs.typo3.org render, runs on `Documentation/**` changes |
| `e2e.yml` | Playwright E2E — `workflow_dispatch` only, no automatic trigger |
| `harness-verify.yml` | Agent-harness consistency via `Build/Scripts/verify-harness.sh` |
| `dco.yml`, `labeler.yml`, `community.yml`, `auto-merge-deps.yml`, `pages.yml`, `ter-publish.yml` | Housekeeping/release plumbing |

## Running checks locally

- `bash Build/Scripts/verify-harness.sh` mirrors the harness-verify job (exit 2 = warnings only, passes in CI)
- `make ci` runs the same lint + phpstan + test + JS-test set the matrix runs; mutation and E2E are separate

## Conventions

- New jobs in `checks.yml` must also be added to `gate.needs` — the gate job is the only required context, a job missing there cannot block a merge
- Template-governed files are edited in `netresearch/.github/templates/typo3-extension/`, then synced — never patched locally (except the `intentional-drift` list in `template.yaml`)
- Every job declares explicit least-privilege `permissions:`; actions are SHA-pinned
- TYPO3 ^14 is excluded from the matrix until `saschaegerer/phpstan-typo3` and `typo3fluid/fluid` support it (comment in `ci.yml`)

## Security

- `checks.yml` carries the elevated-permission jobs (security-events etc.) and is drift-enforced precisely so those permissions cannot silently change
- Never add secrets to workflow files; pass them via `secrets:` from repo/org configuration

## Checklist

- [ ] Changed file is either intentional-drift or changed upstream in the template
- [ ] `gate.needs` updated when adding jobs to `checks.yml`
- [ ] Explicit `permissions:` block on every new job
- [ ] Root `AGENTS.md` updated when commands/CI behavior change (harness drift check)

## Examples

- Thin reusable caller pattern: `mutation.yml` (trigger + permissions + `uses:` + `with:`)
- Matrix configuration: `ci.yml` `with:` block

## When stuck

- Drift model documentation: `../template.yaml` (comment header)
- Reusable workflow sources: `netresearch/typo3-ci-workflows` and `netresearch/.github` on GitHub
- Repo-wide rules: `../../AGENTS.md`
