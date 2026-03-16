import {LitElement, html, css, nothing} from 'lit';
import {ChatCoreController} from './chat-core.js';

const STATES = {HIDDEN: 'hidden', COLLAPSED: 'collapsed', EXPANDED: 'expanded', MAXIMIZED: 'maximized'};
const DEFAULT_HEIGHT = 350;
const COLLAPSED_HEIGHT = 36;
const STORAGE_KEY = 'ai-chat-panel';

/**
 * <ai-chat-panel> - Floating bottom panel for AI chat.
 *
 * Panel states: HIDDEN (display:none), COLLAPSED (header only),
 * EXPANDED (chat + compact conversation switcher),
 * MAXIMIZED (full height with sidebar).
 *
 * All chat logic is delegated to ChatCoreController.
 */
export class AiChatPanel extends LitElement {
    static properties = {
        state: {type: String, reflect: true},
        _height: {state: true},
    };

    static styles = css`
        :host {
            position: fixed;
            bottom: 0;
            right: 16px;
            width: 480px;
            max-width: calc(100vw - 32px);
            z-index: calc(var(--typo3-zindex-modal-backdrop, 1050) - 10);
            box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.15);
            font-family: var(--typo3-font-family, sans-serif);
            background: var(--typo3-surface-container-lowest, #fff);
            display: flex;
            flex-direction: column;
        }
        :host([state="hidden"]) {
            display: none;
        }

        /* Resize handle — larger hit area for easier drag */
        .resize-handle {
            height: 8px;
            cursor: ns-resize;
            background: transparent;
            width: 100%;
            flex-shrink: 0;
            position: relative;
            touch-action: none;
        }
        .resize-handle::before {
            content: '';
            position: absolute;
            top: -4px;
            left: 0;
            right: 0;
            height: 16px;
        }
        .resize-handle:hover,
        .resize-handle:active {
            background: var(--typo3-primary, #0078d4);
            opacity: 0.4;
        }
        .resize-handle:focus-visible {
            background: var(--typo3-primary, #0078d4);
            opacity: 0.6;
            outline: 2px solid var(--typo3-primary, #0078d4);
            outline-offset: -2px;
        }

        /* Panel header */
        .panel-header {
            height: 36px;
            min-height: 36px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            background: var(--typo3-surface-container-low, #f5f5f5);
            border-bottom: 1px solid var(--typo3-list-border-color, #ccc);
            cursor: default;
            flex-shrink: 0;
        }
        .panel-header .title {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
            font-weight: 600;
        }

        /* Panel body */
        .panel-body {
            flex: 1;
            display: flex;
            min-height: 0;
            overflow: hidden;
        }

        /* Sidebar (maximized only) */
        .panel-sidebar {
            width: 260px;
            min-width: 260px;
            border-right: 1px solid var(--typo3-list-border-color, #ccc);
            display: flex;
            flex-direction: column;
            background: var(--typo3-surface-container-low, #f5f5f5);
            overflow: hidden;
        }
        .panel-sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid var(--typo3-list-border-color, #ccc);
        }
        .panel-sidebar-header h3 {
            margin: 0;
            font-size: 13px;
        }
        .sidebar-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px 0;
        }
        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--typo3-list-border-color, #eee);
            transition: background 0.15s;
            font-size: 12px;
        }
        .sidebar-item:hover,
        .sidebar-item:focus-visible {
            background: var(--typo3-state-hover, rgba(0, 0, 0, 0.04));
        }
        .sidebar-item:focus-visible {
            outline: 2px solid var(--typo3-primary, #0078d4);
            outline-offset: -2px;
        }
        .sidebar-item.active {
            background: var(--typo3-state-active, rgba(0, 0, 0, 0.08));
        }
        .sidebar-item .item-title {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .sidebar-item-actions {
            display: flex;
            gap: 2px;
        }

        /* Content area */
        .panel-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* Compact conversation switcher (expanded state) */
        .compact-switcher {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-bottom: 1px solid var(--typo3-list-border-color, #ccc);
            background: var(--typo3-surface-container-low, #f5f5f5);
            flex-shrink: 0;
        }
        .compact-switcher select {
            flex: 1;
            padding: 4px 8px;
            border: 1px solid var(--typo3-input-border-color, #ccc);
            border-radius: 4px;
            font-size: 12px;
            background: var(--typo3-surface-container-lowest, #fff);
            min-width: 0;
        }

        /* Messages */
        .panel-messages {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .message {
            max-width: 85%;
            padding: 8px 12px;
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
            max-height: 80px;
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
        .panel-input {
            display: flex;
            gap: 8px;
            padding: 8px 12px;
            border-top: 1px solid var(--typo3-list-border-color, #ccc);
            background: var(--typo3-surface-container-low, #f5f5f5);
            flex-shrink: 0;
        }
        .panel-input textarea {
            flex: 1;
            resize: none;
            border: 1px solid var(--typo3-input-border-color, #ccc);
            border-radius: 4px;
            padding: 6px 10px;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.4;
            min-height: 34px;
            max-height: 120px;
            overflow-y: auto;
            background: var(--typo3-surface-container-lowest, #fff);
        }
        .panel-input textarea:focus {
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
            background: var(--typo3-state-hover, rgba(0, 0, 0, 0.04));
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
        .btn-icon:hover {
            background: var(--typo3-state-hover, rgba(0, 0, 0, 0.04));
            border-radius: 4px;
        }

        /* Status */
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
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
            font-size: 13px;
            text-align: center;
            padding: 16px;
        }

        .issues-banner {
            padding: 6px 12px;
            background: #fff3e0;
            border-bottom: 1px solid #ffe0b2;
            font-size: 12px;
            color: #e65100;
            flex-shrink: 0;
        }

        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-top-color: #0078d4;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    `;

