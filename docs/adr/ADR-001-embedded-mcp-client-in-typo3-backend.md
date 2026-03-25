# ADR-001: Embedded MCP Client in TYPO3 Backend

**Status:** Accepted
**Date:** 2026-03-14

## Context

`hn/typo3-mcp-server` exposes TYPO3 content operations (pages, records, content elements) as MCP tools. Any MCP-capable AI client — Claude Desktop, Cursor, or similar — can connect to it and manage TYPO3 content through natural language.

The problem: those clients are external applications. Every time an editor wants AI assistance while working in the TYPO3 backend, they must leave TYPO3, switch to the external client, issue their request, switch back to TYPO3, and verify the result. For content workflows this context-switching is constant and disruptive.

## Decision

Build the MCP client and AI chat interface directly into the TYPO3 backend as a native extension (`nr_mcp_agent`). Editors interact with the AI without leaving TYPO3.

## Consequences

- Editors can request content changes and verify results without switching applications.
- The extension must manage the full agent loop (LLM calls, MCP tool execution, conversation state) that external clients handle out of the box.
- All subsequent architectural decisions (CLI processing, stdio MCP transport, Lit UI, conversation persistence) are consequences of this integration choice.
- The project is scoped as a proof of concept: the goal is to demonstrate that this integration is feasible and to gather feedback, not to deliver a production-ready product.
