import {ApiClient} from './api-client.js';
import {lll} from '@typo3/core/lit-helper.js';
import {renderMarkdown} from './markdown.js';

export const PROCESSING_STATUSES = new Set(['processing', 'locked', 'tool_loop']);

/**
 * Where the user is in the backend: the open module and the page the module
 * shows (NEXT-172).
 *
 * Read from the backend frame, which the floating panel shares (ADR-011) and
 * the chat module sits inside. The module is the router's current identifier;
 * the page is the `id` of the module frame's URL — the page a page-tree module
 * shows. A module that is not about a page has no `id` there, and then no page
 * is sent: the page tree may still hold a selection, but "this page" would
 * not mean it.
 *
 * Anything unreadable degrades to "no context" — it is a hint for the
 * assistant, never a reason for a message to fail. The server re-checks the
 * page against the user's permissions before it reaches the prompt.
 *
 * @param {Window} [win]
 * @returns {{pageId: number, module: string}}
 */
export function currentBackendContext(win = globalThis.top ?? globalThis) {
    const context = {pageId: 0, module: ''};
    try {
        const doc = win.document;
        const router = doc.querySelector('typo3-backend-module-router');
        const module = router?.module || router?.getAttribute('module') || '';
        if (/^\w{1,100}$/.test(module)) {
            context.module = module;
        }

        const frame = win.frames?.list_frame;
        const id = new URLSearchParams(frame?.location?.search ?? '').get('id');
        if (id && /^\d+$/.test(id)) {
            context.pageId = Number.parseInt(id, 10);
        }
    } catch {
        // Cross-origin or not a backend window: send no context.
    }
    return context;
}

/**
 * Offer text to the reader as a file download.
 *
 * The anchor is created in the document the component lives in: a panel moved
 * into its own window (popOut()) belongs to that window's document, and an
 * anchor clicked in the other one would download nothing visible to the
 * reader.
 *
 * @param {Document} doc
 * @param {string} fileName
 * @param {string} text
 * @param {string} [type]
 */
export function downloadTextFile(doc, fileName, text, type = 'text/markdown;charset=utf-8') {
    const view = doc.defaultView || globalThis;
    const url = view.URL.createObjectURL(new view.Blob([text], {type}));
    const a = doc.createElement('a');
    a.href = url;
    a.download = fileName;
    a.style.display = 'none';
    doc.body.append(a);
    a.click();
    a.remove();
    // Revoked on the next task: revoking synchronously can cancel the
    // download before the browser has started reading the blob.
    view.setTimeout(() => view.URL.revokeObjectURL(url), 0);
}

/**
 * ChatCoreController – Lit ReactiveController that encapsulates all chat
 * business logic. The host component creates an instance via
 * `new ChatCoreController(this)` in its constructor.
 *
 * The host must implement three callback hooks:
 * - onScrollToBottom(force) – scroll the message container
 * - onFocusInput()          – focus the textarea
 * - onResetInput()          – reset textarea height after send
 */
export class ChatCoreController {
    /** @type {import('lit').ReactiveControllerHost} */
    host;

    // ── Public state (host reads these in render) ──────────────────────
    conversations = [];
    activeUid = null;
    messages = [];
    /**
     * The conversation `messages` belongs to. Differs from activeUid while a
     * switch is loading: the list still holds the previous conversation, and
     * anything built from it (the export) must wait.
     */
    messagesUid = null;
    status = '';
    errorMessage = '';

    /** Link to the run waiting for an approval; empty when nothing is pending. */
    approvalUrl = '';

    /**
     * Where an administrator fixes the failure on screen, and the link text;
     * both empty for anyone else and for an ordinary failure (ADR-017).
     */
    errorLink = '';
    errorLinkLabel = '';

    /** The server message the link belongs to; a local error replacing it takes no link along. */
    errorLinkMessage = '';

    /** True while a decision is in flight, so the buttons cannot be pressed twice. */
    approvalBusy = false;

    /** The pending tool call as the approvals inbox describes it; null when nothing is pending. */
    pendingApproval = null;