    constructor() {
        super();
        this.chat = new ChatCoreController(this);
        this.state = STATES.HIDDEN;
        this._height = DEFAULT_HEIGHT;
        this._lastVisibleState = STATES.EXPANDED;
        this._resizing = false;
        this._restoreState();
    }

    connectedCallback() {
        super.connectedCallback();
        this.setAttribute('role', 'complementary');
        this.setAttribute('aria-label', 'AI Chat');
        this._keydownHandler = (e) => this._onKeydown(e);
        document.addEventListener('keydown', this._keydownHandler);
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        document.removeEventListener('keydown', this._keydownHandler);
    }

    updated(changed) {
        if (changed.has('state') || changed.has('_height')) {
            this._applySize();
        }
        if (changed.has('state')) {
            this.setAttribute('aria-expanded', String(this.state !== STATES.HIDDEN));
        }
    }

    _applySize() {
        if (this.state === STATES.HIDDEN) return;
        if (this.state === STATES.COLLAPSED) {
            this.style.height = COLLAPSED_HEIGHT + 'px';
            this.style.width = '';
            this.style.left = '';
            this.style.right = '16px';
        } else if (this.state === STATES.MAXIMIZED) {
            this.style.height = '100vh';
            this.style.width = '100vw';
            this.style.left = '0';
            this.style.right = '0';
        } else {
            this.style.height = this._height + 'px';
            this.style.width = '';
            this.style.left = '';
            this.style.right = '16px';
        }
    }

    // ── Public API ──────────────────────────────────────────────────────

    toggle() {
        if (this.state === STATES.HIDDEN) {
            this.state = this._lastVisibleState || STATES.EXPANDED;
            this.updateComplete.then(() => this.onFocusInput());
        } else {
            this._lastVisibleState = this.state;
            this.state = STATES.HIDDEN;
        }
        this._saveState();
    }

    collapse() {
        this.state = STATES.COLLAPSED;
        this._saveState();
    }

    hide() {
        this._lastVisibleState = this.state !== STATES.HIDDEN ? this.state : STATES.EXPANDED;
        this.state = STATES.HIDDEN;
        this._saveState();
    }

    maximize() {
        this.state = this.state === STATES.MAXIMIZED ? STATES.EXPANDED : STATES.MAXIMIZED;
        this._saveState();
    }

