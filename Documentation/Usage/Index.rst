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

Answering a question in the chat
--------------------------------

A step can stop and ask you something before it goes on, for example
which point of a page it should start with. A proposed change is never
asked this way: it waits on an approval card. The question appears as the
assistant's message, and the possible answers appear as buttons directly
above the input field. Press one to answer; the step continues with your
answer, and the answer stays in the conversation as your message.

When the question allows an answer in your own words, the input field says
**Write your own answer** and what you send is the answer. Otherwise a
message you send ends the question and starts a new request. A question
with several fields is shown as a small form with a **Send answer**
button. If the chat cannot show a question, it says so.

When the question changed after it was shown, or your answer could not be
taken, the question comes back with the reason above the buttons; answer
again.

.. _usage-approving-a-step:

Approving a step in the chat
----------------------------

A step that writes data waits for your decision on a card in the chat.
The card is headed with what the step does, for example **Create page
draft** or **Delete page**, followed by what would change: where, the
current state, the new state and the consequences. The approve button
carries the same name, so it says what pressing it does. **Cancel** leaves
everything as it is. When a guided process proposes a change, two buttons
take the place of **Cancel** and tell the assistant why the change is not
taken: **Another variant** asks for a different proposal, **Keep** leaves
the current content as it is and closes the open point, **Skip** moves on
without this change and keeps it open; your choice stays in the
conversation as your message. In a guided process, a message of your own while a proposal or a
question waits cancels that step. The name is the first line of the step's
preview when nr-llm marks that line as naming the change; otherwise it
is the tool's editor action label, and without one the step is headed
**Planned step** and approved with **Carry out step**. A turn with
several steps is approved as a whole with **Carry out all steps**.

Administrators and users of **AI > AI Tasks** decide every approval. An
editor whose backend group holds **Approve own changes in the chat** (under
*Custom module options*) decides the approvals of their own conversations,
and may approve a change only when the card shows its preview — the preview
is built with the editor's own rights. Where the AI configuration requires a
second approver, the card offers only the denial and a colleague approves
the step in AI Tasks.

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

.. _usage-context-chips:

What the chat is about: the chips above the input
-------------------------------------------------

When the chat is about one page, a row of chips above the input says which:

*   **The page chip** (grey, with a page icon) shows the title of the page.
    Beside the page module (*Layout*) it is the page selected in the page
    tree, and it changes as soon as you select another one. In a guided
    process it is the process's page, which stays when the page tree moves
    on. Only a page you may see is named.
*   **The element chip** (white, with a black circle and a yellow dot)
    appears in a guided process when the current point is about a content
    element, and changes with the point; a point about no element shows the
    page chip only. Beside the page module, pointing at
    it or focusing it with the keyboard marks the element in the page
    layout, and a click scrolls it into view. In the full-page chat module,
    in the popped-out panel and while the page module shows another page,
    the chip is only a label.
*   **A page property** such as the meta description gets the same chip
    when the current point is about it, named by the field's label as in
    the page properties. The page module has no element for it, so this
    chip is always only a label, and an element marked in an earlier point
    is no longer marked.
*   **The (i)** at the end of the row says what the assistant may change,
    for example "Bereich: ganze Seite „AI Chat Agent“. Geändert wird nur
    nach deiner Bestätigung." or, for a page property, "Der Assistant
    ändert nur die Seiteneigenschaft „…“ der Seite „AI Chat Agent“." It opens
    on pointing at it and on keyboard focus; Escape closes it.

A long title is shortened in the chip; the full title is in the chip's
tooltip and in the text of the (i). There is no row for a skill that works
on no page (:ref:`usage-skill-page`), for a chat without a page, or while no
page is selected. A conversation about a missing alt text shows its image
and the sentence on what may change in the same row.

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

Starting with a page and a skill
--------------------------------

A link into the chat module can start a conversation about one page:
``&pageUid=<page>``, optionally ``&languageUid=<language>`` and
``&skill=<skill>``. The chat checks that you may open the page and edit the
language, opens the new conversation and replaces the parameters with the
conversation's own, so reloading opens it again.

Typing ``/`` at the start of the input lists the skills available in the
chat. Pick one with the arrow keys and Enter, or with the mouse; nothing is
sent. The conversation's skill is shown above the input, and the ``×``
beside it removes it; in a guided process about a page this ends the
process (see below). Every later answer in the conversation uses it.