    /**
     * What was just decided here: 'approved', 'denied', or null when no decision
     * of this reader's is in flight.
     *
     * The chat used to answer a decision with nothing of its own: the card
     * vanished, the spinner came back, and the only durable confirmation was the
     * assistant's reply at the end of the continuation. Between the two there
     * was no visible difference between "your approval is being carried out" and
     * "nothing happened", which is what sent readers to decide a second time in
     * AI Tasks (NEXT-156). Held locally on purpose: the durable record of the
     * outcome is the assistant's answer, and this state is cleared the moment it
     * arrives.
     */
    approvalDecisionTaken = null;
    inputValue = '';
    hasInput = false;
    loading = true;
    sending = false;
    available = false;
    issues = [];
    maxLength = 0;
    /** @type {Set<number>} */
    expandedTools = new Set();
    /** @type {{fileUid: number, name: string, mimeType: string}|null} */
    pendingFile = null;
    visionSupported = false;
    maxFileSize = 0;
    /** @type {string[]} */
    supportedFormats = [];

    /** The active conversation's own instructions; empty when it has none. */
    systemPrompt = '';
    /** True while the instructions editor is open. */
    systemPromptOpen = false;
    systemPromptDraft = '';
    systemPromptSaving = false;

    /**
     * Summaries of the current turn's steps, as the worker records them
     * (NEXT-172); refreshed by every poll while the turn runs.
     * @type {Array<{kind: string, round?: number, ms?: number, tool?: string, error?: boolean, approved?: boolean}>}
     */
    activity = [];
    /** Whether the activity list is shown. */
    activityOpen = false;

    /** Index of the user message being edited, or -1. */
    editingIndex = -1;
    editDraft = '';

    // ── Internal state ─────────────────────────────────────────────────
    /** @type {ApiClient} */
    _api;
    /** @type {AbortController} */
    _abortController;
    /** @type {number|null} */
    _pollTimer = null;
    /** @type {number} */
    _knownMessageCount = 0;
    /** @type {number} */
    _pollFailures = 0;
    /** @type {HTMLElement|null} — overlay wrapping the element-browser iframe */
    _falPickerOverlay = null;

    /**
     * @param {import('lit').ReactiveControllerHost} host
     */
    constructor(host) {
        this.host = host;
        host.addController(this);
    }

    // ── Lifecycle ──────────────────────────────────────────────────────

    hostConnected() {
        this._abortController = new AbortController();
        this._api = new ApiClient(this._abortController.signal);
        this.host.addEventListener(
            'nr-mcp-open-fal-picker',
            () => this._openFalPicker(),
            {signal: this._abortController.signal},
        );
        this.init();
    }

    hostDisconnected() {
        this._abortController?.abort();
        this.stopPolling();
        this._cleanupFalPicker();
    }

    /** @param {string} message */
    _setError(message) {
        this.issues = [message];
        this.host.requestUpdate();
    }

    // ── Core logic ─────────────────────────────────────────────────────

    async init() {
        const signal = this._abortController?.signal;
        try {
            const statusData = await this._api.getStatus();
            if (signal?.aborted) return;
            this.available = statusData.available;
            this.issues = statusData.issues || [];
            this.visionSupported = statusData.visionSupported || false;
            this.maxFileSize = statusData.maxFileSize || 0;
            this.supportedFormats = statusData.supportedFormats || [];
            await this.loadConversations();
        } catch (e) {
            if (signal?.aborted) return;
            this.issues = [e.message];
        } finally {
            if (!signal?.aborted) {
                this.loading = false;
                this.host.requestUpdate();
            }
        }
    }

    async loadConversations() {
        const data = await this._api.listConversations();
        this.conversations = data.conversations || [];
        this.host.requestUpdate();
    }

    async selectConversation(uid) {
        this.activeUid = uid;
        this._knownMessageCount = 0;
        this.expandedTools = new Set();
        this.pendingFile = null;
        this.approvalDecisionTaken = null;
        this.systemPrompt = '';
        this.systemPromptOpen = false;
        this.editingIndex = -1;
        this.activity = [];
        this.host.requestUpdate();
        await this.loadMessages();
        this.startPollingIfNeeded();
        this.host.onFocusInput();
    }