    // ── ChatCoreController callback hooks ───────────────────────────────

    onScrollToBottom(force = false) {
        requestAnimationFrame(() => {
            const el = this.renderRoot?.querySelector('.panel-messages');
            if (!el) return;
            if (force) {
                el.scrollTop = el.scrollHeight;
                return;
            }
            if (el.scrollHeight - el.scrollTop - el.clientHeight < 100) {
                el.scrollTop = el.scrollHeight;
            }
        });
    }

    onFocusInput() {
        this.updateComplete.then(() => {
            this.renderRoot?.querySelector('.panel-input textarea')?.focus();
        });
    }

    onResetInput() {
        const ta = this.renderRoot?.querySelector('.panel-input textarea');
        if (ta) ta.style.height = 'auto';
    }

    // ── Resize ──────────────────────────────────────────────────────────

    _onResizeStart(e) {
        e.preventDefault();
        this._resizing = true;
        this._startY = e.type.startsWith('touch') ? e.touches[0].clientY : e.clientY;
        this._startHeight = this.state === STATES.MAXIMIZED ? window.innerHeight : this._height;

        const onMove = (ev) => {
            const clientY = ev.type.startsWith('touch') ? ev.touches[0].clientY : ev.clientY;
            const delta = this._startY - clientY;
            let newHeight = this._startHeight + delta;
            const vh = window.innerHeight;
            newHeight = Math.max(COLLAPSED_HEIGHT, Math.min(newHeight, vh));

            if (newHeight < 50) {
                newHeight = COLLAPSED_HEIGHT;
                this.state = STATES.COLLAPSED;
            } else if (newHeight > vh * 0.9) {
                newHeight = vh;
                this.state = STATES.MAXIMIZED;
            } else {
                this.state = STATES.EXPANDED;
            }
            this._height = newHeight;
        };

        const onEnd = () => {
            this._resizing = false;
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onEnd);
            this._saveState();
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchmove', onMove, {passive: false});
        document.addEventListener('touchend', onEnd);
    }

