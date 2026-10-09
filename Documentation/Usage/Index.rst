.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

=====
Usage
=====

Opening the AI Chat module
==========================

Navigate to **Admin Tools > AI Chat** in the TYPO3
backend. The module is available to all backend users
who have access to the Admin Tools section (unless
restricted via the ``allowedGroups`` setting).

..  figure:: /Images/ChatModule.png
    :alt: AI Chat backend module
    :class: with-shadow

    The AI Chat module in the TYPO3 backend.

Sending messages
================

1.  Type your message in the input field at the bottom
    of the chat area.
2.  Press **Enter** or click the send button.
3.  The message is sent to the server and processing
    begins in the background.
4.  The interface polls for updates and displays the
    AI response when ready.

While the assistant is processing, you will see a loading
indicator. Processing typically takes a few seconds,
depending on the LLM provider and whether tools are
invoked.

The assistant may execute several tool calls (e.g. reading
page content, then creating a record) before responding.
Each tool call iteration is visible in the conversation.
Which tools it has comes from nr-llm — its builtin set plus
any MCP server registered under **AI > Operation > MCP
Servers**; this extension registers none of its own.

What the chat tells you besides the answer
------------------------------------------

*   **Nothing was saved in this step.** An answer that claims a
    finished change ("done", "created", "saved") carries this note when
    the run behind it wrote no record. Check the page before relying on
    the answer, and ask the assistant again.
*   **The AI provider is not set up.** Editors see this sentence when
    the provider cannot be used, for example because its API key is
    missing. Administrators see the technical message instead, with a
    button to the LLM providers or tasks module.
*   **This step is still waiting for a decision.** A message such as
    "weiter" or "habe alles freigegeben" while an approval card is on
    screen does not start a new run and does not approve anything:
    decide on the card. Without a card, the step is decided in AI Tasks;
    if you cannot open AI Tasks, ask someone who may approve there. If the
    step was already decided in AI Tasks, the chat says so and names
    the records the run wrote.

Approving a step in the chat
----------------------------

A step that writes data waits for your decision on a card in the chat.
The card is headed with what the step does, for example **Create page
draft** or **Delete page**, followed by what would change: where, the
current state, the new state and the consequences. The approve button
carries the same name, so it says what pressing it does; **Cancel**
leaves everything as it is. The name is the first line of the step's
preview when nr-llm marks that line as naming the change; otherwise it
is the tool's editor action label, and without one the step is headed
**Planned step** and approved with **Carry out step**. A turn with
several steps is approved as a whole with **Carry out all steps**.

The tool's internal name, its arguments and the record identifiers are
under **Show technical details**, which is closed until you open it.

The assistant also knows which tools exist but are not available to
you, and why — switched off, for administrators only, or not part of
this chat's configuration — so it can tell you to ask an administrator
instead of claiming that nothing can do what you asked.

..  figure:: /Images/MarkdownResponse.png
    :alt: AI response rendered as Markdown
    :class: with-shadow

    AI responses are rendered as rich Markdown — headings,
    lists, code blocks, and tables.

Editing a message
-----------------

Every message you wrote as text has an **Edit** control below it while no
answer is being generated. Change the text and choose **Save and run
again**: the message is replaced, everything after it is removed, and the
assistant answers the new wording. An attachment of the edited message
stays attached. :kbd:`Escape` leaves the editor without changes,
:kbd:`Ctrl+Enter` saves.

What the assistant knows about you
----------------------------------

*   **The page you are on.** When you send a message while a page module
    (Page, List, ...) shows a page, the assistant is told that page's uid
    and title and which module is open. "Summarise this page" therefore
    works without naming a uid. A page you may not see is never passed on.
