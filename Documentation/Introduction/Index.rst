..  include:: /Includes.rst.txt

============
Introduction
============

..  note::

    **Proof of concept.** This extension explores a concrete question: is
    agent-like behavior possible within the TYPO3 backend? It is not
    intended to answer whether this is the right architectural approach —
    the space is moving fast, and the tradeoffs between MCP, tool-calling,
    browser-side agents, and custom integrations are far from settled. The
    goal here is to show that it *works*, and to invite feedback from
    anyone thinking about the same problem. If you have thoughts,
    `open an issue <https://github.com/netresearch/t3x-nr-mcp-agent/issues>`__.

What does it do?
================

AI Chat adds a backend module to TYPO3 that lets
administrators and editors interact with an AI assistant
directly from the TYPO3 backend. The module is available
under **Admin Tools > AI Chat**.

Through the Model Context Protocol (MCP), the assistant can
read and modify TYPO3 content -- pages, content elements,
records -- using natural language instructions. All
processing happens server-side via CLI commands, keeping the
web server responsive.

Key features
============

..  card-grid::
    :columns: 2

    ..  card:: Integrated chat module

        A dedicated backend module under Admin Tools
        with a modern chat interface. Send messages,
        view responses, and manage conversations without
        leaving TYPO3.

    ..  card:: Content management via MCP

        Connect to hn/typo3-mcp-server to give the AI
        access to TYPO3 content operations -- creating
        pages, editing records, reading site structure,
        and more.

    ..  card:: Conversation history

        Conversations are persisted in the database.
        Resume previous chats, pin important ones, or
        let the system auto-archive inactive
        conversations.

    ..  card:: Floating chat panel

        A toolbar button opens a resizable bottom panel
        that stays visible across all module navigation.
        Chat while working in the page tree without
        switching context.

    ..  card:: File attachments

        Attach PDF, DOCX, TXT, and XLSX files to your
        messages. Text is extracted server-side when
        needed, so all formats work regardless of the
        LLM provider. Vision-capable providers also
        accept images (PNG, JPEG, WebP).

    ..  card:: Markdown rendering

        AI responses are rendered as rich Markdown --
        headings, lists, code blocks, and tables --
        using marked.js with DOMPurify for XSS safety.

    ..  card:: Secure by design

        Access is restricted to configured backend user
        groups. Messages are length-limited, concurrent
        conversations are capped, and API keys are never
        exposed to the browser.

Example interactions
====================

Once configured with MCP enabled, you can ask the assistant
to perform tasks like:

*   "Show me all pages under the homepage"
*   "Create a new text content element on page 42 with
    the heading 'Welcome'"
*   "What content elements exist on page 15?"
*   "Move the news page to be a subpage of 'About Us'"
*   "List all hidden pages in the site"

Without MCP, the assistant works as a general-purpose
AI chat (using the configured LLM provider from nr-llm),
but cannot interact with TYPO3 content.

Acknowledgments
===============

This extension builds on the work of others:

`hauptsache.net <https://hauptsache.net/>`__
    For creating `hn/typo3-mcp-server
    <https://github.com/hauptsache-net/typo3-mcp-server>`__,
    the MCP server that exposes TYPO3 content operations
    as tools.

`nr-llm <https://github.com/netresearch/t3x-nr-llm>`__
    The Netresearch LLM abstraction layer for TYPO3 that
    provides provider-agnostic access to language models.

`nr-vault <https://github.com/netresearch/t3x-nr-vault>`__
    Secure credential storage for TYPO3, used to protect
    API keys for LLM providers.