    /**
     * Send the decision and start following the conversation.
     *
     * The endpoint records the decision and answers 202 — a worker carries it
     * out, exactly as a sent message is carried out. So the outcome arrives the
     * way every other outcome here arrives: through the poll. Reloading right
     * away puts the conversation into its processing state, which is what
     * starts that poll.
     *
     * The digest travels back exactly as it arrived. The runtime verifies it
     * against the state it claims, so a decision made on a card that has since
     * been superseded is refused there rather than applied here — and that
     * refusal comes back as the card, with the reason on it.
     *
     * Everything is bound to the conversation the click happened in, captured
     * before the await: the reader can switch conversations while the request is
     * in flight, and the answer must not land on whichever one they moved to —
     * neither the confirmation nor, on the error path, the reason. Same guard
     * pollMessages() uses on its own response.
     */
    async decideApproval(approve) {
        const uid = this.activeUid;
        if (!this.pendingApproval || this.approvalBusy || !uid) {
            return;
        }

        this.approvalBusy = true;
        this.host.requestUpdate();
        try {
            await this._api.decideApproval(uid, approve, this.pendingApproval.turnDigest || '');
            if (uid !== this.activeUid) {
                return;
            }

            // Say what happened before the reload, and drop the card and the
            // notice that asked for the decision: both describe a state this
            // click has just left, and the server has cleared the notice too.
            this.approvalDecisionTaken = approve ? 'approved' : 'denied';
            this.errorMessage = '';
            this.pendingApproval = null;
            this.host.requestUpdate();
            await this.loadMessages();
            this.startPollingIfNeeded();
        } catch (e) {
            if (uid !== this.activeUid) {
                return;
            }

            this.approvalDecisionTaken = null;
            this.errorMessage = e.message;
        } finally {
            this.approvalBusy = false;
            this.host.requestUpdate();
        }
    }

    async loadMessages() {
        const uid = this.activeUid;
        if (!uid) return;
        try {
            const data = await this._api.getMessages(uid, 0);
            // Switched away while this was loading: the answer is for a
            // conversation no longer on screen.
            if (uid !== this.activeUid) return;
            this.messages = data.messages || [];
            this.messagesUid = uid;
            this.status = data.status;
            this.errorMessage = data.errorMessage || '';
            this._setErrorLink(data);
            this.approvalUrl = data.approvalUrl || '';
            this.pendingApproval = data.pendingApproval || null;
            this.systemPrompt = data.systemPrompt || '';
            this.activity = data.activity || [];
            if (data.pendingApproval) {
                // The decision was refused and the run handed back: what is on
                // screen is a question again, not a confirmation.
                this.approvalDecisionTaken = null;
            }
            this._knownMessageCount = data.totalCount;
            this.host.requestUpdate();
            this.host.onScrollToBottom(true);
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        }
    }

    async pollMessages() {
        const uid = this.activeUid;
        if (!uid) return;
        try {
            const data = await this._api.getMessages(uid, this._knownMessageCount);
            if (uid !== this.activeUid) return; // stale response, discard
            // Fewer messages than this view holds: the transcript was cut
            // elsewhere — an edit in another tab or in the other surface.
            // Appending to the old list would never show the new answer.
            if (typeof data.totalCount === 'number' && data.totalCount < this._knownMessageCount) {
                await this.loadMessages();
                if (!this.isProcessing()) this.stopPolling();
                return;
            }

            const newMessages = data.messages || [];
            const statusChanged = data.status !== this.status;

            // The activity changes while nothing else does — a tool call adds
            // an entry without a message — so it is taken from every poll.
            const activity = data.activity || [];
            if (JSON.stringify(activity) !== JSON.stringify(this.activity)) {
                this.activity = activity;
                this.host.requestUpdate();
            }

            if (newMessages.length > 0 || statusChanged) {
                if (newMessages.length > 0) {
                    this.messages = [...this.messages, ...newMessages];
                    // The continuation answered. Its answer is the outcome, and
                    // it outlives a reload; the transient line does not.
                    this.approvalDecisionTaken = null;
                }
                if (data.pendingApproval) {
                    this.approvalDecisionTaken = null;
                }
                this.status = data.status;
                this.errorMessage = data.errorMessage || '';
                this._setErrorLink(data);
                this.approvalUrl = data.approvalUrl || '';
                this.pendingApproval = data.pendingApproval || null;
                this._knownMessageCount = data.totalCount;
                // Update active conversation status in-place (avoids extra request)
                this.conversations = this.conversations.map(c =>
                    c.uid === this.activeUid
                        ? {...c, status: data.status, errorMessage: data.errorMessage || ''}
                        : c
                );
                this.host.requestUpdate();
                this.host.onScrollToBottom();
            }

            // Reset failure counter on success
            this._pollFailures = 0;
            if (this.errorMessage === lll('chat.connectionLost')) {
                this.errorMessage = '';
                this.host.requestUpdate();
            }

            // Stop polling when no longer processing
            if (!this.isProcessing()) {
                this.stopPolling();
            }
        } catch {
            this._pollFailures++;
            if (this._pollFailures >= 5) {
                this.errorMessage = lll('chat.connectionLost');
                this.host.requestUpdate();
                this.stopPolling();
            }
        }
    }

