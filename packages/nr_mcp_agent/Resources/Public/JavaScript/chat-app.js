import {LitElement, html, css, nothing} from 'lit';
import {ApiClient} from './api-client.js';

/**
 * <nr-chat-app> – Main chat application component.
 *
 * Renders a sidebar with conversation list and a main area with messages.
 */
export class ChatApp extends LitElement {
    static properties = {
        maxLength: {type: Number, attribute: 'data-max-length'},
        _conversations: {state: true},
        _activeUid: {state: true},
        _messages: {state: true},
        _status: {state: true},
        _errorMessage: {state: true},
        _loading: {state: true},
        _sending: {state: true},
        _available: {state: true},
        _issues: {state: true},
        _inputValue: {state: true},
        _sidebarCollapsed: {state: true},
    };

    /** @type {ApiClient} */
    _api;
    /** @type {number|null} */
    _pollTimer = null;
    /** @type {number} */
    _knownMessageCount = 0;

    static styles = css`
        :host {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 200px);
            min-height: 400px;
            border: 1px solid var(--typo3-list-border-color, #ccc);
            border-radius: 4px;
            overflow: hidden;
            font-family: var(--typo3-font-family, sans-serif);
            background: var(--typo3-surface-container-lowest, #fff);
        }

        .chat-body {
            display: flex;
            flex: 1;
            min-height: 0;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            min-width: 280px;
            border-right: 1px solid var(--typo3-list-border-color, #ccc);
            display: flex;
            flex-direction: column;
            background: var(--typo3-surface-container-low, #f5f5f5);
        }
        .sidebar.collapsed {
            width: 0;
            min-width: 0;
            overflow: hidden;
            border-right: none;
        }
        .sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid var(--typo3-list-border-color, #ccc);
        }
        .sidebar-header h3 {
            margin: 0;
            font-size: 14px;
        }
        .conversation-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px 0;
        }
        .conversation-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--typo3-list-border-color, #eee);
            transition: background 0.15s;
        }
        .conversation-item:hover,
        .conversation-item:focus-visible {
            background: var(--typo3-state-hover, rgba(0,0,0,0.04));
        }
        .conversation-item:focus-visible {
            outline: 2px solid var(--typo3-primary, #0078d4);
            outline-offset: -2px;
        }
        .conversation-item.active {
            background: var(--typo3-state-active, rgba(0,0,0,0.08));
        }
        .conversation-item .title {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
        }
        .conversation-item .meta {
            font-size: 11px;
            color: var(--typo3-text-color-variant, #666);
        }

        /* Main area */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .main-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-bottom: 1px solid var(--typo3-list-border-color, #ccc);
            min-height: 44px;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .message {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .message.user {
            align-self: flex-end;
            background: #0078d4;
            color: #fff;
            border-bottom-right-radius: 2px;
        }
        .message.assistant {
            align-self: flex-start;
            background: var(--typo3-surface-container-high, #e8e8e8);
            border-bottom-left-radius: 2px;
        }
        .message.tool {
            align-self: flex-start;
            background: var(--typo3-surface-container, #f0f0f0);
            font-size: 12px;
            font-family: monospace;
            opacity: 0.7;
            max-height: 100px;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .message.tool.expanded {
            max-height: none;
        }
        .message.tool:not(.expanded)::after {
            content: '... click to expand';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 24px;
            background: linear-gradient(transparent, var(--typo3-surface-container, #f0f0f0));
            display: flex;
            align-items: flex-end;
            justify-content: center;
            font-size: 11px;
            font-family: sans-serif;
        }
        .message.system {
            align-self: center;
            font-size: 12px;
            color: var(--typo3-text-color-variant, #666);
            font-style: italic;
        }

        /* Input area */
        .input-area {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid var(--typo3-list-border-color, #ccc);
            background: var(--typo3-surface-container-low, #f5f5f5);
        }
        .input-area textarea {
            flex: 1;
            resize: none;
            border: 1px solid var(--typo3-input-border-color, #ccc);
            border-radius: 4px;
            padding: 8px 12px;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.4;
            min-height: 40px;
            max-height: 120px;
            overflow-y: auto;
            background: var(--typo3-surface-container-lowest, #fff);
        }
        .input-area textarea:focus {
            outline: none;
            border-color: var(--typo3-primary, #0078d4);
            box-shadow: 0 0 0 1px var(--typo3-primary, #0078d4);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 6px 12px;
            border: 1px solid var(--typo3-input-border-color, #ccc);
            border-radius: 4px;
            background: var(--typo3-surface-container-lowest, #fff);
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
            transition: background 0.15s;
        }
        .btn:hover {
            background: var(--typo3-state-hover, rgba(0,0,0,0.04));
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-primary {
            background: #0078d4;
            color: #fff;
            border-color: transparent;
        }
        .btn-primary:hover:not(:disabled) {
            background: #006abc;
        }
        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        .btn-icon {
            padding: 4px 6px;
            border: none;
            background: transparent;
        }

        /* Status indicators */
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-idle { background: #e8f5e9; color: #2e7d32; }
        .status-processing, .status-locked, .status-tool_loop {
            background: #fff3e0; color: #e65100;
        }
        .status-failed { background: #ffebee; color: #c62828; }

        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--typo3-text-color-variant, #666);
            font-size: 14px;
            text-align: center;
            padding: 24px;
        }

        .issues-banner {
            padding: 8px 12px;
            background: #fff3e0;
            border-bottom: 1px solid #ffe0b2;
            font-size: 12px;
            color: #e65100;
        }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(0,0,0,0.1);
            border-top-color: #0078d4;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    `;