When the chat's AI configuration needs a second person to approve every
change, no skill can be started, and the chat says so: a guided process
is decided on the chat's own approval card, where you cannot release
your own change.

.. _usage-skill-page:

Whether a skill works on a page
-------------------------------

A skill states in its frontmatter whether it works on a page (the ``page``
key of nr-llm, :ref:`adr-025`):

``page: required``
    The conversation is about one page. Without one, the chat asks for it:
    for a guided process, choose the page in the page tree and confirm it
    with **Diese Seite prüfen**; for any other skill, choose the page and
    then write what to do -- your message carries the page. Once set, the
    page stays, whatever page the page module shows later; **Zur neuen
    Seite wechseln** opens a new conversation on the other page.
``page: optional``
    The chat passes along the page the page module shows with each message,
    when there is one; it does not ask for one.
``page: none``
    The conversation is about no page. The chat does not ask for one, does
    not pass one along and does not keep one, even when the conversation is
    started with a page.

A skill without the key behaves as before: a guided process as
``required``, any other skill as ``optional``. The *Frequent tasks* widget
follows the same declaration (:confval:`dashboardQuickTasks`).

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

Guided processes
----------------

A skill can walk you through a page one point at a time. While it does:

*   **The header shows where it stands** -- for example
    "Über uns · Deutsch · Punkt 2 von 5": the page and its language from
    the conversation, then the point. When space is short the page name is
    shortened first; the point is always shown in full. Before the first
    point the header says "Analyse läuft". It changes when the assistant
    stops to ask you or to propose a change, not while it works.
*   **The element is highlighted.** With the floating panel open next to
    the page module, the content element the current point is about gets a
    frame and the label "Betrifft den aktuellen Vorschlag", and is scrolled
    into view; a screen reader hears which element is marked. The full-page
    chat module and the popped-out panel have no page module beside them and
    only show the progress. A point about a page property, such as the meta description,
    marks nothing in the page module; the chip above the input names it.
*   **Each proposal shows its outcome.** A proposal is shown as a block with
    what is affected and, where nr-llm provides it, the current and the
    proposed value with its length. After you answer "Übernehmen", the chat
    says itself whether the change was saved, saved but worth checking, or
    not saved. At the end it summarises what was applied, what to check and
    what you skipped.
*   **The end is shown.** When the process is finished, the header says
    so and the highlight goes away.
*   **At the end you choose what follows.** **Fertig** ends the process;
    where the dashboard is installed, the chat links to it. Where the
    process can be started anew (see below), **Andere Seite wählen** starts
    it in a new conversation that asks for a page.
*   **Another page in the page module is noticed.** When the page module
    beside the floating panel shows another page than the process, the
    chat says "Andere Seite ausgewählt". **Bei „<Seite>“ bleiben** keeps
    the process and does not ask again for that page; where the process can
    be started anew, **Zur neuen Seite wechseln** ends it and starts it on
    the new page.
*   **You can end it at any point.** The ``×`` beside the progress ends
    the process, and so does removing the conversation's skill. A proposal
    still waiting for your answer is withdrawn and changes nothing; changes
    already applied stay, and so do the outcomes shown in the chat and the
    open points. The highlight goes away and the input gets the focus. While
    the assistant works or a decision is being carried out, the process
    cannot be ended.
*   **A decision that cannot be taken right now comes back.** When nr-llm
    cannot check the process just then, the proposal stays and the chat
    asks you to try again. When the AI configuration requires a second
    person to approve every change, nr-llm ends the process at the first
    decision: the chat says so, the proposal is not applied, and the
    conversation can go on without the process.
*   **Skipped proposals stay open.** A change you answer with **Skip** is
    kept as an open point of that page: the record and field it was about,
    never its text. The next time the process runs on the page, it is
    offered again. It stays open until a change to the same record and
    field is applied in full, in any conversation; a change that was only
    partly applied leaves it open. A proposal you leave behind with a
    message of your own is not kept.
*   **Kept content closes the open point.** A change you answer with
    **Keep** is not offered again: the open points on that record and field
    are closed, and the tour summary lists the proposal under **Kept**.