    startPollingIfNeeded() {
        this.stopPolling();
        if (this.isProcessing()) {
            this.schedulePoll();
        }
    }

    schedulePoll() {
        this._pollTimer = setTimeout(async () => {
            if (!this.host.isConnected) return;
            await this.pollMessages();
            if (this.host.isConnected && this.isProcessing()) {
                this.schedulePoll();
            }
        }, 2000);
    }

    stopPolling() {
        if (this._pollTimer) {
            clearTimeout(this._pollTimer);
            this._pollTimer = null;
        }
    }

    /**
     * The link an administrator gets beside a configuration failure, or null.
     * Only while the message on screen is the one the server sent it with.
     *
     * @returns {{href: string, label: string}|null}
     */
    currentErrorLink() {
        if (!this.errorLink || !this.errorMessage || this.errorLinkMessage !== this.errorMessage) {
            return null;
        }

        return {href: this.errorLink, label: this.errorLinkLabel || this.errorLink};
    }

    /** @param {{errorMessage?: string, errorLink?: string, errorLinkLabel?: string}} data */
    _setErrorLink(data) {
        this.errorLink = data.errorLink || '';
        this.errorLinkLabel = data.errorLinkLabel || '';
        this.errorLinkMessage = data.errorMessage || '';
    }

    isProcessing() {
        return PROCESSING_STATUSES.has(this.status);
    }

    async handleSend() {
        const content = this.inputValue.trim();
        if (!content || this.sending || this.isProcessing()) return;

        if (this.maxLength > 0 && content.length > this.maxLength) {
            this.errorMessage = lll('chat.messageTooLong', this.maxLength);
            this.host.requestUpdate();
            return;
        }

        const fileUid = this.pendingFile?.fileUid ?? null;

        this.sending = true;
        this.errorMessage = '';
        this.host.requestUpdate();
        try {
            await this._api.sendMessage(this.activeUid, content, fileUid, currentBackendContext());
            this.inputValue = '';
            this.hasInput = false;
            this.host.onResetInput();
            // Optimistic: add user message locally
            const msg = {role: 'user', content, createdAt: new Date().toISOString()};
            if (this.pendingFile) {
                msg.fileUid = this.pendingFile.fileUid;
                msg.fileName = this.pendingFile.name;
                msg.fileMimeType = this.pendingFile.mimeType;
            }
            this.pendingFile = null;
            this.messages = [...this.messages, msg];
            this.activity = [];
            this.status = 'processing';
            this._knownMessageCount++;
            this.conversations = this.conversations.map(c =>
                c.uid === this.activeUid ? {...c, status: 'processing'} : c
            );
            this.errorMessage = '';
            this.host.requestUpdate();
            this.host.onScrollToBottom(true);
            this.startPollingIfNeeded();
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        } finally {
            this.sending = false;
            this.host.requestUpdate();
        }
    }

    /**
     * Whether the message at `idx` can be edited and run again: a message the
     * user wrote as plain text, while no turn is running.
     */
    canEditMessage(idx) {
        const msg = this.messages[idx];
        return !!msg && msg.role === 'user' && typeof msg.content === 'string'
            && !this.isProcessing() && !this.sending && this.available;
    }

