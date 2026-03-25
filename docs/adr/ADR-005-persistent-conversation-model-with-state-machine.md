# ADR-005: Persistent Conversation Model with State Machine

**Status:** Accepted
**Date:** 2026-03-14

## Context

AI chat sessions consist of multiple messages exchanged over time. Users may close the browser, navigate away, or return to a conversation hours or days later. Processing happens asynchronously in a CLI subprocess (see ADR-002), so the web request and the processing job do not share memory.

Alternatives considered:
- **PHP session storage**: Does not survive browser close or server restarts; not accessible from CLI.
- **Stateless (no history)**: Each message would be processed without context; unusable for multi-turn conversations.
- **Database persistence**: Survives restarts, accessible from both web and CLI, queryable.

## Decision

Persist conversations and messages in dedicated database tables (`tx_nrmcpagent_conversation`, `tx_nrmcpagent_message`). Messages use a status state machine:

`pending` → `processing` → `done` | `error`

Status transitions use atomic compare-and-swap (CAS) queries to prevent race conditions between concurrent CLI workers.

Conversation lifecycle is managed by the extension: users can pin conversations, and inactive conversations are auto-archived after a configurable number of days.

## Consequences

- Conversations survive browser close, server restarts, and CLI worker restarts.
- The browser polls the message status via a lightweight AJAX endpoint (see ADR-007).
- CAS updates prevent double-processing in `worker` mode.
- Auto-archive and cleanup commands (`ai-chat:cleanup`) keep the table size bounded.
- Per-user concurrency caps (`maxActiveConversationsPerUser`) are enforceable via database queries.