    constructor() {
        super();
        this._api = new ApiClient();
        this.maxLength = 0;
        this._conversations = [];
        this._activeUid = null;
        this._messages = [];
        this._status = '';
        this._errorMessage = '';
        this._inputValue = '';
        this._loading = true;
        this._sending = false;
        this._available = false;
        this._issues = [];
        this._sidebarCollapsed = false;
    }

    connectedCallback() {
        super.connectedCallback();
        this._abortController = new AbortController();
        this._init();
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        this._abortController?.abort();
        this._stopPolling();
    }

    async _init() {
        const signal = this._abortController?.signal;
        try {
            const statusData = await this._api.getStatus();
            if (signal?.aborted) return;
            this._available = statusData.available;
            this._issues = statusData.issues || [];
            await this._loadConversations();
        } catch (e) {
            if (signal?.aborted) return;
            this._issues = [e.message];
        } finally {
            if (!signal?.aborted) this._loading = false;
        }
    }

    async _loadConversations() {
        const data = await this._api.listConversations();
        this._conversations = data.conversations || [];
    }

    async _selectConversation(uid) {
        this._activeUid = uid;
        this._knownMessageCount = 0;
        await this._loadMessages();
        this._startPollingIfNeeded();
        await this.updateComplete;
        this.renderRoot?.querySelector('.input-area textarea')?.focus();
    }

    async _loadMessages() {
        if (!this._activeUid) return;
        try {
            const data = await this._api.getMessages(this._activeUid, 0);
            this._messages = data.messages || [];
            this._status = data.status;
            this._errorMessage = data.errorMessage || '';
            this._knownMessageCount = data.totalCount;
            await this.updateComplete;
            this._scrollToBottom();
        } catch (e) {
            this._errorMessage = e.message;
        }
    }

    async _pollMessages() {
        if (!this._activeUid) return;
        try {
            const data = await this._api.getMessages(this._activeUid, this._knownMessageCount);
            const newMessages = data.messages || [];
            const statusChanged = data.status !== this._status;

            if (newMessages.length > 0 || statusChanged) {
                if (newMessages.length > 0) {
                    this._messages = [...this._messages, ...newMessages];
                }
                this._status = data.status;
                this._errorMessage = data.errorMessage || '';
                this._knownMessageCount = data.totalCount;
                // Update active conversation status in-place (avoids extra request)
                this._conversations = this._conversations.map(c =>
                    c.uid === this._activeUid
                        ? {...c, status: data.status, errorMessage: data.errorMessage || ''}
                        : c
                );
                await this.updateComplete;
                this._scrollToBottom();
            }

            // Stop polling when no longer processing
            if (!this._isProcessing()) {
                this._stopPolling();
            }
        } catch {
            // Silently ignore polling errors
        }
    }

    _startPollingIfNeeded() {
        this._stopPolling();
        if (this._isProcessing()) {
            this._schedulePoll();
        }
    }

    _schedulePoll() {
        this._pollTimer = setTimeout(async () => {
            await this._pollMessages();
            if (this._isProcessing()) {
                this._schedulePoll();
            }
        }, 2000);
    }

    _stopPolling() {
        if (this._pollTimer) {
            clearTimeout(this._pollTimer);
            this._pollTimer = null;
        }
    }