    startEdit(idx) {
        if (!this.canEditMessage(idx)) return;
        this.editingIndex = idx;
        this.editDraft = this.messages[idx].content;
        this.host.requestUpdate();
        this._focusAfterRender('.message-editor textarea');
    }

    cancelEdit() {
        this.editingIndex = -1;
        this.editDraft = '';
        this.host.requestUpdate();
    }

    /**
     * Send the edited message. The server replaces it, drops every message
     * after it and starts a new turn; the transcript is then reloaded rather
     * than patched here, because indices past the edit no longer exist.
     */
    async submitEdit() {
        const idx = this.editingIndex;
        const content = this.editDraft.trim();
        if (idx < 0 || !content || this.sending) return;

        if (this.maxLength > 0 && content.length > this.maxLength) {
            this.errorMessage = lll('chat.messageTooLong', this.maxLength);
            this.host.requestUpdate();
            return;
        }

        const uid = this.activeUid;
        this.sending = true;
        this.errorMessage = '';
        this.host.requestUpdate();
        try {
            await this._api.editMessage(uid, idx, content, currentBackendContext(), this.messages[idx].content, this.messages.length);
            if (uid !== this.activeUid) return;
            this.editingIndex = -1;
            this.editDraft = '';
            this.expandedTools = new Set();
            this.approvalDecisionTaken = null;
            this.conversations = this.conversations.map(c =>
                c.uid === uid ? {...c, status: 'processing'} : c
            );
            await this.loadMessages();
            this.startPollingIfNeeded();
            if (idx === 0) {
                // The server renames a conversation whose title came from
                // this message; show the new one.
                await this.loadConversations();
            }
        } catch (e) {
            this.errorMessage = e.message;
            if (e.status === 409 && uid === this.activeUid) {
                // The transcript changed under this view: show it as it is now.
                this.editingIndex = -1;
                await this.loadMessages();
                this.errorMessage = e.message;
            }
        } finally {
            this.sending = false;
            this.host.requestUpdate();
        }
    }

    /**
     * Move focus into an editor the next render opens, so a keyboard user
     * does not have to find it (and Escape, which closes it, works at once).
     */
    _focusAfterRender(selector) {
        this.host.updateComplete?.then(() => {
            this.host.renderRoot?.querySelector(selector)?.focus();
        });
    }

    toggleSystemPrompt() {
        if (this.systemPromptOpen) {
            this.closeSystemPrompt();
        } else {
            this.openSystemPrompt();
        }
    }

    toggleActivity() {
        this.activityOpen = !this.activityOpen;
        this.host.requestUpdate();
    }

    openSystemPrompt() {
        this.systemPromptDraft = this.systemPrompt;
        this.systemPromptOpen = true;
        this.host.requestUpdate();
        this._focusAfterRender('.instructions-editor textarea');
    }

    closeSystemPrompt() {
        this.systemPromptOpen = false;
        this.host.requestUpdate();
    }

    async saveSystemPrompt() {
        const uid = this.activeUid;
        if (!uid || this.systemPromptSaving) return;
        this.systemPromptSaving = true;
        this.host.requestUpdate();
        try {
            const data = await this._api.updateSystemPrompt(uid, this.systemPromptDraft.trim());
            if (uid !== this.activeUid) return;
            this.systemPrompt = data.systemPrompt ?? '';
            this.systemPromptOpen = false;
            this.errorMessage = '';
        } catch (e) {
            this.errorMessage = e.message;
        } finally {
            this.systemPromptSaving = false;
            this.host.requestUpdate();
        }
    }

    async handleNewConversation() {
        try {
            const data = await this._api.createConversation();
            await this.loadConversations();
            await this.selectConversation(data.uid);
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        }
    }

    async handleResume() {
        if (!this.activeUid) return;
        try {
            await this._api.resumeConversation(this.activeUid);
            this.status = 'processing';
            this.errorMessage = '';
            this.conversations = this.conversations.map(c =>
                c.uid === this.activeUid ? {...c, status: 'processing'} : c
            );
            this.host.requestUpdate();
            this.startPollingIfNeeded();
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        }
    }

