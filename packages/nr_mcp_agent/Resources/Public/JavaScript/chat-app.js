import {LitElement, html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';
import {ChatCoreController} from './chat-core.js';

/**
 * <nr-chat-app> – Main chat application component.
 *
 * Renders a sidebar with conversation list and a main area with messages.
 * All chat business logic is delegated to ChatCoreController.
 */
export class ChatApp extends LitElement {
    static properties = {
        maxLength: {type: Number, attribute: 'data-max-length'},
        _sidebarCollapsed: {state: true},
    };

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
        this.maxLength = 0;
        this._sidebarCollapsed = false;
        this.chat = new ChatCoreController(this);
    }

    connectedCallback() {
        super.connectedCallback();
        this.chat.maxLength = this.maxLength || 0;
    }

    // ── Callback hooks for ChatCoreController ──────────────────────────

    onScrollToBottom(force = false) {
        const doScroll = () => {
            const container = this.renderRoot?.querySelector('.messages');
            if (!container) return;
            if (force) {
                container.scrollTop = container.scrollHeight;
                return;
            }
            const isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
            if (isNearBottom) {
                container.scrollTop = container.scrollHeight;
            }
        };
        // Ensure DOM is updated before scrolling
        this.updateComplete.then(() => doScroll());
    }

    onFocusInput() {
        this.updateComplete.then(() => {
            this.renderRoot?.querySelector('.input-area textarea')?.focus();
        });
    }

    onResetInput() {
        const ta = this.renderRoot?.querySelector('.input-area textarea');
        if (ta) ta.style.height = 'auto';
    }

    // ── DOM-specific event handlers ────────────────────────────────────

    _handleInput(e) {
        this.chat.inputValue = e.target.value;
        const newHasInput = e.target.value.trim().length > 0;
        if (newHasInput !== this.chat.hasInput) {
            this.chat.hasInput = newHasInput;
            this.requestUpdate();
        }
        e.target.style.height = 'auto';
        e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
    }

    _handleKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.chat.handleSend().catch(() => {});
        }
    }

    // ── Render ─────────────────────────────────────────────────────────

    render() {
        if (this.chat.loading) {
            return html`<div class="empty-state"><span class="spinner"></span></div>`;
        }

        return html`
            ${this.chat.issues.length > 0 ? html`
                <div class="issues-banner">
                    ${this.chat.issues.map(i => html`<div>${i}</div>`)}
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
                <h3>${lll('conversations.title')}</h3>
                <button class="btn btn-sm btn-primary"
                    @click=${() => this.chat.handleNewConversation()}
                    ?disabled=${!this.chat.available}
                    aria-label="${lll('conversations.new')}">
                    ${lll('conversations.new')}
                </button>
            </div>
            <div class="conversation-list" role="listbox" aria-label="${lll('conversations.title')}">
                ${this.chat.conversations.length === 0
                    ? html`<div class="empty-state" style="font-size:12px;">${lll('conversations.empty')}</div>`
                    : this.chat.conversations.map(c => this._renderConversationItem(c))
                }
            </div>
        `;
    }

    _renderConversationItem(c) {
        const isActive = c.uid === this.chat.activeUid;
        return html`
            <div class="conversation-item ${isActive ? 'active' : ''}"
                 role="option"
                 tabindex="0"
                 aria-selected="${isActive}"
                 @click=${() => this.chat.selectConversation(c.uid)}
                 @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.chat.selectConversation(c.uid); } }}>
                <div class="title">
                    ${c.pinned ? '\u{1F4CC} ' : ''}${c.title || lll('conversations.newConversation')}
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
                title="${this._sidebarCollapsed ? lll('sidebar.show') : lll('sidebar.hide')}"
                aria-label="${this._sidebarCollapsed ? lll('sidebar.show') : lll('sidebar.hide')}">
                ${this._sidebarCollapsed ? '\u2630' : '\u2039'}
            </button>
        `;
    }

    _renderMain() {
        if (!this.chat.activeUid) {
            return html`
                <div class="main-header">
                    ${this._renderToggleButton()}
                </div>
                <div class="empty-state">
                    ${this.chat.available
                        ? lll('chat.selectOrCreate')
                        : lll('chat.notAvailable')
                    }
                </div>
            `;
        }

        const conv = this.chat.getActiveConversation();
        const isResumable = conv?.resumable || false;

        return html`
            <div class="main-header">
                ${this._renderToggleButton()}
                <strong style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    ${conv?.title || lll('conversations.newConversation')}
                </strong>
                <button class="btn btn-sm" @click=${() => this.chat.handleTogglePin()}
                    title="${conv?.pinned ? lll('conversations.unpin') : lll('conversations.pin')}">
                    ${conv?.pinned ? '\u{1F4CC}' : lll('conversations.pin')}
                </button>
                <button class="btn btn-sm" @click=${() => this.chat.handleArchive()}>${lll('conversations.archive')}</button>
            </div>

            <div class="messages" aria-live="polite" aria-relevant="additions">
                ${this.chat.messages.map((msg, idx) => this._renderMessage(msg, idx))}
                ${this.chat.isProcessing() ? html`
                    <div class="message system"><span class="spinner"></span> ${lll('chat.processing')}</div>
                ` : nothing}
                ${this.chat.errorMessage ? html`
                    <div class="message system" style="color:#c62828;">
                        Error: ${this.chat.errorMessage}
                        ${isResumable ? html`
                            <button class="btn btn-sm" @click=${() => this.chat.handleResume()}
                                style="margin-left:8px;">${lll('chat.retry')}</button>
                        ` : nothing}
                        <button class="btn btn-sm btn-icon" @click=${() => { this.chat.errorMessage = ''; this.requestUpdate(); }}
                            style="margin-left:4px;" title="${lll('chat.dismiss')}" aria-label="${lll('chat.dismiss')}">&times;</button>
                    </div>
                ` : nothing}
            </div>

            <div class="input-area">
                <textarea
                    .value=${this.chat.inputValue}
                    @input=${this._handleInput}
                    @keydown=${this._handleKeydown}
                    placeholder="${lll('chat.placeholder')}"
                    aria-label="${lll('chat.placeholder')}"
                    ?disabled=${!this.chat.available || this.chat.isProcessing()}
                    maxlength=${this.maxLength > 0 ? this.maxLength : nothing}
                    rows="1"
                ></textarea>
                <button class="btn btn-primary"
                    @click=${() => this.chat.handleSend()}
                    aria-label="${lll('chat.send')}"
                    ?disabled=${!this.chat.hasInput || this.chat.sending || this.chat.isProcessing() || !this.chat.available}>
                    ${this.chat.sending ? html`<span class="spinner"></span>` : lll('chat.send')}
                </button>
            </div>
        `;
    }

    _renderMessage(msg, idx) {
        const role = msg.role || 'system';
        // Skip tool-call assistant messages (just show the tool results)
        if (role === 'assistant' && msg.tool_calls && !msg.content) return nothing;

        if (role === 'tool') {
            const isExpanded = this.chat.expandedTools.has(idx);
            return html`
                <div class="message tool ${isExpanded ? 'expanded' : ''}"
                     role="button"
                     tabindex="0"
                     aria-label="${lll('tool.output')}"
                     aria-expanded="${isExpanded}"
                     @click=${() => this.chat.handleToolMessageClick(idx)}
                     @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.chat.handleToolMessageClick(idx); } }}>
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
}

customElements.define('nr-chat-app', ChatApp);
