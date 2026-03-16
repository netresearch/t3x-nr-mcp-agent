..  include:: /Includes.rst.txt

=============
Configuration
=============

All settings are managed via **Admin Tools > Settings >
Extension Configuration > nr_mcp_agent**.

LLM connection
==============

..  confval:: llmTaskUid
    :type: int
    :default: 0

    UID of an nr-llm Task record. This Task defines which
    LLM provider and model to use (e.g. OpenAI GPT-4,
    Anthropic Claude). **Required** -- the extension will
    not work without a valid Task UID.

    Create the Task record in the nr-llm backend module
    first, then enter its UID here.

Processing
==========

..  confval:: processingStrategy
    :type: string
    :default: exec

    How chat messages are processed in the background:

    ``exec``
        Forks a CLI process per request
        (``ai-chat:process``). Simple, no extra setup.
        Best for development and low-traffic sites.

    ``worker``
        Uses a long-running worker process
        (``ai-chat:worker``) that polls for new messages.
        Better for production -- lower latency, no
        process forking overhead.

Access control
==============

..  confval:: allowedGroups
    :type: string
    :default: *(empty)*

    Comma-separated list of backend user group UIDs that
    are allowed to use the AI Chat module. Leave empty
    to allow all backend users with module access.

MCP integration
===============

..  confval:: enableMcp
    :type: boolean
    :default: false

    Enable MCP (Model Context Protocol) server
    integration. Requires `hn/typo3-mcp-server` to be
    installed.

    When enabled, the AI assistant can use TYPO3 content
    management tools (create pages, edit records, etc.).
    When disabled, it works as a plain chat without
    tool access.

..  confval:: mcpServerCommand
    :type: string
    :default: *(empty, auto-detected)*

    Path to the MCP server binary. When empty, defaults
    to ``vendor/bin/typo3`` in the project root.

    Override this if your TYPO3 CLI binary is at a custom
    path.

..  confval:: mcpServerArgs
    :type: string
    :default: *(empty, auto-detected)*

    Comma-separated arguments passed to the MCP server
    command. When empty, defaults to ``mcp:server``.

    Example: ``mcp:server,--verbose``

System prompt
=============

The system prompt sent to the LLM is not configured in the
extension configuration itself, but in the **nr-llm records**:

**Configuration record** (``tx_nrllm_configuration.system_prompt``)
    The primary system prompt. Set this to define the AI
    assistant's persona, language, and behavior. Also use
    this field for tool usage instructions when MCP is
    enabled.

    Example for MCP usage::

        Du bist ein TYPO3-Assistent.

        ## Tool-Nutzung
        - Bei WriteTable gehören Record-Felder IMMER in den
          "data" Parameter als Objekt.
        - Beispiel: {"action": "create", "table": "pages",
          "pid": 1, "data": {"title": "Meine Seite"}}

**Task record** (``tx_nrllm_task.prompt_template``)
    Additional instructions appended after the Configuration
    prompt. Use this for task-specific context.

When both fields are set, they are combined (separated by a
blank line). If neither is set, a locale-based default prompt
is used. A per-conversation ``system_prompt`` field can
override everything (set programmatically, not via UI).

User interface
==============

..  confval:: maxConversationsPerUser
    :type: int
    :default: 50

    Maximum number of conversations to keep per user.
    Set to ``0`` for unlimited. When the limit is reached,
    the oldest non-pinned conversations are archived
    automatically.

..  confval:: autoArchiveDays
    :type: int
    :default: 30

    Automatically archive conversations that have been
    inactive for this many days. Set to ``0`` to disable
    auto-archiving.

    Auto-archiving runs via the ``ai-chat:cleanup``
    command.

Security
========

..  confval:: maxMessageLength
    :type: int
    :default: 10000

    Maximum length of a single user message in characters.
    Set to ``0`` for unlimited (not recommended).

    Messages exceeding this limit are rejected with an
    error.

..  confval:: maxActiveConversationsPerUser
    :type: int
    :default: 3

    Maximum number of simultaneously active (processing)
    conversations per user. Prevents a single user from
    overloading the system. Set to ``0`` for unlimited.

Worker mode production setup
============================

For production use with ``processingStrategy = worker``,
set up a systemd service to keep the worker running:

..  code-block:: ini

    # /etc/systemd/system/typo3-ai-chat-worker.service
    [Unit]
    Description=TYPO3 AI Chat Worker
    After=mysql.service

    [Service]
    Type=simple
    User=www-data
    Group=www-data
    WorkingDirectory=/var/www/html
    ExecStart=/var/www/html/vendor/bin/typo3 \
        ai-chat:worker --poll-interval=200
    Restart=always
    RestartSec=5

    [Install]
    WantedBy=multi-user.target

Enable and start the service:

..  code-block:: bash

    sudo systemctl daemon-reload
    sudo systemctl enable typo3-ai-chat-worker
    sudo systemctl start typo3-ai-chat-worker

Scheduled cleanup
=================

Add the cleanup command to your cron or TYPO3 scheduler
to handle stuck conversations, auto-archiving, and
deletion of old data:

..  code-block:: bash

    # Run cleanup daily at 3:00 AM
    0 3 * * * /var/www/html/vendor/bin/typo3 \
        ai-chat:cleanup --delete-after-days=90