    async handleArchive(uid = null) {
        const targetUid = uid ?? this.activeUid;
        if (!targetUid) return;
        if (!confirm(lll('conversations.archiveConfirm'))) return;
        try {
            await this._api.archiveConversation(targetUid);
            if (targetUid === this.activeUid) {
                this.activeUid = null;
                this.messages = [];
                this.status = '';
                this.errorMessage = '';
                this.stopPolling();
            }
            await this.loadConversations();
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        }
    }

    async handleRename(uid, title) {
        const trimmed = title.trim();
        if (!trimmed) return;
        try {
            await this._api.renameConversation(uid, trimmed);
            this.conversations = this.conversations.map(c =>
                c.uid === uid ? {...c, title: trimmed} : c,
            );
            this.host.requestUpdate();
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        }
    }

    async handleTogglePin() {
        if (!this.activeUid) return;
        try {
            await this._api.togglePin(this.activeUid);
            this.errorMessage = '';
            await this.loadConversations();
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        }
    }

    handleToolMessageClick(idx) {
        if (this.expandedTools.has(idx)) {
            this.expandedTools.delete(idx);
        } else {
            this.expandedTools.add(idx);
        }
        this.host.requestUpdate();
    }

    getActiveConversation() {
        return this.conversations.find(c => c.uid === this.activeUid);
    }

    canAttachFile() {
        if (!this.visionSupported) return false;
        const fileCount = this.messages.filter(m => m.fileUid).length;
        return fileCount < 5 && !this.pendingFile;
    }

    async handleFileUpload(file) {
        if (file.size > this.maxFileSize) {
            this.errorMessage = lll('attachment.tooLarge', Math.round(this.maxFileSize / 1024 / 1024));
            this.host.requestUpdate();
            return;
        }
        try {
            const result = await this._api.uploadFile(file);
            this.pendingFile = result;
            this.host.requestUpdate();
        } catch (e) {
            this.errorMessage = e.message;
            this.host.requestUpdate();
        }
    }

    // Reserved for the TYPO3 Element Browser (FAL picker) flow — not yet wired to UI.
    handleFileSelect(fileUid, name, mimeType) {
        this.pendingFile = {fileUid, name, mimeType};
        this.host.requestUpdate();
    }