A process started for a page -- from the dashboard's "Eine Seite
verbessern", or by **Zur neuen Seite wechseln** -- begins at once: the chat
sends **Diese Seite prüfen** for you, and you do not need to type anything.
The process stays on that page; to work on another one, start it there. A
process started without a page asks for one: choose the page in the
page tree, then confirm it with **Diese Seite prüfen** in the floating
panel. This page choice, **Andere Seite wählen** and **Zur neuen Seite
wechseln** are offered only where nr-llm can start a run with an
invocation of the process; until it can, they stay hidden.

None of this changes a record. An administrator can switch the tools behind
it off as the tool group ``nr_mcp_agent``.

..  _usage-conversation-management:

Conversation management
=======================

The sidebar shows your conversation history. Every list of conversations
-- the chat module's sidebar, the panel's tabs, its **More** list and its
sidebar, and the **Recent chats** dashboard widget -- shows the same about
each one:

-   **Title** -- a guided tour is named by its tour and its page, for
    example *SEO optimieren · Über uns*, with the page's current title. Any
    other chat is named by its first message, shortened to its first
    sentence or about 60 characters; the full text is in the tooltip. A
    chat about a missing alt text keeps *Alt-Text: <file>*. A title you gave
    a chat yourself is always kept, also when it begins like the tour's. The
    page is named in the chat's language where it has a translation. Two
    chats that would read alike, such as the same tour twice on one page,
    show when each was started after the title, to the second where the
    minute is the same.
-   **Context** -- what the title does not say already: the page a skill
    that needs one works on, the skill, *Guided tour* and the point a tour is
    at (*Point 2 of 5*). A page is named only if you may see it in the page
    tree; a deleted page is shown as *Deleted page*. An ordinary chat names
    no page: it only remembers the page you had open when you last wrote,
    which is not what the chat is about.
-   **Time** -- when the last message was written: *Today, 10:42*,
    *Yesterday, 16:05*, then the date, in your browser's time zone. The full
    date and time are in the tooltip.
-   **Status** -- in words, with a colour that only repeats them: *Ready* is
    neutral, *Waiting for your answer* and *Waiting for your approval* stand
    out in the warning colour with an icon, *Working* is shown as
    information, *Failed* as an error.

The lists are ordered by the last message, newest first, with pinned chats
on top. A chat without any message is not listed, except while it is the
one that is open.

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
of the lists.

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

.. _usage-attaching-files:

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

With the privacy setting :confval:`imageAnalysis` on *Context only*, no
image goes to the provider: the file picker offers no image format, an image
upload is refused, and an image attached earlier is not sent; the assistant
says that it cannot see it. A PDF then goes as its extracted text, not as
the file, because a provider that reads PDFs would see the photos in it. An
image in a folder listed in :confval:`imageAnalysisExcludedFolders` is not
sent either, and a document from such a folder goes as its text.

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
chat. The row holds four of them: pinned conversations first, then the ones
with the most recent message, and always the one that is open. A tab shows
the status icon and the title; its tooltip adds the context, the status in
words and the time. The others are behind **More (n)** at the end of the
row, which opens a list with a search field and the same lines as the
module's list: type to filter by title, page or skill, move with the arrow
keys, open one with :kbd:`Enter`, close the list with :kbd:`Escape`. However
many conversations you keep, the row stays one line high and the chat keeps
its space. Maximized, the panel lists every conversation in the sidebar
instead. The collapsed bar shows the open conversation's title, with its
context and time in the tooltip.

Panel height and state are stored in ``localStorage`` per user.

..  _usage-chat-column:

Chat as a column
----------------

The **Column mode** button in the panel's header docks the chat as a column
in the backend's content area: in modules with a page tree it sits between
the tree and the module, in modules without one (the Dashboard, for example)
it is the leftmost column. The column stays when you switch modules, and the
module beside it gets the remaining width. The same button in the column's
header turns it back into the floating panel.

*   **Width** -- Drag the edge between the column and the module, or focus
    it with :kbd:`Tab` and use :kbd:`Left` and :kbd:`Right` to step,
    :kbd:`Home` and :kbd:`End` for the narrowest and the widest width. The
    column is at least 320 px and at most half the content area wide.
*   **Selected page** -- The chat follows the page selected in the page tree,
    as soon as you click it. In a module that is not about a page the chat
    sends no page.
