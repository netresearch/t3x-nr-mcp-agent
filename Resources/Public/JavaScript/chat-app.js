// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {LitElement, html, css, nothing} from 'lit';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import {lll} from '@typo3/core/lit-helper.js';
import {ChatCoreController, displayStatus, downloadTextFile} from '@netresearch/nr-mcp-agent/chat-core.js';
import {markdownStyles} from '@netresearch/nr-mcp-agent/markdown-styles.js';
import {themeStyles} from '@netresearch/nr-mcp-agent/theme.js';
import {AVATAR_ASSISTANT, AVATAR_USER, ICON_PAPERCLIP, ICON_SEND, ICON_COMPOSE, ICON_CHEVRON_DOWN, ICON_UPLOAD, ICON_DOWNLOAD, ICON_INSTRUCTIONS, ICON_ACTIVITY} from '@netresearch/nr-mcp-agent/icons.js';
import {chatActivityStyles, renderActivity} from '@netresearch/nr-mcp-agent/chat-activity.js';
import {chatGuidedStyles, renderEndTour, renderPageChange, renderPageChoice, renderProgress, renderTourEnd} from '@netresearch/nr-mcp-agent/chat-guided.js';
import {chatReplyOptionsStyles, decisionLabel, renderDenyButtons, renderReplyOptions, replyPlaceholder} from '@netresearch/nr-mcp-agent/chat-reply-options.js';
import {chatProposalStyles, isProposal, renderProposal} from '@netresearch/nr-mcp-agent/chat-proposal.js';
import {chatOutcomesStyles, renderOutcomeLines, renderSummary} from '@netresearch/nr-mcp-agent/chat-outcomes.js';
import {chatSlashStyles, handleSlashKeydown, renderActiveSkill, renderSlashList, slashAria, SLASH_LIST_ID} from '@netresearch/nr-mcp-agent/chat-slash-commands.js';
import {chatEditingStyles, renderMessageBody, renderInstructionsEditor, instructionsLabel} from '@netresearch/nr-mcp-agent/chat-editing.js';

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
        _attachMenuOpen: {type: Boolean, state: true},
    };

    static styles = [themeStyles, markdownStyles, chatEditingStyles, chatActivityStyles, chatGuidedStyles, chatReplyOptionsStyles, chatSlashStyles, chatProposalStyles, chatOutcomesStyles, css`
        :host {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 400px;
            border: 1px solid var(--nr-chat-border);
            border-radius: 4px;
            overflow: hidden;
            font-family: var(--typo3-font-family, sans-serif);
            background: var(--nr-chat-surface);
        }

        .chat-body {
            display: flex;
            flex: 1;
            min-height: 0;
            position: relative;
            container-type: inline-size;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            min-width: 280px;
            border-right: 1px solid var(--nr-chat-border);
            display: flex;
            flex-direction: column;
            background: var(--nr-chat-surface-low);
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
            border-bottom: 1px solid var(--nr-chat-border);
        }
        .sidebar-header h2 {
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
            border-bottom: 1px solid var(--nr-chat-border);
            transition: background 0.15s;
        }
        .conversation-item:hover,
        .conversation-item:focus-visible {
            background: var(--nr-chat-hover);
        }
        .conversation-item:focus-visible {
            outline: 2px solid var(--nr-chat-focus-ring);
            outline-offset: -2px;
        }
        .conversation-item.active {
            background: var(--nr-chat-active);
            color: var(--nr-chat-on-active);
        }
        /*
         * Two-colour indicator. --nr-chat-focus-ring equals the active
         * background in light schemes, and a light ring alone vanishes into
         * the light sidebar beside the row. So the outer 2px take the ring
         * colour (against the surface around the row) and the next 2px the
         * active text colour (against the row itself). Both stay inside the
         * row, where no scroll container can clip them. The row's bottom
         * border takes the ring colour too, or the ring is 1px thinner there.
         */
        .conversation-item.active:focus-visible {
            outline: 2px solid var(--nr-chat-on-active);
            outline-offset: -4px;
            box-shadow: inset 0 0 0 2px var(--nr-chat-focus-ring);
            border-bottom-color: var(--nr-chat-focus-ring);
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
            color: var(--nr-chat-text-variant);
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
            border-bottom: 1px solid var(--nr-chat-border);
            min-height: 44px;
        }
        /* The module's one h1: the open conversation, or the module name. */
        .main-title {
            flex: 1;
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: inherit;
            font-weight: 700;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        /* Message row layout (avatar + bubble + timestamp) */
        .message-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }
        .message-row.user { flex-direction: row-reverse; }
        .message-bubble {
            display: flex;
            flex-direction: column;
            max-width: 78%;
        }
        .message-row.user .message-bubble { align-items: flex-end; }
        .avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar-assistant { background: var(--nr-chat-accent); color: var(--nr-chat-on-accent); }
        .avatar-user { background: var(--nr-chat-surface-high); color: var(--nr-chat-text); }
        .message-time {
            font-size: 11px;
            color: var(--nr-chat-text-variant);
            margin-top: 3px;
            padding: 0 2px;
        }
        .message {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            word-break: break-word;
        }
        .message.user {
            background: var(--nr-chat-accent);
            color: var(--nr-chat-on-accent);
            border-bottom-right-radius: 2px;
        }
        .message.assistant {
            background: var(--nr-chat-surface-high);
            border-bottom-left-radius: 2px;
        }
        .message.tool {
            align-self: flex-start;
            background: var(--nr-chat-surface-base);
            font-size: 12px;
            font-family: monospace;
            color: var(--nr-chat-text-variant);
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
            background: linear-gradient(transparent, var(--nr-chat-surface-base));
            display: flex;
            align-items: flex-end;
            justify-content: center;
            font-size: 11px;
            font-family: sans-serif;
        }
        .approval-card { margin-top: 8px; }
        .approval-call { margin-bottom: 8px; }
        .approval-call code { font-size: .9em; }
        .approval-title { margin: 0 0 4px; }
        .approval-preview ul { margin: 4px 0 0; padding-left: 1.2em; }
        .approval-technical { margin-top: 6px; }
        .approval-technical summary { cursor: pointer; }
        .approval-technical p { margin: 4px 0 0; }
        .approval-warning { color: var(--nr-chat-status-warning); margin-left: 6px; }
        .approval-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
        .status-notice:focus-visible { outline: 2px solid var(--nr-chat-focus-ring); outline-offset: 2px; }
        .approval-run-link { display: inline-block; margin-top: 6px; font-size: 12px; }
        .approval-stale { margin: 4px 0; }
        .approval-card pre { margin: 4px 0 0; max-height: 12em; overflow: auto; }
        .message.system {
            align-self: center;
            font-size: 12px;
            color: var(--nr-chat-text-variant);
            font-style: italic;
        }

        /* Attachment area */
        .file-badge {
            display: flex; align-items: center; gap: 6px;
            padding: 4px 8px; margin: 4px 12px 0;
            background: var(--nr-chat-surface-low);
            border: 1px solid var(--nr-chat-border);
            border-radius: 6px; font-size: 12px;
        }
        .file-badge .file-badge-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-badge .remove { cursor: pointer; color: var(--nr-chat-text-variant); font-size: 16px; line-height: 1; }
        .file-badge .remove:hover { color: var(--nr-chat-text); }
        .message-file-badge {
            display: flex; align-items: center; gap: 4px;
            font-size: 11px; margin-bottom: 3px;
        }

        /* Attach menu */
        .attach-menu-wrap { position: relative; }
        .attach-menu {
            position: absolute;
            bottom: calc(100% + 4px);
            left: 0;
            background: var(--nr-chat-surface);
            border: 1px solid var(--nr-chat-border);
            border-radius: 6px;
            box-shadow: var(--typo3-component-box-shadow-flyout, 0 4px 16px rgba(0,0,0,0.12));
            list-style: none;
            margin: 0;
            padding: 4px 0;
            min-width: 160px;
            z-index: 100;
        }
        .attach-menu li {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
        }
        .attach-menu li:hover { background: var(--nr-chat-surface-base); }

        /* Input area */
        .input-area {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid var(--nr-chat-border);
            background: var(--nr-chat-surface-low);
        }
        .input-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 4px;
            border: 1px solid var(--nr-chat-input-border);
            border-radius: 20px;
            padding: 4px 4px 4px 12px;
            background: var(--nr-chat-surface);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .input-wrap:focus-within {
            border-color: var(--nr-chat-focus-ring);
            box-shadow: 0 0 0 1px var(--nr-chat-focus-ring);
        }
        .input-wrap textarea {
            flex: 1;
            resize: none;
            border: none;
            outline: none;
            padding: 5px 0;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.4;
            min-height: 44px;
            max-height: 120px;
            overflow-y: auto;
            background: transparent;
        }
        .btn-send {
            appearance: none;
            -webkit-appearance: none;
            flex-shrink: 0;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: none;
            background: var(--nr-chat-accent);
            background-image: none;
            color: var(--nr-chat-on-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s, opacity 0.15s;
            margin: 0 2px 0 0;
        }
        .btn-send:hover:not(:disabled) { background: var(--nr-chat-accent-hover); background-image: none; }
        .btn-send:disabled { opacity: 0.35; cursor: not-allowed; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 6px 12px;
            border: 1px solid var(--nr-chat-input-border);
            border-radius: 4px;
            background: var(--nr-chat-surface);
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
            transition: background 0.15s;
        }
        .btn:hover {
            background: var(--nr-chat-hover);
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-primary {
            background: var(--nr-chat-accent);
            color: var(--nr-chat-on-accent);
            border-color: transparent;
        }
        .btn-primary:hover:not(:disabled) {
            background: var(--nr-chat-accent-hover);
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
        .status-idle { background: var(--nr-chat-success-bg); color: var(--nr-chat-success-text); }
        .status-processing, .status-tool_loop {
            background: var(--nr-chat-warning-bg); color: var(--nr-chat-warning-text);
        }
        .status-badge.status-awaiting_approval, .status-badge.status-awaiting_input { background: var(--nr-chat-info-bg); color: var(--nr-chat-info-text); }
        .status-failed { background: var(--nr-chat-danger-bg); color: var(--nr-chat-danger-text); }

        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--nr-chat-text-variant);
            font-size: 14px;
            text-align: center;
            padding: 24px;
        }

        .empty-state-guidance {
            max-width: 34em;
        }

        .empty-state-guidance h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: var(--nr-chat-text);
        }

        .empty-state-guidance p {
            margin: 0 0 16px;
        }

        .empty-state-guidance .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .empty-state-hint {
            margin: 16px 0 0 !important;
            font-size: 13px;
        }

        .issues-banner {
            padding: 8px 12px;
            background: var(--nr-chat-warning-bg);
            border-bottom: 1px solid var(--nr-chat-warning-border);
            font-size: 12px;
            color: var(--nr-chat-warning-text);
        }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid color-mix(in srgb, currentColor 15%, transparent);
            border-top-color: var(--nr-chat-accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Typing indicator — animated dots */
        .typing-indicator {
            display: flex;
            gap: 4px;
            align-items: center;
            padding: 10px 14px;
            background: var(--nr-chat-surface-high);
            border-radius: 8px;
            border-bottom-left-radius: 2px;
            width: fit-content;
        }
        .typing-indicator span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--nr-chat-text-variant);
            animation: typing-bounce 1.2s infinite ease-in-out;
        }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-5px); opacity: 1; }
        }
    `];

    constructor() {
        super();
        this.maxLength = 0;
        this._sidebarCollapsed = false;
        this._attachMenuOpen = false;
        this.chat = new ChatCoreController(this);
    }

    connectedCallback() {
        super.connectedCallback();
        this.chat.maxLength = this.maxLength || 0;
        this._closeAttachMenu = (e) => {
            if (!e.composedPath().includes(this)) {
                this._attachMenuOpen = false;
            }
        };
        document.addEventListener('click', this._closeAttachMenu);
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        document.removeEventListener('click', this._closeAttachMenu);
    }

    // ── Callback hooks for ChatCoreController ──────────────────────────

    /**
     * What the module URL asks a new conversation to be about (ADR-019):
     * `&pageUid=<uid>`, `&languageUid=<uid>` and `&skill=<identifier>`. Null
     * without either a page or a skill, and when the URL names a conversation.
     *
     * @returns {{pageUid?: number, languageUid?: number, skill?: string}|null}
     */
    initialStartContext() {
        const params = new URLSearchParams(globalThis.location.search);
        if (params.get('conversation')) return null;
        const start = {};
        const page = params.get('pageUid') ?? '';
        const language = params.get('languageUid') ?? '';
        const skill = params.get('skill') ?? '';
        if (/^\d+$/.test(page) && Number(page) > 0) {
            start.pageUid = Number(page);
            if (/^\d+$/.test(language)) start.languageUid = Number(language);
        }
        if (skill !== '') start.skill = skill;
        return Object.keys(start).length > 0 ? start : null;
    }

    /** Point the URL at the conversation it started, so a reload opens it again. */
    onStartContextConsumed(uid) {
        const url = new URL(globalThis.location.href);
        ['pageUid', 'languageUid', 'skill'].forEach((key) => url.searchParams.delete(key));
        url.searchParams.set('conversation', String(uid));
        globalThis.history?.replaceState?.(globalThis.history.state, '', url.toString());
    }

    /** The conversation the module URL names (`&conversation=<uid>`), or 0. */
    initialConversationUid() {
        return Number.parseInt(new URLSearchParams(globalThis.location.search).get('conversation') ?? '', 10) || 0;
    }

    onScrollToBottom(force = false) {
        const doScroll = () => {
            const container = this.renderRoot?.querySelector('.messages');
            if (!container) return;
            if (force) {
                container.scrollTop = container.scrollHeight;
                return;
            }
            const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
            if (distanceFromBottom < container.clientHeight * 0.5) {
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
        this.chat.updateSlash();
    }

    _handleKeydown(e) {
        if (handleSlashKeydown(this.chat, e)) {
            return;
        }
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            this.chat.handleSend().catch(() => {});
        }
    }

    // ── Render ─────────────────────────────────────────────────────────

    render() {
        if (this.chat.loading) {
            return html`
                <div class="main-header"><h1 class="main-title">${lll('panel.title')}</h1></div>
                <div class="empty-state"><span class="spinner"></span></div>
            `;
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
                ${renderActivity(this.chat, 'sidebar')}
            </div>
        `;
    }

    _renderSidebar() {
        return html`
            <div class="sidebar-header">
                <h2>${lll('conversations.title')}</h2>
                <button class="btn btn-icon"
                    @click=${() => this.chat.handleNewConversation()}
                    ?disabled=${!this.chat.available}
                    title="${lll('conversations.new')}"
                    aria-label="${lll('conversations.new')}">
                    ${ICON_COMPOSE(16)}
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
                    <span class="status-badge status-${displayStatus(c.status)}">${displayStatus(c.status)}</span>
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

    /**
     * The empty state used to be the sentence "Select or start a chat" and
     * nothing else. The only way to act on it was an icon-only button in the
     * sidebar header, which people have to find first — so the screen named a
     * choice and hid both of its options.
     *
     * This puts the primary action where the sentence is, and says what the
     * assistant can actually do here, because "start a chat" answers neither
     * "about what?" nor "why here rather than anywhere else?".
     */
    _renderEmptyStateGuidance() {
        return html`
            <div class="empty-state-guidance">
                <h2>${lll('chat.empty.title')}</h2>
                <p>${lll('chat.empty.body')}</p>
                <button class="btn btn-primary"
                    @click=${() => this.chat.handleNewConversation()}
                    ?disabled=${!this.chat.available}>
                    ${ICON_COMPOSE(16)} ${lll('conversations.new')}
                </button>
                <p class="empty-state-hint">${lll('chat.empty.hint')}</p>
            </div>
        `;
    }

    _renderMain() {
        if (!this.chat.activeUid) {
            return html`
                <div class="main-header">
                    ${this._renderToggleButton()}
                    <h1 class="main-title">${lll('panel.title')}</h1>
                </div>
                <div class="empty-state">
                    ${this.chat.available
                        ? this._renderEmptyStateGuidance()
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
                <h1 class="main-title">
                    ${conv?.title || lll('conversations.newConversation')}
                </h1>
                ${renderProgress(this.chat)}
                ${renderEndTour(this.chat)}
                <button class="btn btn-sm" @click=${() => this.chat.handleTogglePin()}
                    title="${conv?.pinned ? lll('conversations.unpin') : lll('conversations.pin')}">
                    ${conv?.pinned ? '\u{1F4CC}' : lll('conversations.pin')}
                </button>
                <button class="btn btn-sm" data-action="activity"
                    aria-pressed="${String(this.chat.activityOpen)}"
                    @click=${() => this.chat.toggleActivity()}
                    title="${lll('activity.button')}">
                    ${ICON_ACTIVITY(14)} ${lll('activity.title')}
                </button>
                <button class="btn btn-sm ${this.chat.systemPrompt ? 'has-instructions' : ''}" data-action="instructions"
                    aria-pressed="${String(this.chat.systemPromptOpen)}"
                    @click=${() => this.chat.toggleSystemPrompt()}
                    title="${instructionsLabel(this.chat)}">
                    ${ICON_INSTRUCTIONS(14)} ${lll('instructions.buttonShort')}
                </button>
                <button class="btn btn-sm" data-action="export"
                    ?disabled=${!this.chat.canExport()}
                    @click=${() => this.chat.canExport() && downloadTextFile(this.ownerDocument || document, this.chat.exportFileName(), this.chat.buildMarkdownExport())}
                    title="${lll('conversations.export')}">
                    ${ICON_DOWNLOAD(14)} ${lll('conversations.exportShort')}
                </button>
                <button class="btn btn-sm" @click=${() => this.chat.handleArchive()}>${lll('conversations.archive')}</button>
            </div>

            ${renderInstructionsEditor(this.chat)}
            <div class="messages" aria-live="polite" aria-relevant="additions">
                ${this.chat.messages.map((msg, idx) => html`${renderOutcomeLines(this.chat, idx)}${this._renderMessage(msg, idx)}`)}
                ${renderOutcomeLines(this.chat, this.chat.messages.length)}
                ${renderSummary(this.chat)}
                ${this.chat.isProcessing() ? html`
                    <div class="message-row assistant" aria-label="${lll('chat.processing')}">
                        <div class="avatar avatar-assistant">${AVATAR_ASSISTANT(16)}</div>
                        <div class="typing-indicator" aria-hidden="true"><span></span><span></span><span></span></div>
                    </div>
                ` : nothing}
                ${this._renderStatusNotice(isResumable)}
            </div>

            ${renderReplyOptions(this.chat)}
            ${renderPageChange(this.chat)}
            ${renderPageChoice(this.chat)}
            ${renderTourEnd(this.chat)}
            ${renderActiveSkill(this.chat)}
            ${renderSlashList(this.chat)}
            ${this._renderFileBadge()}
            <div class="input-area">
                ${this._renderAttachmentMenu()}
                <div class="input-wrap">
                    <textarea
                        .value=${this.chat.inputValue}
                        @input=${this._handleInput}
                        @keydown=${this._handleKeydown}
                        role="combobox"
                        aria-autocomplete="list"
                        aria-controls="${SLASH_LIST_ID}"
                        aria-expanded="${slashAria(this.chat).expanded}"
                        aria-activedescendant="${slashAria(this.chat).activedescendant || nothing}"
                        placeholder="${replyPlaceholder(this.chat)}"
                        aria-label="${replyPlaceholder(this.chat)}"
                        ?disabled=${!this.chat.available}
                        maxlength=${this.maxLength > 0 ? this.maxLength : nothing}
                        rows="2"
                    ></textarea>
                    <button class="btn-send"
                        @click=${() => this.chat.handleSend()}
                        aria-label="${lll('chat.send')}"
                        title="${lll('chat.send')}"
                        ?disabled=${!this.chat.hasInput || this.chat.sending || this.chat.isProcessing() || !this.chat.available}>
                        ${this.chat.sending ? html`<span class="spinner" style="width:14px;height:14px;border-width:2px;"></span>` : ICON_SEND(16)}
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * The status notice under the transcript: a decision just taken, a pending
     * approval, or an error.
     *
     * Extracted from render() because these are one chained conditional with a
     * further one inside it, which is hard to read and pushed render() past its
     * complexity budget.
     *
     * The decision state comes FIRST and returns on its own: it is the answer to
     * a click the reader has just made, and it is shown while the conversation is
     * Processing — the same state an ordinary turn is in, where a notice would
     * otherwise be silent (NEXT-156).
     *
     * @param {boolean} isResumable
     */
    _renderStatusNotice(isResumable) {
        const dismiss = () => { this.chat.errorMessage = ''; this.requestUpdate(); };

        // A question shows its answers, and the reason it is open again, above
        // the input (ADR-018); an error line here would offer a Retry that
        // steps past it.
        if (this.chat.status === 'awaiting_input') {
            return nothing;
        }

        if (this.chat.approvalDecisionTaken) {
            const granted = this.chat.approvalDecisionTaken === 'approved';
            // The two denials (ADR-018) confirm in their own words.
            return html`
                <div class="message system status-notice" tabindex="-1"
                    style="color:${granted ? 'var(--nr-chat-status-success, #2e7d32)' : 'var(--nr-chat-status-info, #0277bd)'};">
                    ${decisionLabel(this.chat.approvalDecisionTaken)}
                </div>
            `;
        }

        // The pending state is gated by the status, not by the field: the pause
        // stores no sentence (NEXT-159 — a stored one is frozen in the language
        // of the moment it was written), and a run handed back by reconcile()
        // carries an empty field too.
        if (!this.chat.errorMessage && this.chat.status !== 'awaiting_approval') {
            return nothing;
        }

        if (this.chat.status === 'awaiting_approval') {
            // The field holds a reason only when the runtime refused a decision
            // and handed the run back; otherwise the label says what is pending.
            // No Retry here: restarting would step past an approval that is
            // still pending. No Dismiss either: the notice follows the status,
            // so clearing the field would not hide it, and the card it carries
            // is where the decision is taken.
            //
            // A short status, not an explanation (editorial rules 14, 24): the
            // card below names the change and its buttons the decision. A
            // reader who may not decide gets neither card nor link — the module
            // would refuse them — but the one step they can take (rule 27).
            if (!this.chat.mayDecideApproval) {
                return html`
                    <div class="message system status-notice" tabindex="-1" style="color:var(--nr-chat-status-info, #0277bd);">
                        ${lll('chat.approvalPendingElsewhere')}
                    </div>
                `;
            }

            const reason = this.chat.errorMessage ? `: ${this.chat.errorMessage}` : '';

            return html`
                <div class="message system status-notice" tabindex="-1" style="color:var(--nr-chat-status-info, #0277bd);">
                    ${lll('chat.approvalPending')}${reason}
                    ${this._renderApprovalCard()}
                </div>
            `;
        }

        return html`
            <div class="message system status-notice" tabindex="-1" style="color:var(--nr-chat-status-danger, #c62828);">
                ${lll('chat.errorPrefix')} ${this.chat.errorMessage}
                ${this._renderErrorLink()}
                ${isResumable ? html`
                    <button class="btn btn-sm" @click=${() => this.chat.handleResume()}
                        style="margin-left:8px;">${lll('chat.retry')}</button>
                ` : nothing}
                <button class="btn btn-sm btn-icon" @click=${dismiss}
                    style="margin-left:4px;" title="${lll('chat.dismiss')}" aria-label="${lll('chat.dismiss')}">&times;</button>
            </div>
        `;
    }

    /**
     * Where an administrator fixes a configuration failure (ADR-017). Only
     * present when the server sent one, which it does for administrators only.
     */
    _renderErrorLink() {
        const link = this.chat.currentErrorLink();
        if (!link) {
            return nothing;
        }

        return html`
            <a class="btn btn-sm" href="${link.href}" style="margin-left:8px;">${link.label}</a>
        `;
    }

    /**
     * An assistant message's body. A line the chat itself added for a run that
     * finished outside it is stored language-neutral for the model; the reader
     * gets the label in their own language, filled with the records the run
     * wrote (ADR-017).
     *
     * @param {{content?: string, notice?: string, noticeArgs?: string[]}} msg
     */
    _renderAssistantContent(msg) {
        const notice = this.chat.formatRunNotice(msg);
        if (notice !== null) {
            return notice;
        }

        return unsafeHTML(this.chat.renderMessageContent(msg));
    }

    /**
     * The label an assistant message carries when it claims a finished change
     * and the run wrote nothing (ADR-017). The server stores the code, the
     * label is rendered in the reader's language.
     *
     * @param {{notice?: string}} msg
     */
    _renderMessageNotice(msg) {
        if (msg.notice !== 'nothingSaved') {
            return nothing;
        }

        // A plain note, not a warning: the trigger is generous on purpose
        // (ChangeClaim), so it also fires under answers of read-only steps that
        // merely mention a change, and the editorial rules keep warnings for
        // critical consequences (rule 21). The body text colour keeps the
        // contrast at least as high as the warning colour had.
        return html`
            <div class="message-notice" role="note"
                style="color:var(--nr-chat-text);font-size:12px;margin-top:4px;">
                ${lll('chat.nothingSaved')}
            </div>
        `;
    }

    /**
     * The pending tool call, with the decision on it.
     *
     * Rendered inside the notice rather than as a link away from it: the run is
     * this conversation's, the decision goes through the same per-run
     * authorisation as the approvals module, and the answer arrives here.
     *
     * The card names the change, not the tool: its heading and its approve
     * button carry the change's name (the preview's first line, else the tool's
     * editor action label), the preview lines follow in
     * nr-llm's order, and the tool name, its arguments and the technical
     * preview line sit in one closed "Show technical details" section
     * (editorial rules 10, 14-16, 22, 26). The denial is "Cancel", or, for a
     * write in a process run, "Another variant" and "Skip", which tell the run
     * why the change was not taken (ADR-018, nr-llm ADR-214).
     *
     * Approve and the denial buttons are the only actions of a decidable card. The link to
     * the run used to sit beside them, styled like a third button and labelled
     * "Grant approval", although it opens the run's timeline, where nothing can
     * be granted (NEXT-162). It is now a plain text link, and it is offered on
     * a decidable card only when a call has no usable preview — the one case in
     * which the card itself cannot show what the reader is deciding on.
     */
    _renderApprovalCard() {
        const pending = this.chat.pendingApproval;
        if (!pending) {
            return this._renderApprovalLink();
        }

        if (pending.unreadableReason) {
            // An empty card would look decidable. Say why it is not.
            return html`
                <div class="approval-card">
                    <em>${lll('chat.approvalUnreadable')}</em>
                    ${this._renderApprovalLink()}
                </div>
            `;
        }

        if (isProposal(pending)) {
            return this._renderProposalCard(pending);
        }

        return html`
            <div class="approval-card">
                ${pending.calls.map((call) => html`
                    <div class="approval-call">
                        <p class="approval-title" role="heading" aria-level="3"><strong>${call.actionLabel || lll('chat.approvalTitleGeneric')}</strong></p>
                        ${call.toolStillRegistered ? nothing : html`
                            <p class="approval-warning approval-stale">${lll('chat.approvalToolGone')}</p>
                        `}
                        ${call.previewStale ? html`
                            <p class="approval-warning approval-stale">${lll('chat.approvalPreviewStale')}</p>
                        ` : nothing}
                        ${this._renderApprovalPreview(call)}
                        <details class="approval-technical">
                            <summary>${lll('chat.approvalTechnicalDetails')}</summary>
                            ${call.technicalDetails ? html`<p>${call.technicalDetails}</p>` : nothing}
                            <p>${lll('chat.approvalTool')} <code>${call.name}</code></p>
                            <p>${lll('chat.approvalArguments')}</p>
                            <pre><code>${call.argumentsJson}</code></pre>
                        </details>
                    </div>
                `)}
                <div class="approval-actions">
                    <button class="btn btn-sm btn-primary" ?disabled=${this.chat.approvalBusy}
                        @click=${() => this._decide(true)}>${this._approveLabel(pending)}</button>
                    ${renderDenyButtons(pending, this.chat.approvalBusy, (reason) => this._decide(false, reason))}
                </div>
                ${this._lacksPreview(pending) || this.chat.errorPointsToRun ? this._renderRunDetailsLink() : nothing}
            </div>
        `;
    }

    /**
     * A write in a process run (ADR-023): the proposal block, the three
     * answers directly below it, and the technical details last.
     */
    _renderProposalCard(pending) {
        const call = pending.calls[0];

        return html`
            <div class="approval-card proposal-card">
                ${call.toolStillRegistered ? nothing : html`
                    <p class="approval-warning approval-stale">${lll('chat.approvalToolGone')}</p>
                `}
                ${call.previewStale ? html`
                    <p class="approval-warning approval-stale">${lll('chat.approvalPreviewStale')}</p>
                ` : nothing}
                ${renderProposal(call, (c) => this._renderApprovalPreview(c))}
                <div class="approval-actions">
                    <button class="btn btn-sm btn-primary" ?disabled=${this.chat.approvalBusy}
                        @click=${() => this._decide(true)}>${lll('chat.approvalApply')}</button>
                    ${renderDenyButtons(pending, this.chat.approvalBusy, (reason) => this._decide(false, reason))}
                </div>
                <details class="approval-technical">
                    <summary>${lll('chat.approvalTechnicalDetails')}</summary>
                    ${call.technicalDetails ? html`<p>${call.technicalDetails}</p>` : nothing}
                    <p>${lll('chat.approvalTool')} <code>${call.name}</code></p>
                    <p>${lll('chat.approvalArguments')}</p>
                    <pre><code>${call.argumentsJson}</code></pre>
                </details>
                ${this._lacksPreview(pending) || this.chat.errorPointsToRun ? this._renderRunDetailsLink() : nothing}
            </div>
        `;
    }

    /**
     * The preview lines of one call, in nr-llm's order. A failed or withheld
     * preview is introduced as such, because its lines carry the reason.
     */
    _renderApprovalPreview(call) {
        if (!call.previewLines?.length) {
            return nothing;
        }

        const unavailable = call.previewFailed
            ? html`<strong>${lll('chat.approvalPreviewUnavailable')}</strong>`
            : nothing;

        return html`
            <div class="approval-preview">
                ${unavailable}
                <ul>${call.previewLines.map((line) => html`<li>${line}</li>`)}</ul>
            </div>
        `;
    }

    /**
     * Take the decision, then move focus to the status line that answers it.
     * The card and the button the reader pressed are gone by then, and focus
     * would otherwise fall back to the document body. Not when the reader has
     * switched conversations meanwhile: the answer is not on screen then.
     */
    async _decide(approve, reason = '') {
        const uid = this.chat.activeUid;
        await this.chat.decideApproval(approve, reason);
        if (uid !== this.chat.activeUid) {
            return;
        }

        await this.updateComplete;
        this.shadowRoot.querySelector('.status-notice')?.focus();
    }

    /**
     * What the approve button says (editorial rule 22): the action it carries
     * out, named by the change's name the server resolved. The decision covers every
     * call of the turn, so a button naming one action is right only when the
     * turn has exactly one call; otherwise, and for a tool without a label,
     * the button says that the step(s) will be carried out.
     */
    _approveLabel(pending) {
        if (pending.calls.length === 1) {
            return pending.calls[0].actionLabel || lll('chat.approvalConfirmGeneric');
        }

        return lll('chat.approvalConfirmGenericAll');
    }

    /**
     * True when at least one pending call shows the reader nothing to decide
     * on: its preview failed or was withheld (`previewFailed`, the lines then
     * carry the reason), or the tool offers no preview at all (no lines).
     */
    _lacksPreview(pending) {
        return pending.calls.some(
            // A preview whose only line became the heading still showed it.
            (call) => call.previewFailed || !(call.previewLines?.length || call.actionLabelFromPreview),
        );
    }

    /**
     * Link to the run waiting for an approval, where it is the only thing the
     * notice can offer — no decidable card; absent when there is no run.
     */
    _renderApprovalLink() {
        if (!this.chat.approvalUrl) {
            return nothing;
        }

        return html`
            <a class="btn btn-sm" href="${this.chat.approvalUrl}"
                style="margin-left:8px;">${lll('chat.approvalOpen')}</a>
        `;
    }

    /** The same link on a decidable card: secondary, so never button-shaped. */
    _renderRunDetailsLink() {
        if (!this.chat.approvalUrl) {
            return nothing;
        }

        return html`
            <a class="approval-run-link" href="${this.chat.approvalUrl}">${lll('chat.approvalOpen')}</a>
        `;
    }

    _renderFileBadge() {
        if (!this.chat.pendingFile) return nothing;
        const icon = this.chat.pendingFile.mimeType?.startsWith('image/') ? '\u{1F5BC}\uFE0F' : '\u{1F4C4}';
        return html`
            <div class="file-badge">
                <span>${icon}</span>
                <span class="file-badge-name">${this.chat.pendingFile.name}</span>
                <span class="remove"
                      role="button"
                      tabindex="0"
                      title="${lll('attachment.remove')}"
                      @click=${() => this.chat.clearPendingFile()}
                      @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') this.chat.clearPendingFile(); }}
                >&times;</span>
            </div>
        `;
    }

    _renderAttachmentMenu() {
        if (!this.chat.visionSupported) return nothing;
        const canAttach = this.chat.canAttachFile();
        return html`
            <div class="attach-menu-wrap">
                <button class="btn btn-icon"
                        ?disabled=${!canAttach}
                        title="${canAttach ? lll('attachment.attach') : lll('attachment.limitReached')}"
                        aria-label="${lll('attachment.attach')}"
                        aria-expanded="${String(this._attachMenuOpen)}"
                        aria-haspopup="menu"
                        @click=${(e) => { e.stopPropagation(); this._attachMenuOpen = !this._attachMenuOpen; }}>
                    ${ICON_PAPERCLIP(16)}${ICON_CHEVRON_DOWN(10)}
                </button>

                ${this._attachMenuOpen ? html`
                    <ul class="attach-menu"
                        role="menu"
                        @click=${(e) => e.stopPropagation()}>
                        <li role="menuitem"
                            tabindex="0"
                            @click=${() => { this._attachMenuOpen = false; this.renderRoot.querySelector('input[type="file"]')?.click(); }}
                            @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this._attachMenuOpen = false; this.renderRoot.querySelector('input[type="file"]')?.click(); } }}>
                            ${ICON_UPLOAD(14)}
                            ${lll('attachment.upload')}
                        </li>
                        <li role="menuitem"
                            tabindex="0"
                            @click=${() => { this._attachMenuOpen = false; this.dispatchEvent(new CustomEvent('nr-mcp-open-fal-picker', {bubbles: true, composed: true})); }}
                            @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this._attachMenuOpen = false; this.dispatchEvent(new CustomEvent('nr-mcp-open-fal-picker', {bubbles: true, composed: true})); } }}>
                            <typo3-icon identifier="apps-filetree-folder-opened" size="small"></typo3-icon>
                            ${lll('attachment.fromFal')}
                        </li>
                    </ul>
                ` : nothing}
            </div>

            <input type="file"
                   accept="${(this.chat.supportedFormats || []).map(f => '.' + f).join(',') || '*'}"
                   style="display:none"
                   @change=${this._handleFileSelected}>
        `;
    }

    async _handleFileSelected(e) {
        const file = e.target.files?.[0];
        if (!file) return;
        e.target.value = '';
        await this.chat.handleFileUpload(file);
    }

    _renderMessage(msg, idx) {
        const role = msg.role || 'system';
        if (role === 'assistant' && msg.tool_calls && !msg.content) return nothing;

        // Tool messages — no avatar, collapsible
        if (role === 'tool') {
            const isExpanded = this.chat.expandedTools.has(idx);
            return html`
                <div class="message tool ${isExpanded ? 'expanded' : ''}"
                     role="button" tabindex="0"
                     aria-label="${lll('tool.output')}" aria-expanded="${isExpanded}"
                     @click=${() => this.chat.handleToolMessageClick(idx)}
                     @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.chat.handleToolMessageClick(idx); } }}>
                    ${this.chat.renderMessageContent(msg)}
                </div>
            `;
        }

        // System messages — centered, no avatar
        if (role === 'system') {
            return html`<div class="message system">${this.chat.renderMessageContent(msg)}</div>`;
        }

        // User + assistant — avatar row with timestamp
        const isUser = role === 'user';
        const time = this.chat.formatTime(msg.createdAt);
        const fileIcon = msg.fileMimeType?.startsWith('image/') ? '\u{1F5BC}\uFE0F' : '\u{1F4C4}';
        const fileBadge = msg.fileUid
            ? html`<div class="message-file-badge">${fileIcon} ${msg.fileName || lll('attachment.file')}</div>`
            : nothing;
        const bubbleContent = isUser
            ? html`${fileBadge}${this.chat.renderMessageContent(msg)}`
            : this._renderAssistantContent(msg);

        return html`
            <div class="message-row ${role}">
                ${isUser ? nothing : html`<div class="avatar avatar-assistant">${AVATAR_ASSISTANT(16)}</div>`}
                <div class="message-bubble">
                    ${renderMessageBody(this.chat, idx, html`<div class="message ${role}">${bubbleContent}</div>${this._renderMessageNotice(msg)}`, time)}
                </div>
                ${isUser ? html`<div class="avatar avatar-user">${AVATAR_USER(16)}</div>` : nothing}
            </div>
        `;
    }
}

customElements.define('nr-chat-app', ChatApp);