    _openFalPicker() {
        // Guard: picker already open (message listener active)
        if (this._falPickerListener) {
            return;
        }

        // TYPO3 registers the element browser URL in settings.Wizards.elementBrowserUrl
        // (set by BackendController via addInlineSetting for route 'wizard_element_browser')
        const browserUrl = top.TYPO3?.settings?.Wizards?.elementBrowserUrl;
        if (!browserUrl) {
            this._setError(lll('fal_picker_unavailable'));
            return;
        }

        // A unique fieldName lets us identify our postMessage response (TYPO3 13/14 both use postMessage)
        const fieldName = 'nr_mcp_agent_fal_picker';
        const extensions = this.supportedFormats.join(',');
        // bparams format: fieldName|irreConfig|allowedTables|allowedExtensions
        const bparams = encodeURIComponent(fieldName + '|||' + extensions);
        const url = browserUrl + '&mode=file&bparams=' + bparams;

        // We embed the element browser in an <iframe class="t3js-modal-iframe"> instead of a popup window.
        //
        // Root cause of popup approach: TYPO3's element-browser.js#getParent() opens with:
        //   const e = ... && window.frames.frameElement.classList.contains("t3js-modal-iframe")
        // In a popup window window.frameElement is null (not undefined), so null.classList throws before
        // the postMessage is ever sent.
        //
        // With an iframe, window.frameElement is the <iframe> element itself (non-null).  TYPO3's
        // getParent() then hits the branch:
        //   this.opener = window.frames.frameElement.contentWindow.parent   (= our window)
        // and MessageUtility.send() delivers the postMessage to us correctly.
        const iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.className = 't3js-modal-iframe'; // required for TYPO3 getParent() to resolve our window
        iframe.setAttribute('aria-label', lll('fal_picker_label') || 'Select file');
        Object.assign(iframe.style, {
            width: '100%', height: '100%', border: 'none', display: 'block',
        });

        this._falPickerOverlay = document.createElement('div');
        this._falPickerOverlay.setAttribute('aria-modal', 'true');
        this._falPickerOverlay.setAttribute('role', 'dialog');
        Object.assign(this._falPickerOverlay.style, {
            position: 'fixed', inset: '0', zIndex: '9999',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            background: 'color-mix(in srgb, var(--typo3-overlay-bg, #000) 50%, transparent)',
        });

        const box = document.createElement('div');
        Object.assign(box.style, {
            width: '900px', height: '600px', maxWidth: '95vw', maxHeight: '90vh',
            background: 'var(--typo3-surface-container-lowest, #fff)', borderRadius: '4px', overflow: 'hidden',
            display: 'flex', flexDirection: 'column',
        });

        box.appendChild(iframe);
        this._falPickerOverlay.appendChild(box);
        document.body.appendChild(this._falPickerOverlay);

        // Click outside the box to dismiss
        this._falPickerOverlay.addEventListener('click', (e) => {
            if (e.target === this._falPickerOverlay) {
                this._cleanupFalPicker();
            }
        });

        // TYPO3 element browser sends {actionName:'typo3:elementBrowser:elementAdded', fieldName, value, label}
        // via MessageUtility.send() → postMessage() to the window resolved by getParent().
        // value = sys_file UID as a plain string ("42") or in table_uid format ("sys_file_42").
        //
        // getParent() resolves via `window.frames.frameElement.contentWindow.parent` (= our window).
        // However, when `top.frames` contains other t3js-modal-iframe frames (e.g. an open TYPO3 backend
        // modal), getParent() may return `top` or another window instead.  We register the listener on
        // all candidate windows to ensure we receive the message regardless of where getParent() resolves.
        this._falPickerListener = (event) => {
            // The element browser is part of the backend and always same-origin. Registration
            // below is already restricted to same-origin frames, but a window holding a handle
            // to ours — an embedder, or whoever opened us — can post here regardless of where
            // the listener sits. Without this check such a window could forge an
            // `elementAdded` message and make the chat attach any sys_file UID it names.
            if (event.origin !== globalThis.location.origin) return;
            if (event.data?.actionName !== 'typo3:elementBrowser:elementAdded') return;
            if (event.data?.fieldName !== fieldName) return;
            if (!this._falPickerOverlay) return; // guard against duplicate invocations
            this._cleanupFalPicker();
            // Extract the trailing integer — handles both "42" and "sys_file_42"
            const re = /(\d+)$/;
            const match = re.exec(String(event.data.value ?? ''));
            const uid = match ? Number.parseInt(match[1], 10) : 0;
            if (uid > 0) {
                this._onFalFileSelected(uid);
            }
        };
        this._addFalPickerMessageListeners();
    }

    /**
     * Register the FAL picker message listener on all windows that TYPO3's getParent() may resolve to.
     *
     * In TYPO3 v14 the backend loads the active module in an iframe named "list_frame".  getParent()
     * detects this via document.list_frame and — because our overlay contains a .t3js-modal-iframe —
     * routes the postMessage to that module iframe instead of top.  We therefore register on
     * globalThis AND on every same-origin frame currently in top.frames.
     */
    _addFalPickerMessageListeners() {
        const fn = this._falPickerListener;
        globalThis.addEventListener('message', fn);
        // Register on all same-origin child frames so we catch the message regardless of which
        // window getParent() resolves to (top, list_frame, or another t3js-modal-iframe frame).
        this._falPickerExtraWindows = [];
        try {
            Array.from(top.frames || []).forEach(frame => {
                try {
                    if (frame !== globalThis) {
                        frame.addEventListener('message', fn);
                        this._falPickerExtraWindows.push(frame);
                    }
                } catch { /* cross-origin frame — skip */ }
            });
        } catch { /* cross-origin access to top.frames — skip */ }
    }

    _cleanupFalPicker() {
        if (this._falPickerListener) {
            const fn = this._falPickerListener;
            globalThis.removeEventListener('message', fn);
            (this._falPickerExtraWindows || []).forEach(w => {
                try { w.removeEventListener('message', fn); } catch { /* cross-origin */ }
            });
            this._falPickerExtraWindows = null;
            this._falPickerListener = null;
        }
        if (this._falPickerOverlay) {
            this._falPickerOverlay.remove();
            this._falPickerOverlay = null;
        }
        this.host.requestUpdate();
    }