    _isProcessing() {
        return ['processing', 'locked', 'tool_loop'].includes(this._status);
    }

    async _handleSend() {
        const content = this._inputValue.trim();
        if (!content || this._sending || this._isProcessing()) return;

        if (this.maxLength > 0 && content.length > this.maxLength) {
            this._errorMessage = `Message too long (max ${this.maxLength} characters)`;
            return;
        }

        this._sending = true;
        this._errorMessage = '';
        try {
            await this._api.sendMessage(this._activeUid, content);
            this._inputValue = '';
            // Reset textarea height
            const ta = this.renderRoot?.querySelector('.input-area textarea');
            if (ta) ta.style.height = 'auto';
            // Optimistic: add user message locally
            this._messages = [...this._messages, {role: 'user', content}];
            this._status = 'processing';
            this._knownMessageCount++;
            this._conversations = this._conversations.map(c =>
                c.uid === this._activeUid ? {...c, status: 'processing'} : c
            );
            await this.updateComplete;
            this._scrollToBottom();
            this._startPollingIfNeeded();
        } catch (e) {
            this._errorMessage = e.message;
        } finally {
            this._sending = false;
        }
    }

    async _handleNewConversation() {
        try {
            const data = await this._api.createConversation();
            await this._loadConversations();
            await this._selectConversation(data.uid);
        } catch (e) {
            this._errorMessage = e.message;
        }
    }

    async _handleResume() {
        if (!this._activeUid) return;
        try {
            await this._api.resumeConversation(this._activeUid);
            this._status = 'processing';
            this._errorMessage = '';
            this._conversations = this._conversations.map(c =>
                c.uid === this._activeUid ? {...c, status: 'processing'} : c
            );
            this._startPollingIfNeeded();
        } catch (e) {
            this._errorMessage = e.message;
        }
    }

    async _handleArchive() {
        if (!this._activeUid) return;
        if (!confirm('Archive this conversation? It will be removed from the list.')) return;
        try {
            await this._api.archiveConversation(this._activeUid);
            this._activeUid = null;
            this._messages = [];
            this._status = '';
            this._stopPolling();
            await this._loadConversations();
        } catch (e) {
            this._errorMessage = e.message;
        }
    }

    async _handleTogglePin() {
        if (!this._activeUid) return;
        try {
            await this._api.togglePin(this._activeUid);
            await this._loadConversations();
        } catch (e) {
            this._errorMessage = e.message;
        }
    }