*   **Minimize** -- The column becomes a small bar in the bottom right corner
    with the chat's title and state, for example *Waiting for your approval*
    when a change waits for you. Its first button opens the column again.

Column mode and width are stored in your backend user settings, so they
follow you to other browsers. Where the backend has no content area for the
column (for example without access to any module), the chat stays a
floating panel. While the content area is narrower than 640 px (twice the
column's minimum), for example in a small window, the chat floats as well
and docks again once there is room; your choice stays stored, and the
**Column mode** button is not offered meanwhile. While the chat is sending a
message or a decision, the **Column mode** and **Open in a separate window**
buttons are disabled. The module menu is not collapsed automatically.

..  _usage-dashboard:

Dashboard widget
================

With the TYPO3 dashboard installed, a **Recent chats** widget can be added
to a dashboard (*Add widget > General*). It takes the dashboard's full width
and lists your ten chats with the newest last message, newest first; a chat
without any message yet is not listed. Each entry shows what every list
shows (see :ref:`Conversation management <usage-conversation-management>`):

-   the title, for example *SEO optimieren · Über uns* for a guided tour;
-   below it, in grey, the context the title does not name, for example
    *Guided tour*, or *Page “Über uns” · SEO optimieren* for a chat you
    renamed;
-   for a guided process that is at a point, has not finished and still
    has its page, that point (*Point 3 of 5*), as the chat's header shows
    it;
-   the status in words, as a badge: neutral for *Ready*, in the warning
    colour with an icon while the chat waits for your answer or approval;
-   when the last message was written: *Today, 10:42*, *Yesterday, 16:05*,
    then the date (*Oct 3*), with the year once it is more than a year ago,
    in your backend language; the full date and time in the tooltip.

A click opens the chat in the chat panel next to the dashboard, floating or
as a column, as you last used it; a guided process also opens its page in
the page module, in its language. A guided process continues where it stopped: a
proposal waiting for your answer is shown again. **New chat** starts a chat
there, and **Show all chats** goes to the chat module. Each entry is one
link, reachable with :kbd:`Tab`; a screen reader reads its title, context,
progress, status and time.

A guided process on a page you may not see is listed without the page. A guided process
whose page has been deleted since is listed as *Deleted page*; it opens
read-only, with a notice where the input was: it can be read, but no message
can be sent or edited, and no question answered, proposal decided or run
resumed. **End tour** in the notice (or the × beside the progress) ends the
tour, which withdraws a run still waiting for your answer; the chat then goes
on as an ordinary chat, and a guided tour picked in it again asks for a page.
A page deleted only in a workspace does not count as deleted.

For a user the chat is not available to, the widget says so instead of
listing anything. Which groups may place the widget at all is set per
backend group under *Dashboard widgets*, as for every other widget.

AI assistant dashboard
----------------------

Editors can put together a dashboard for working with the assistant from
the widgets in the *AI Assistant* group (*Add widget > AI Assistant*), or
start from the **AI Assistant** preset when creating a dashboard, which
holds them in this order: **AI Assistant**, **Edit a page**, **Create
content**, **Recent chats**, **Recommended for your website**, **Improve a
page**, **Check website**. **Frequent tasks** is in the group, not in the
preset.

Every widget opens with one sentence and offers one kind of action: a
button, a grid of buttons, number tiles or a list whose rows are links. An
action that concerns a page opens the page module with the chat as a
column beside it (floating where the window is too narrow, see
:ref:`usage-chat-column`; a chat popped out into its own window stays
there). Where the chat panel is not available, the same action opens the
chat module.

**AI Assistant**
    A question of your own, without choosing a tour or a page first: type it
    under *What would you like to do?* and press :kbd:`Enter` or the round
    send button; :kbd:`Shift+Enter` starts a new line. The chat opens as a
    column beside the dashboard (floating where the window is too narrow
    for a column, see :ref:`usage-chat-column`) on a new conversation, and
    your question is its first message, as you typed it; the assistant
    answers from there. The column is for this opening only: your chosen
    chat layout is not changed, and the chat returns to it once you close
    it. A chat you popped out into its own window stays there and gets the
    question there. The send button can be pressed only while there is a
    question. Where the chat cannot take the question -- while it is still
    sending another message, or when the question is longer than the
    configured maximum -- the question stays in the widget and the widget
    says why. A question about a page while no page is selected makes the
    assistant ask which page you mean.

**Edit a page**
    **Choose a page to edit** opens the page module with a new chat beside
    it. Choose the page in the page tree; the chat follows it, and whatever
    you then ask is about that page.

**Create content**
    One button per kind of content the assistant can create for you: **New
    page**, and **News** where EXT:news is installed. A kind is offered only
    where you may create its records and the tool that creates them is
    enabled for your chat, and that the tool would not refuse for you (the
    default language, and for news its table settings and nr-llm's
    deny-list). A button opens a new chat with the start of the request in
    its input (*I would like to create a new page, namely …*); nothing is sent
    until you complete and send it, as the widget says below the buttons. Without any kind,
    the widget says that your administrator can enable the tools.

**Improve a page**
    One row per guided tour (by default *Optimise SEO* and *Improve
    content*). A row opens the page module and starts the tour in the chat
    beside it; the tour asks for the page, which you choose in the page tree
    and confirm with **Check this page**.

**Frequent tasks**
    Shortcuts into the chat, set by :confval:`dashboardQuickTasks` (none by
    default), one row each. A task that works on one page opens the page
    module and asks for the page there; any other task opens the chat
    module.

**Recommended for your website**
    Number tiles for what the extension can count exactly, each with one
    action:

    -   **Images without alt text** you may fix, on how many pages and in how
        many records; **View** opens the list (see
        :ref:`usage-missing-alt-texts`).
    -   **Pages without a meta description**: page versions you may edit
        whose meta description is empty, in every language you may edit and
        in your workspace; hidden pages, folders, links, shortcuts, mount
        points and spacers are not counted, nor are pages below a page you
        may not see in the page tree. Each translation counts on its
        own, and the label says so (*Pages and translations without a meta
        description*). The number is kept for five minutes and recounted as
        soon as a page is saved. **Start now** starts the SEO tour
        on the most recently changed of them, beside the page module. The
        tile is left out where you may not edit the meta description or the
        SEO tour is not available to you.

    A count of 0 is shown as done (*No missing alt texts – great!*, *Every
    page you may edit has a meta description – great!*).
    Broken links and outdated content are not counted: the extension has no
    exact source for them.

    Below the tiles, the points guided tours left open: changes a tour
    proposed that an editor skipped, until a change to the same record and
    field is applied. Each row starts the matching tour on its page, in the
    language the point is about. A point is shown only when the editor may
    access its page, read the changed record and field, and edit that
    language; a point whose skill no longer exists is not shown.

**Check website**
    Site-wide checks, one row each. **Missing alt texts** is always listed
    and opens the list, also when nothing is open. **Pages without a meta
    description** is listed while one is open and starts the SEO tour as
    the tile does.

Only tours and tasks the chat can actually start are offered: a configured
skill that does not exist (yet), is not attached to the chat, or cannot run
on this installation is left out, and a widget left empty says that nothing
is set up yet. For a user the chat is not available to, each widget says so
instead.
Offering a page is not permission to change it: what the assistant does on
the page is checked again when it does it.

.. _usage-missing-alt-texts:

Missing alt texts
=================

The list **Missing alt texts** shows every use of an image without an alt
text that you may fix, across all pages and records, without opening them
one by one. Open it from **Check website** or from the tile in
**Recommended for your website** on the dashboard. It is checked when the
list opens: an alt text you add in a content element is gone from the list
the next time you open it.

The heading counts the open images. **Pages** lists the images in content
elements and in the page properties, grouped by page in page tree order and
within a page in the order of its content. Each record type with open
images follows as a tab of its own, named as in the list module, for
example *News*; its images are grouped by record, with the folder the
record lies in. A tab whose last image you fixed stays open, saying it has
no open hits, until you open the list again. The tabs work with the arrow
keys, :kbd:`Home` and :kbd:`End`.

Each row shows the image (with its crop), the file name, the content
element or the image field, the language (its code, such as *DE*, or *DE-CH*
where two languages of the site share a code), and a button that opens the
page in that language,
or the record's detail page, in a new tab. A record type gets **Open detail
page** only where its preview is configured (Page TSconfig
``TCEMAIN.preview``), as **View** in the list module. Hidden and timed
content stays in the list: *Currently not visible* says why, for example
*Element hidden* or *Expired since …*, because the image needs its alt text
once the content is shown again. Many images are listed page by page.

An image counts as open when neither its use nor the file list
(*Alternative text* of the file) gives it an alt text, and it is not marked
as decorative. You see only images you may change: on pages whose content
you may edit (an image in the page properties needs the right to edit the
page as well), in languages you may edit, in content elements of a type you
may use, with the right to change *Alternative text* and the record and its
image field. Which tables and fields are checked is set in
:confval:`altTextExcludedTables` and :confval:`altTextExcludedFields`.

.. _usage-optimise-alt-text:

Optimise an alt text with the assistant
---------------------------------------

**Optimise** in a row opens the chat beside the list, on that image. The
module menu folds to make room and unfolds again when you close the chat;
the row the chat works on is marked, and its button reads **In the chat**.
The chat starts with a card of the image: where it is used, its language,
and that it has no alt text. The assistant then proposes a text for this
use of the image, with these answers:

**Apply**
    Saves the text. The chat confirms it with *Alt text saved.*, and the
    image leaves the list. If it cannot be saved, the assistant says why
    in one sentence, the proposal stays, and **Offer it again** brings it
    back as a new proposal.

**Another variant**
    The assistant proposes a different text. You can also write a wish,
    such as *Shorter*, and get a new proposal.

**Mark as decorative**
    For an image that adds nothing to the content (see
    :ref:`usage-decorative-images`), offered when you may set the mark.
    The assistant then proposes the mark; you confirm it once, with **Mark
    as decorative** on that proposal, and **Create an alt text** asks for a
    text instead. For an image that belongs to a section or a record the
    proposal says so, because such an image probably needs an alt text.
    Where the assistant itself finds the image decorative, it proposes the
    mark in the same way; where you may not set the mark, it does not, and
    such a proposal cannot be confirmed.

**Skip**
    Saves nothing; the image stays open in the list.

**Optimise the next hit** below the conversation, or **Optimise** in
another row, moves the conversation to the next image: a line *Hit
changed* names it, and its card follows. The next hit is the one after the
current image in the order of the list, across the tabs. **Optimise** while
the assistant is still writing its proposal takes effect when it has
finished, unless you close the chat before. Closing the chat gives the keyboard focus back to the row it was
on, and unfolds the module menu it folded.

The assistant changes nothing but the alt text of this one use of the
image, and only after you confirm it; a proposal for another image cannot
be confirmed in this chat. It sees the image where the AI
provider supports images and the privacy setting lets it go
(:ref:`configuration-image-analysis`), as a scaled copy. Otherwise the card
says that the proposal rests on the page, the element and the file name
only, and why: image analysis is switched off in the settings, the image
lies in a folder whose images are never sent (the card names the folder),
the provider cannot see images, or the image cannot be read. The preview of
the image in the chat and in the list stays; it is shown in the backend
only.

**Optimise** works in the live workspace, when an administrator has enabled
the tools ``set_file_reference_alternative_text`` and
``set_file_reference_decorative`` in nr-llm's **Tools** module, and when
you may approve your own changes in the chat
(:ref:`usage-approving-a-step`). Otherwise the button stays in
the list, and a note above the list says why it does not work.

.. _usage-decorative-images:

Decorative images
=================

Not every image needs an alt text. A purely decorative image, such as a
pattern, an ornament or a divider, is output with an empty alt text, and
screen readers skip it. Mark such an image with **Decorative image**: the
checkbox sits next to *Alternative text* where an image is added to a
content element or a record (for example a news article), not in the file
list, because whether an image is decorative depends on where it is used.
Each language version of an image can be marked on its own.

A decorative image is output with an empty alt text even if it has an alt
text of its own or one from the file list, in the standard content
elements of fluid_styled_content. Depending on how the site loads
fluid_styled_content this needs a set or an import, and other templates
need the condition; both are in :ref:`installation-decorative-images`. Replacing the image creates a new
use, which is not marked.

*Decorative image* is an exclude field, like *Alternative text*: editors
see it once their backend group is granted it under *Allowed excludefields*
(*File Reference: Decorative image*).

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