    _onResizeKeydown(e) {
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            this._height = Math.min(this._height + 50, window.innerHeight);
            if (this._height > window.innerHeight * 0.9) this.state = 'maximized';
            else this.state = 'expanded';
            this._saveState();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            this._height = Math.max(this._height - 50, 36);
            if (this._height < 50) { this._height = 36; this.state = 'collapsed'; }
            else this.state = 'expanded';
            this._saveState();
        }
    }

    // ── Persistence ─────────────────────────────────────────────────────

    _saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                state: this.state,
                height: this._height,
                activeUid: this.chat.activeUid,
            }));
        } catch { /* ignore */ }
    }

    _restoreState() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            const data = JSON.parse(raw);
            if (data.state && data.state !== STATES.HIDDEN) {
                this._lastVisibleState = data.state;
            }
            if (typeof data.height === 'number' && data.height >= COLLAPSED_HEIGHT) {
                this._height = data.height;
            }
        } catch { /* ignore corrupted data */ }
    }

    // ── Keyboard ────────────────────────────────────────────────────────

    _onKeydown(e) {
        if (e.key === 'Escape' && this.state !== STATES.HIDDEN) {
            this.collapse();
        }
    }

    // ── Input handling ──────────────────────────────────────────────────

    _handleInput(e) {
        this.chat.inputValue = e.target.value;
        this.chat.hasInput = e.target.value.trim().length > 0;
        this.chat.host.requestUpdate();
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
    }

    _handleKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.chat.handleSend().catch(() => {});
        }
    }

    // ── Render ──────────────────────────────────────────────────────────

    render() {
        if (this.state === STATES.HIDDEN) return nothing;

        return html`
            <div class="resize-handle"
                 role="separator"
                 aria-orientation="horizontal"
                 aria-label="Resize panel"
                 tabindex="0"
                 @mousedown=${(e) => this._onResizeStart(e)}
                 @touchstart=${(e) => this._onResizeStart(e)}
                 @keydown=${(e) => this._onResizeKeydown(e)}></div>
            ${this._renderHeader()}
            ${this.state === STATES.COLLAPSED ? nothing : this._renderBody()}
        `;
    }

    _renderHeader() {
        const conv = this.chat.getActiveConversation();
        const title = conv?.title || 'AI Chat';

        return html`
            <div class="panel-header">
                <span class="title">${title}</span>
                ${this.chat.status ? html`
                    <span class="status-badge status-${this.chat.status}">${this.chat.status}</span>
                ` : nothing}
                <button class="btn-icon" @click=${() => this.collapse()}
                        title="Collapse" aria-label="Collapse panel">&#x2015;</button>
                <button class="btn-icon" @click=${() => this.maximize()}
                        title="${this.state === STATES.MAXIMIZED ? 'Restore' : 'Maximize'}"
                        aria-label="${this.state === STATES.MAXIMIZED ? 'Restore panel' : 'Maximize panel'}">
                    ${this.state === STATES.MAXIMIZED ? '\u2913' : '\u2912'}
                </button>
                <button class="btn-icon" @click=${() => this.hide()}
                        title="Close" aria-label="Close panel">&times;</button>
            </div>
        `;
    }

    _renderBody() {
        if (this.chat.loading) {
            return html`<div class="panel-body"><div class="empty-state"><span class="spinner"></span></div></div>`;
        }

        return html`
            ${this.chat.issues.length > 0 ? html`
                <div class="issues-banner">
                    ${this.chat.issues.map(i => html`<div>${i}</div>`)}
                </div>
            ` : nothing}
            <div class="panel-body">
                ${this.state === STATES.MAXIMIZED ? this._renderSidebar() : nothing}
                <div class="panel-content">
                    ${this.state === STATES.EXPANDED ? this._renderCompactSwitcher() : nothing}
                    ${this._renderChat()}
                </div>
            </div>
        `;
    }

    _renderSidebar() {
        return html`
            <div class="panel-sidebar">
                <div class="panel-sidebar-header">
                    <h3>Conversations</h3>
                    <button class="btn btn-sm btn-primary"
                            @click=${() => this.chat.handleNewConversation()}
                            ?disabled=${!this.chat.available}
                            aria-label="Create new conversation">+ New</button>
                </div>
                <div class="sidebar-list" role="listbox" aria-label="Conversations">
                    ${this.chat.conversations.length === 0
                        ? html`<div class="empty-state" style="font-size:12px;">No conversations yet</div>`
                        : this.chat.conversations.map(c => this._renderSidebarItem(c))
                    }
                </div>
            </div>
        `;
    }

    _renderSidebarItem(c) {
        const isActive = c.uid === this.chat.activeUid;
        return html`
            <div class="sidebar-item ${isActive ? 'active' : ''}"
                 role="option"
                 tabindex="0"
                 aria-selected="${isActive}"
                 @click=${() => this.chat.selectConversation(c.uid)}
                 @keydown=${(e) => {
                     if (e.key === 'Enter' || e.key === ' ') {
                         e.preventDefault();
                         this.chat.selectConversation(c.uid);
                     }
                 }}>
                <span class="item-title">
                    ${c.pinned ? '\u{1F4CC} ' : ''}${c.title || 'New conversation'}
                </span>
                <span class="status-badge status-${c.status}">${c.status}</span>
                ${isActive ? html`
                    <span class="sidebar-item-actions">
                        <button class="btn-icon btn-sm" @click=${(e) => { e.stopPropagation(); this.chat.handleTogglePin(); }}
                                title="${c.pinned ? 'Unpin' : 'Pin'}"
                                aria-label="${c.pinned ? 'Unpin conversation' : 'Pin conversation'}">
                            ${c.pinned ? '\u{1F4CC}' : '\u{1F4CC}'}
                        </button>
                        <button class="btn-icon btn-sm" @click=${(e) => { e.stopPropagation(); this.chat.handleArchive(); }}
                                title="Archive" aria-label="Archive conversation">
                            \u{1F5C4}
                        </button>
                    </span>
                ` : nothing}
            </div>
        `;
    }

    _renderCompactSwitcher() {
        return html`
            <div class="compact-switcher">
                <select @change=${(e) => this.chat.selectConversation(Number(e.target.value))}
                        aria-label="Select conversation">
                    ${!this.chat.activeUid ? html`<option value="" selected disabled>Select conversation...</option>` : nothing}
                    ${this.chat.conversations.map(c => html`
                        <option value=${c.uid} ?selected=${c.uid === this.chat.activeUid}>
                            ${c.pinned ? '\u{1F4CC} ' : ''}${c.title || 'New conversation'}
                        </option>
                    `)}
                </select>
                <button class="btn btn-sm btn-primary"
                        @click=${() => this.chat.handleNewConversation()}
                        ?disabled=${!this.chat.available}
                        aria-label="Create new conversation">+ New</button>
            </div>
        `;
    }

    _renderChat() {
        if (!this.chat.activeUid) {
            return html`
                <div class="empty-state">
                    ${this.chat.available
                        ? 'Select a conversation or create a new one'
                        : 'AI Chat is not available. Check extension configuration.'
                    }
                </div>
            `;
        }

        const conv = this.chat.getActiveConversation();
        const isResumable = conv?.resumable || false;

        return html`
            <div class="panel-messages" aria-live="polite" aria-relevant="additions">
                ${this.chat.messages.map((msg, idx) => this._renderMessage(msg, idx))}
                ${this.chat.isProcessing() ? html`
                    <div class="message system"><span class="spinner"></span> Processing...</div>
                ` : nothing}
                ${this.chat.errorMessage ? html`
                    <div class="message system" style="color:#c62828;">
                        Error: ${this.chat.errorMessage}
                        ${isResumable ? html`
                            <button class="btn btn-sm" @click=${() => this.chat.handleResume()}
                                    style="margin-left:8px;">Retry</button>
                        ` : nothing}
                        <button class="btn btn-sm btn-icon" @click=${() => { this.chat.errorMessage = ''; this.requestUpdate(); }}
                                style="margin-left:4px;" title="Dismiss" aria-label="Dismiss error">&times;</button>
                    </div>
                ` : nothing}
            </div>
            ${this._renderInput()}
        `;
    }

    _renderMessage(msg, idx) {
        const role = msg.role || 'system';
        if (role === 'assistant' && msg.tool_calls && !msg.content) return nothing;

        if (role === 'tool') {
            const isExpanded = this.chat.expandedTools.has(idx);
            return html`
                <div class="message tool ${isExpanded ? 'expanded' : ''}"
                     role="button"
                     tabindex="0"
                     aria-label="Tool output, activate to expand"
                     aria-expanded="${isExpanded}"
                     @click=${() => this.chat.handleToolMessageClick(idx)}
                     @keydown=${(e) => {
                         if (e.key === 'Enter' || e.key === ' ') {
                             e.preventDefault();
                             this.chat.handleToolMessageClick(idx);
                         }
                     }}>
                    ${this.chat.renderMessageContent(msg)}
                </div>
            `;
        }

        return html`
            <div class="message ${role}">
                ${this.chat.renderMessageContent(msg)}
            </div>
        `;
    }

    _renderInput() {
        return html`
            <div class="panel-input">
                <textarea
                    .value=${this.chat.inputValue}
                    @input=${this._handleInput}
                    @keydown=${this._handleKeydown}
                    placeholder="Type a message... (Enter to send)"
                    aria-label="Type your message"
                    ?disabled=${!this.chat.available || this.chat.isProcessing()}
                    rows="1"
                ></textarea>
                <button class="btn btn-primary btn-sm"
                        @click=${() => this.chat.handleSend()}
                        aria-label="Send message"
                        ?disabled=${!this.chat.hasInput || this.chat.sending || this.chat.isProcessing() || !this.chat.available}>
                    ${this.chat.sending ? html`<span class="spinner"></span>` : 'Send'}
                </button>
            </div>
        `;
    }
}

customElements.define('ai-chat-panel', AiChatPanel);
