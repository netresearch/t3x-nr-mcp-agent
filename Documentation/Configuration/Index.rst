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

Chat panel
==========

When ``llmTaskUid`` is configured, a chat button appears
automatically in the TYPO3 backend toolbar (top right).
Clicking it opens a floating bottom panel that stays visible
across module navigation.

The panel supports four states:

*   **Hidden** -- Default. Only the toolbar button is visible.
*   **Collapsed** -- Minimal header bar showing the active
    conversation title and status.
*   **Expanded** -- Resizable panel with chat messages, input,
    and a compact conversation switcher.
*   **Maximized** -- Full-height panel with a sidebar for
    conversation management (search, pin, archive).

The panel height and state are persisted per user in the
browser's localStorage.

System prompt
=============

The system prompt sent to the LLM is not configured in the
extension configuration itself, but in the **nr-llm records**:

**Configuration record** (``tx_nrllm_configuration.system_prompt``)
    The primary system prompt. Set this to define the AI
    assistant's persona, language, and behavior. Also use
    this field for instructions on how the tools registered
    in nr-llm are to be used.

    Example::

        Du bist ein TYPO3-Assistent.

        ## Tool-Nutzung
        - Vor jeder Aussage über den Seitenbaum erst
          get_pagetree aufrufen, nie aus dem Verlauf raten.
        - Datensatzfelder über read_records lesen, nicht aus
          einer früheren Antwort zitieren.
        - create_content_element_draft legt ein verstecktes
          Element an; sag danach, wo es liegt und dass es
          noch freigeschaltet werden muss.

    Name the tools your installation actually has --
    **AI > Operation > Tools** in nr-llm lists them, and
    **AI > Operation > MCP Servers** is where an external
    server's tools come from.

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

File attachments
================

File attachments are always available — no special provider configuration
required. Text is extracted server-side for document formats, so they
work with any LLM provider.

**Always supported (server-side text extraction):**

*   PDF: ``application/pdf`` — requires ``smalot/pdfparser`` (hard dependency)
*   DOCX: ``application/vnd.openxmlformats-officedocument.wordprocessingml.document``
    — requires ``phpoffice/phpword`` (hard dependency)
*   TXT: ``text/plain`` — no dependencies
*   XLSX: ``application/vnd.openxmlformats-officedocument.spreadsheetml.sheet``
    — requires ``phpoffice/phpspreadsheet`` (optional; install via
    ``composer require phpoffice/phpspreadsheet:^3.0``)

**Additionally available for vision-capable providers** (Claude 3+, Gemini,
GPT-4o, etc.):

*   Images: ``image/png``, ``image/jpeg``, ``image/webp``

When the provider natively handles a document format (e.g. Claude
natively processes PDFs via ``DocumentCapableInterface``), the file is
sent as binary instead of being extracted. The file picker automatically
restricts to formats the active provider can process.

**Storage:** An uploaded file is a managed TYPO3 file from the moment it
arrives: it is written into the default storage, indexed in ``sys_file``
and given a ``sys_file_metadata`` record, like any other file in the file
module. It is then read at LLM call time and sent as Base64-encoded
multimodal content, and the assistant is told its ``sys_file`` uid and
path — so it can reference the attachment from a content element with
nr-llm's file tools instead of asking for it to be uploaded again.

Nothing is ever overwritten or deleted. A name already taken in the folder
yields ``report_01.pdf``; if the file of that name has identical content,
the existing one is returned instead of a copy. Where the user may not
write, the upload is refused with ``403`` rather than failing as a server
error — the file mounts and permissions of the logged-in backend user
apply throughout.

..  confval:: attachmentFolder
    :type: string
    :default: ai-chat

    Folder for chat attachments in the default storage, relative to its
    root — ``ai-chat`` means ``fileadmin/ai-chat/``. A per-user subfolder
    (``<be_user_uid>``) is created inside it and is not configurable: it
    is what keeps one user's attachments out of another's. An empty value
    falls back to the default rather than writing into the storage root.

**Limits:**

*   Maximum 5 files per conversation.
*   Maximum file size: 20 MB per file.
*   File count is enforced both in the frontend (before upload) and in the
    backend API.

**Security:** Decide deliberately whether the attachment folder is
publicly readable, because the two things it is used for pull in opposite
directions.

An attachment is material a backend user hands to the assistant, and it is
stored under a path that is guessable by name. Where attachments are only
ever read by the assistant, deny direct HTTP access to the folder:

..  code-block:: apache

    # fileadmin/ai-chat/.htaccess
    Require all denied

Where editors are meant to place an attached image or PDF into a content
element — the file is already in FAL, so this needs no second upload — the
folder must stay publicly readable, or the reference renders as a broken
image in the frontend. Denying access to the folder and referencing files
out of it is a contradiction, not a hardened setup. Point
:confval:`attachmentFolder` at a folder your editors publish from in that
case, and treat what is uploaded through the chat as publishable.

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
deletion of old data. A conversation whose worker never
finished stays in *processing* until this command marks it
failed after five minutes — without the schedule it can stay
there indefinitely:

..  code-block:: bash

    # Run cleanup daily at 3:00 AM
    0 3 * * * /var/www/html/vendor/bin/typo3 \
        ai-chat:cleanup --delete-after-days=90