    /** @param {number} fileUid */
    async _onFalFileSelected(fileUid) {
        try {
            const result = await this._api.getFileInfo(fileUid);
            this.handleFileSelect(result.fileUid, result.name, result.mimeType);
        } catch (e) {
            this._setError(e.message);
        }
    }

    clearPendingFile() {
        this.pendingFile = null;
        this.host.requestUpdate();
    }

    formatTime(ts) {
        if (!ts) return '';
        try {
            return new Intl.DateTimeFormat(undefined, {hour: '2-digit', minute: '2-digit'}).format(new Date(ts));
        } catch {
            return '';
        }
    }

    /**
     * The active conversation as a Markdown document.
     *
     * Built from the transcript the chat already holds — loadMessages() reads
     * it from the start — so there is no export endpoint and nothing the reader
     * could not already see. What goes in is what the chat shows as the
     * conversation: user and assistant turns, with the name of an attached
     * file. Tool results and assistant turns that only request a tool are left
     * out, as are system notices; the chat collapses or hides those too, and
     * in a document they would be noise rather than the conversation.
     *
     * @returns {string}
     */
    /** Whether the transcript on screen belongs to the active conversation. */
    canExport() {
        return this.activeUid !== null && this.messagesUid === this.activeUid;
    }

    buildMarkdownExport() {
        if (!this.canExport()) return '';
        const conv = this.getActiveConversation();
        const title = conv?.title || lll('conversations.newConversation');
        const parts = [`# ${title}`];

        for (const msg of this.messages) {
            const role = msg.role;
            if (role !== 'user' && role !== 'assistant') continue;
            if (role === 'assistant' && msg.tool_calls && !msg.content) continue;

            const label = role === 'user' ? lll('export.roleUser') : lll('export.roleAssistant');
            const time = this._formatExportTime(msg.createdAt);
            parts.push(time ? `## ${label} · ${time}` : `## ${label}`);
            if (msg.fileName) {
                parts.push(`*${lll('export.attachment')}: ${msg.fileName}*`);
            }
            parts.push((this.formatRunNotice(msg) ?? this._extractText(msg)).trim());
        }

        return parts.join('\n\n') + '\n';
    }

    /**
     * The reader-language text of a stored run notice, or null when the
     * message carries none. Both chat surfaces and the export use it, so the
     * export shows what the reader saw rather than the stored neutral line.
     *
     * @param {{notice?: string, noticeArgs?: string[]}} msg
     * @returns {string|null}
     */
    formatRunNotice(msg) {
        if (msg.notice !== 'runFinishedOutside') return null;
        const writes = Array.isArray(msg.noticeArgs) ? msg.noticeArgs : [];
        return writes.length > 0
            ? lll('chat.runFinishedOutside', writes.join(', '))
            : lll('chat.runFinishedOutsideNothingWritten');
    }

    /**
     * File name for the export: the title reduced to a safe slug, plus the date.
     *
     * @param {Date} [now]
     * @returns {string}
     */
    exportFileName(now = new Date()) {
        const title = this.getActiveConversation()?.title || '';
        // Words of ASCII letters and digits, joined by single dashes: split
        // and join rather than trimming dashes with a regular expression.
        const words = title
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .split(/[^a-z0-9]+/)
            .filter(Boolean);
        let slug = '';
        for (const word of words) {
            const next = slug === '' ? word : `${slug}-${word}`;
            if (next.length > 50) {
                slug ||= word.slice(0, 50);
                break;
            }
            slug = next;
        }
        slug ||= 'conversation';
        const pad = (n) => String(n).padStart(2, '0');
        const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        return `ai-chat-${slug}-${date}.md`;
    }

    _formatExportTime(ts) {
        if (!ts) return '';
        try {
            return new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(ts));
        } catch {
            return '';
        }
    }

    renderMessageContent(msg) {
        const text = this._extractText(msg);
        return msg.role === 'assistant' ? renderMarkdown(text) : text;
    }

    _extractText(msg) {
        if (typeof msg.content === 'string') return msg.content;
        if (Array.isArray(msg.content)) {
            return msg.content.map(p => p.text || '').join('\n');
        }
        return JSON.stringify(msg.content);
    }
}