*   **Your backend language.** The assistant answers in the language your
    backend is set to (*User settings > Language*), even when you write in
    another one. Ask for a different language in the message ("answer in
    English") and it uses that instead.

What the assistant is doing
---------------------------

The **Activity** button (a pulse line in the floating panel, *Activity* in
the module) lists the steps of the current turn while it runs: every call
to the model and every tool the assistant uses, with its duration, a
failed tool call marked as such, and — when a step needs your approval —
which tool is waiting. After you decide, your decision and the steps that
follow it are added to the same list. The list starts over with your next
message. It shows the names of the tools, not their arguments or results;
those are in the run's timeline under **AI > AI Tasks**.

In the expanded panel the list sits above the conversation; maximized, and
in the module, it is a sidebar on the right.

Instructions for a conversation
-------------------------------

The **Instructions** button (sliders icon in the floating panel) opens a
field for instructions that apply to this conversation only -- for
example "answer in bullet points" or "you are reviewing the press
section". They are added to the instructions an administrator configured
for the chat, which stay in force: where the two contradict each other,
the configured ones win. Your instructions take effect with the next
message. The assistant's identity, its tools and permissions, and the
language rules stay the same. The
button is highlighted while a conversation has instructions; empty the
field and save to remove them. Instructions cannot be changed while an
answer is being generated.

Conversation management
=======================

The sidebar shows your conversation history. Each
conversation has a title that is auto-generated from
the first message.

Starting a new conversation
---------------------------

Click the **New conversation** button to start a fresh
chat. The previous conversation remains in the sidebar
for later access.

Resuming a conversation
-----------------------

Click any conversation in the sidebar to resume it.
The full message history is loaded, and you can continue
where you left off.

Pinning conversations
---------------------

Pin important conversations to prevent them from being
auto-archived. Pinned conversations appear at the top
of the sidebar list.

Archiving conversations
-----------------------

Archive conversations you no longer need actively.
Archived conversations are hidden from the default
sidebar view but can still be accessed.

Conversations are also auto-archived after a
configurable period of inactivity (default: 30 days).

Exporting a conversation
------------------------

The **Export** button (a downward arrow in the floating panel) downloads the
open conversation as a Markdown file named after its title and the date. The
file holds what the chat shows as the conversation: your messages and the
assistant's answers with their time, and the name of any attached file. Tool
results and system notices are left out. The file is built in the browser
from the conversation already on screen; nothing is sent to the server for
it.

Attaching files
===============

A **+** button appears to the left of the input field whenever file
attachments are available.

1.  Click **+** to open the attachment menu.
2.  Select **Upload file** to open a file picker and choose a file from
    your computer.
3.  The selected file is uploaded immediately and shown as a badge above
    the input field (file name and size).
4.  Type your message and send — the file is included in the request.

To remove a pending attachment before sending, click the **×** on the
file badge.

..  figure:: /Images/FileAttachmentBadge.png
    :alt: File attachment badge above the chat input
    :class: with-shadow

    A selected file is shown as a badge above the input field.

**Supported file types:**

The following document formats are always available. Text is extracted
server-side before sending to the LLM:

*   PDF (``application/pdf``)
*   DOCX (``application/vnd.openxmlformats-officedocument.wordprocessingml.document``)
*   TXT (``text/plain``)
*   XLSX (``application/vnd.openxmlformats-officedocument.spreadsheetml.sheet``) --
    requires ``phpoffice/phpspreadsheet`` to be installed

Vision-capable providers (Claude, Gemini, GPT-4o, etc.) additionally
accept images:

*   PNG, JPEG, WebP

When the provider natively supports a document format (e.g. Claude
natively handles PDFs), the file is sent as-is instead of being
extracted. The file picker automatically restricts to the formats
supported by the active provider.

**Limits:**

*   Maximum 5 files per conversation.
*   Maximum file size: 20 MB per file.

If a file is not accepted (wrong type, too large, or upload error), an
error message is shown above the input. A file the logged-in user may not
store in the attachment folder is refused as well — the file mounts and
permissions that apply in the file module apply here too.

**What happens to an attached file:** it is not a throwaway copy. The
upload puts it straight into the file storage — ``fileadmin/ai-chat/``
by default, see :confval:`attachmentFolder` — where it is indexed like
any other file, and the assistant is told its file uid and path. So you
can ask it to reference the attachment from a content element in the same
breath as you attach it, without uploading the file a second time through
the file module, and you can ask it to describe the image for the
alternative text. Which of those it can actually carry out depends on the
file tools enabled in nr-llm, and every write waits for your approval.
Placing a file in a *different* folder afterwards is not something the
assistant can do — there is no tool for moving a file — so choose the
attachment folder to suit where those files belong.

Nothing is overwritten: a name already taken produces ``photo_01.jpg``,
and re-attaching a file that is byte-identical to the one already there
reuses it instead of making a copy.

Floating chat panel
===================

A chat button in the TYPO3 toolbar (top right, next to the search and
user menu) opens a floating bottom panel. The panel stays visible across
all module navigation -- you can chat with the AI while working in the
page tree, list module, or any other backend module.

..  figure:: /Images/ToolbarButton.png
    :alt: Chat toolbar button in the TYPO3 backend header
    :class: with-shadow

    The chat button in the TYPO3 toolbar. The badge shows the number of
    active (processing) conversations.

The panel has four states:

*   **Hidden** -- Only the toolbar button is visible.
*   **Collapsed** -- A minimal bar at the bottom showing the active
    conversation title.
*   **Expanded** -- Resizable panel with the full chat interface.
*   **Maximized** -- Full-height with conversation sidebar.

..  figure:: /Images/ChatPanel.png
    :alt: Floating chat panel in expanded state
    :class: with-shadow

    The floating panel in expanded state, overlaying the TYPO3 backend.
    Drag the top edge to resize.

In the expanded state the conversations sit in one row of tabs above the
chat. The row holds four of them: pinned conversations first, then the most
recently active ones, and always the one that is open. The others are
behind **More (n)** at the end of the row, which opens a list with a search
field: type to filter by title, move with the arrow keys, open one with
:kbd:`Enter`, close the list with :kbd:`Escape`. However many conversations
you keep, the row stays one line high and the chat keeps its space.
Maximized, the panel lists every conversation in the sidebar instead.

Panel height and state are stored in ``localStorage`` per user.

..  _usage-dashboard:

Dashboard widget
================

With the TYPO3 dashboard installed, an **AI Chat** widget can be added to a
dashboard (*Add widget > General*). It lists your five most recent chats;
a click opens one in the floating panel next to the dashboard, **New chat**
starts one there, and **Open the chat module** goes to the full-page view.
For a user the chat is not available to, the widget says so instead of
listing anything. Which groups may place the widget at all is set per
backend group under *Dashboard widgets*, as for every other widget.

AI assistant dashboard
----------------------

Editors can put together a dashboard for working with the assistant from
the widgets in the *AI Assistant* group (*Add widget > AI Assistant*), or
start from the **AI Assistant** preset when creating a dashboard, which
holds all of them and the **AI Chat** widget.

**Improve a page**
    Choose a page, then press the button of a guided tour (by default
    *Optimise SEO* and *Improve content*): the chat module opens and the
    assistant starts that tour on that page, in its default language. The
    page choice lists *Recommended* pages first, each with its reason, then
    the most recently changed pages. Recommended are, for every tour, the
    pages with open points of that tour, and for the SEO tour also the
    pages without a meta description, with a title shorter than 30
    characters, or without a social-media image (the SEO title and the
    social-media image are checked only with EXT:seo installed) — at most
    five, worked out from the stored pages, not by the assistant. Every page
    offered is one whose content the editor may edit: in their web mounts,
    with the *edit content* permission, not locked for editing, and in a
    language the editor may edit. Folders, links, shortcuts, spacers and
    translations are left out.

**Frequent tasks**
    Shortcuts into the chat, set by :confval:`dashboardQuickTasks` (none by
    default). A task that works on one page asks for the page first, from
    the same pages as *Improve a page*; any other task is a link.

**Recommended for your website**
    Open points: changes a guided tour proposed that an editor skipped,
    until a change to the same record and field is applied. Each comes with
    a link that starts the matching tour on its page, in the language the
    point is about. A point is shown only when the editor may access its
    page, read the changed record and field, and edit that language; a
    point whose skill no longer exists is not shown. Until open points are
    recorded, the widget says how they come about.

Only tours and tasks the chat can actually start are offered: a configured
skill that does not exist (yet), is not attached to the chat, or cannot run
on this installation is left out, and a widget left empty says that nothing
is set up yet. For a user the chat is not available to, each widget says so
instead.
Offering a page is not permission to change it: what the assistant does on
the page is checked again when it does it.

Error handling
==============

If a conversation fails (e.g. due to an LLM provider
error or timeout), an error message is displayed. You
can retry by sending a new message in the same
conversation -- the system will attempt to resume
processing.

Stuck conversations (processing for more than 5 minutes)
are automatically marked as failed by the cleanup
command.
