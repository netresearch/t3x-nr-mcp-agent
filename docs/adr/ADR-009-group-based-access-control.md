# ADR-009: Group-Based Access Control via Extension Configuration

**Status:** Accepted
**Date:** 2026-03-14

## Context

The AI chat must be restricted to authorized backend users. TYPO3 provides several access control mechanisms:

- **Backend User Permissions / Access Lists**: Fine-grained per-user or per-group permission records. Flexible, but requires administrators to configure individual permission records in the TYPO3 backend — significant overhead for a single on/off feature.
- **Module access via `allowed_modules`**: Controls which modules a group can see, but does not restrict API endpoints.
- **Custom group allowlist in extension configuration**: A comma-separated list of backend group UIDs in `ext_conf_template.txt`. Simple to configure, enforceable on both module and API layer.

## Decision

Use a `allowedGroups` extension configuration setting. If the list is empty, all authenticated backend users have access. If non-empty, only users belonging to one of the listed groups can access the chat module and API endpoints.

## Consequences

- Configuration is a single field in Admin Tools > Extension Configuration — no permission records to create.
- The check is applied uniformly in the API controller before any processing begins.
- Granularity is at the group level; per-user overrides require creating a dedicated group.
- Admin users (UID 0) bypass the check in line with TYPO3 conventions.