    _handleKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this._handleSend().catch(() => {});
        }
    }

    _handleInput(e) {
        this._inputValue = e.target.value;
        // Auto-grow textarea
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
    }

    _handleToolMessageClick(e) {
        e.currentTarget.classList.toggle('expanded');
    }

    _scrollToBottom() {
        const el = this.renderRoot?.querySelector('.messages');
        if (el) el.scrollTop = el.scrollHeight;
    }

    _getActiveConversation() {
        return this._conversations.find(c => c.uid === this._activeUid);
    }

    _formatTime(tstamp) {
        if (!tstamp) return '';
        const d = new Date(tstamp * 1000);
        const now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
        }
        return d.toLocaleDateString([], {month: 'short', day: 'numeric'});
    }

    _renderMessageContent(msg) {
        if (typeof msg.content === 'string') return msg.content;
        if (Array.isArray(msg.content)) {
            return msg.content.map(p => p.text || '').join('\n');
        }
        return JSON.stringify(msg.content);
    }

    render() {
        if (this._loading) {
            return html`<div class="empty-state"><span class="spinner"></span></div>`;
        }

        return html`
            ${this._issues.length > 0 ? html`
                <div class="issues-banner">
                    ${this._issues.map(i => html`<div>${i}</div>`)}
                </div>
            ` : nothing}
            <div class="chat-body">
                <div class="sidebar ${this._sidebarCollapsed ? 'collapsed' : ''}">
                    ${this._renderSidebar()}
                </div>
                <div class="main">
                    ${this._renderMain()}
                </div>
            </div>
        `;
    }

    _renderSidebar() {
        return html`
            <div class="sidebar-header">
                <h3>Conversations</h3>
                <button class="btn btn-sm btn-primary"
                    @click=${this._handleNewConversation}
                    ?disabled=${!this._available}
                    aria-label="Create new conversation">
                    + New
                </button>
            </div>
            <div class="conversation-list" role="listbox" aria-label="Conversations">
                ${this._conversations.length === 0
                    ? html`<div class="empty-state" style="font-size:12px;">No conversations yet</div>`
                    : this._conversations.map(c => this._renderConversationItem(c))
                }
            </div>
        `;
    }

    _renderConversationItem(c) {
        const isActive = c.uid === this._activeUid;
        return html`
            <div class="conversation-item ${isActive ? 'active' : ''}"
                 role="option"
                 tabindex="0"
                 aria-selected="${isActive}"
                 @click=${() => this._selectConversation(c.uid)}
                 @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this._selectConversation(c.uid); } }}>
                <div class="title">
                    ${c.pinned ? '\u{1F4CC} ' : ''}${c.title || 'New conversation'}
                </div>
                <div class="meta">
                    <span class="status-badge status-${c.status}">${c.status}</span>
                </div>
            </div>
        `;
    }

    _renderToggleButton() {
        return html`
            <button class="btn btn-icon"
                @click=${() => this._sidebarCollapsed = !this._sidebarCollapsed}
                title="${this._sidebarCollapsed ? 'Show sidebar' : 'Hide sidebar'}"
                aria-label="${this._sidebarCollapsed ? 'Show sidebar' : 'Hide sidebar'}">
                ${this._sidebarCollapsed ? '\u2630' : '\u2039'}
            </button>
        `;
    }

    _renderMain() {
        if (!this._activeUid) {
            return html`
                <div class="main-header">
                    ${this._renderToggleButton()}
                </div>
                <div class="empty-state">
                    ${this._available
                        ? 'Select a conversation or create a new one'
                        : 'AI Chat is not available. Check extension configuration.'
                    }
                </div>
            `;
        }

        const conv = this._getActiveConversation();
        const isResumable = conv?.resumable || false;

        return html`
            <div class="main-header">
                ${this._renderToggleButton()}
                <strong style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    ${conv?.title || 'New conversation'}
                </strong>
                <button class="btn btn-sm" @click=${this._handleTogglePin}
                    title="${conv?.pinned ? 'Unpin' : 'Pin'}">
                    ${conv?.pinned ? '\u{1F4CC}' : 'Pin'}
                </button>
                <button class="btn btn-sm" @click=${this._handleArchive}>Archive</button>
            </div>

            <div class="messages" aria-live="polite" aria-relevant="additions">
                ${this._messages.map(msg => this._renderMessage(msg))}
                ${this._isProcessing() ? html`
                    <div class="message system"><span class="spinner"></span> Processing...</div>
                ` : nothing}
                ${this._errorMessage ? html`
                    <div class="message system" style="color:#c62828;">
                        Error: ${this._errorMessage}
                        ${isResumable ? html`
                            <button class="btn btn-sm" @click=${this._handleResume}
                                style="margin-left:8px;">Retry</button>
                        ` : nothing}
                        <button class="btn btn-sm btn-icon" @click=${() => this._errorMessage = ''}
                            style="margin-left:4px;" title="Dismiss" aria-label="Dismiss error">&times;</button>
                    </div>
                ` : nothing}
            </div>

            <div class="input-area">
                <textarea
                    .value=${this._inputValue}
                    @input=${this._handleInput}
                    @keydown=${this._handleKeydown}
                    placeholder="Type a message... (Enter to send, Shift+Enter for newline)"
                    ?disabled=${!this._available || this._isProcessing()}
                    maxlength=${this.maxLength > 0 ? this.maxLength : nothing}
                    rows="1"
                ></textarea>
                <button class="btn btn-primary"
                    @click=${this._handleSend}
                    aria-label="Send message"
                    ?disabled=${!this._inputValue.trim() || this._sending || this._isProcessing() || !this._available}>
                    ${this._sending ? html`<span class="spinner"></span>` : 'Send'}
                </button>
            </div>
        `;
    }

    _renderMessage(msg) {
        const role = msg.role || 'system';
        // Skip tool-call assistant messages (just show the tool results)
        if (role === 'assistant' && msg.tool_calls && !msg.content) return nothing;

        if (role === 'tool') {
            return html`
                <div class="message tool"
                     role="button"
                     tabindex="0"
                     aria-label="Tool output, activate to expand"
                     @click=${this._handleToolMessageClick}
                     @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this._handleToolMessageClick(e); } }}>
                    ${this._renderMessageContent(msg)}
                </div>
            `;
        }

        return html`
            <div class="message ${role}">
                ${this._renderMessageContent(msg)}
            </div>
        `;
    }
}

customElements.define('nr-chat-app', ChatApp);
