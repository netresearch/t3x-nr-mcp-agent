.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

All notable changes to this extension are documented here.

The format follows `Keep a Changelog <https://keepachangelog.com/>`_ and
the project adheres to `Semantic Versioning <https://semver.org/>`_.

.. _version-0-1-0:

Version 0.1.0 (2026-03-24)
===========================

Initial alpha release.

Added
-----

- AI chat panel in the TYPO3 backend powered by ``netresearch/nr-llm``.
- Persistent conversation management: create, list, archive, pin conversations.
- Asynchronous processing via ``ai-chat:worker`` CLI command with
  atomic compare-and-swap queue dequeue.
- MCP (Model Context Protocol) integration via ``hn/typo3-mcp-server``:
  agent loop with tool call execution and resume support.
- File upload support (PDF, PNG, JPEG, WebP — max 20 MB) stored in
  FAL under per-user ``ai-chat/{uid}/`` folder; passed as multimodal
  content to the LLM provider.
- ``DocumentCapableInterface`` detection: PDF uploads only offered
  when the active provider advertises document support.
- Configurable access control: restrict chat to specific backend
  user groups.
- Extension configuration: LLM Task UID, max message length, max
  active conversations per user, MCP toggle.
- Lit-based web component frontend (``<nr-chat-app>``) with
  conversation list, message polling, file attachment UI.
- PHPStan Level 10, PHP-CS-Fixer, Rector, Infection mutation
  testing (≥70% MSI) — full CI pipeline on PHP 8.2–8.4 × TYPO3
  13.4/14.0 matrix.
- Architecture tests (phpat) enforcing domain/controller layer
  separation.
