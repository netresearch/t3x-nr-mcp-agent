import {ApiClient} from './api-client.js';

export const PROCESSING_STATUSES = new Set(['processing', 'locked', 'tool_loop']);

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
    status = '';
    errorMessage = '';
    inputValue = '';
    hasInput = false;
    loading = true;
    sending = false;
    available = false;
    issues = [];
    maxLength = 0;
    /** @type {Set<number>} */
    expandedTools = new Set();

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
        this.init();
    }

    hostDisconnected() {
        this._abortController?.abort();
        this.stopPolling();
    }

    // ── Core logic ─────────────────────────────────────────────────────

    async init() {
        const signal = this._abortController?.signal;
        try {
            const statusData = await this._api.getStatus();
            if (signal?.aborted) return;
            this.available = statusData.available;
            this.issues = statusData.issues || [];
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
        this.host.requestUpdate();
        await this.loadMessages();
        this.startPollingIfNeeded();
        this.host.onFocusInput();
    }

    async loadMessages() {
        if (!this.activeUid) return;
        try {
            const data = await this._api.getMessages(this.activeUid, 0);
            this.messages = data.messages || [];
            this.status = data.status;
            this.errorMessage = data.errorMessage || '';
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
            const newMessages = data.messages || [];
            const statusChanged = data.status !== this.status;

            if (newMessages.length > 0 || statusChanged) {
                if (newMessages.length > 0) {
                    this.messages = [...this.messages, ...newMessages];
                }
                this.status = data.status;
                this.errorMessage = data.errorMessage || '';
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
            if (this.errorMessage === 'Connection lost. Retrying...') {
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
                this.errorMessage = 'Connection lost. Retrying...';
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

    isProcessing() {
        return PROCESSING_STATUSES.has(this.status);
    }

    async handleSend() {
        const content = this.inputValue.trim();
        if (!content || this.sending || this.isProcessing()) return;

        if (this.maxLength > 0 && content.length > this.maxLength) {
            this.errorMessage = `Message too long (max ${this.maxLength} characters)`;
            this.host.requestUpdate();
            return;
        }

        this.sending = true;
        this.errorMessage = '';
        this.host.requestUpdate();
        try {
            await this._api.sendMessage(this.activeUid, content);
            this.inputValue = '';
            this.hasInput = false;
            this.host.onResetInput();
            // Optimistic: add user message locally
            this.messages = [...this.messages, {role: 'user', content}];
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

    async handleArchive() {
        if (!this.activeUid) return;
        if (!confirm('Archive this conversation? It will be removed from the list.')) return;
        try {
            await this._api.archiveConversation(this.activeUid);
            this.activeUid = null;
            this.messages = [];
            this.status = '';
            this.errorMessage = '';
            this.stopPolling();
            await this.loadConversations();
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

    renderMessageContent(msg) {
        if (typeof msg.content === 'string') return msg.content;
        if (Array.isArray(msg.content)) {
            return msg.content.map(p => p.text || '').join('\n');
        }
        return JSON.stringify(msg.content);
    }
}
