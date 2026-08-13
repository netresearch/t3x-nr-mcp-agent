import {LitElement, html, css, nothing} from 'lit';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import {ref} from 'lit/directives/ref.js';
import {lll} from '@typo3/core/lit-helper.js';
import {ChatCoreController} from './chat-core.js';
import {markdownStyles} from './markdown-styles.js';
import {themeStyles} from './theme.js';
import {AVATAR_ASSISTANT, AVATAR_USER, ICON_PAPERCLIP, ICON_SEND, ICON_COMPOSE, ICON_MINIMIZE, ICON_MAXIMIZE, ICON_RESTORE, ICON_CLOSE, ICON_POPOUT, ICON_CHEVRON_DOWN, ICON_UPLOAD} from './icons.js';

const STATES = {HIDDEN: 'hidden', COLLAPSED: 'collapsed', EXPANDED: 'expanded', MAXIMIZED: 'maximized'};
const STATUS_ICONS = {idle: '✓', processing: '⟳', tool_loop: '⚙', locked: '⊘', awaiting_approval: '⏸', failed: '✕'};
const DEFAULT_HEIGHT = 350;
const DEFAULT_WIDTH = 480;
const MIN_WIDTH = 320;
const MIN_HEIGHT = 120;
const COLLAPSED_HEIGHT = 36;
// How much of the panel must stay on screen when it is pushed off an edge —
// enough of the header to grab and drag it back.
const MIN_VISIBLE = 64;
const STORAGE_KEY = 'ai-chat-panel';

/**
 * <ai-chat-panel> - Floating draggable panel for AI chat.
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
        _width: {state: true},
        _posX: {state: true},
        _posY: {state: true},
        _attachMenuOpen: {type: Boolean, state: true},
        _renamingUid: {state: true},
    };

    static styles = [themeStyles, markdownStyles, css`
        :host {
            position: fixed;
            z-index: calc(var(--typo3-zindex-modal-backdrop, 1050) - 10);
            box-shadow: var(--typo3-component-box-shadow-flyout, 0 4px 24px rgba(0, 0, 0, 0.18)), 0 0 0 1px var(--nr-chat-border);
            border-radius: 12px;
            font-family: var(--typo3-font-family, sans-serif);
            background: var(--nr-chat-surface);
            display: flex;
            flex-direction: column;
        }
        :host([state="hidden"]) {
            display: none;
        }
        :host([state="maximized"]) {
            border-radius: 0;
        }

        /* Corner resize grip — bottom-right, generous hit area */
        .resize-grip {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 28px;
            height: 28px;
            cursor: nwse-resize;
            touch-action: none;
            z-index: 10;
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            padding: 4px;
            border-radius: 0 0 12px 0;
        }
        .resize-grip::before {
            content: '';
            position: absolute;
            bottom: -4px;
            right: -4px;
            width: 36px;
            height: 36px;
        }
        .resize-grip svg {
            width: 14px;
            height: 14px;
            opacity: 0.3;
            transition: opacity 0.15s;
        }
        .resize-grip:hover svg,
        .resize-grip:active svg {
            opacity: 0.6;
        }
        .resize-grip:focus-visible {
            outline: 2px solid var(--nr-chat-focus-ring);
            outline-offset: -2px;
        }
        .resize-grip:focus-visible svg {
            opacity: 0.8;
        }

        /* Panel header — drag handle */
        .panel-header {
            height: 36px;
            min-height: 36px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            background: linear-gradient(to bottom, var(--nr-chat-surface-low), color-mix(in srgb, var(--nr-chat-surface-low) 85%, transparent));
            border-bottom: 1px solid var(--nr-chat-border);
            cursor: grab;
            flex-shrink: 0;
            user-select: none;
            -webkit-user-select: none;
            touch-action: none;
            border-radius: 12px 12px 0 0;
        }
        :host([state="maximized"]) .panel-header {
            border-radius: 0;
        }
        .panel-header:active {
            cursor: grabbing;
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
            border-right: 1px solid var(--nr-chat-border);
            display: flex;
            flex-direction: column;
            background: var(--nr-chat-surface-low);
            overflow: hidden;
        }
        .panel-sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid var(--nr-chat-border);
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
            border-bottom: 1px solid var(--nr-chat-border);
            transition: background 0.15s;
            font-size: 12px;
        }
        .sidebar-item:hover,
        .sidebar-item:focus-visible {
            background: var(--nr-chat-hover);
        }
        .sidebar-item:focus-visible {
            outline: 2px solid var(--nr-chat-focus-ring);
            outline-offset: -2px;
        }
        .sidebar-item.active {
            background: var(--nr-chat-active);
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
            gap: 6px;
            padding: 6px 10px;
            border-bottom: 1px solid var(--nr-chat-border);
            background: var(--nr-chat-surface-low);
            flex-shrink: 0;
        }
        .select-wrap {
            flex: 1;
            position: relative;
            min-width: 0;
            display: flex;
            align-items: center;
        }
        .select-wrap select {
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
            padding: 5px 28px 5px 10px;
            border: 1px solid var(--nr-chat-input-border);
            border-radius: 8px;
            font-size: 12px;
            background: var(--nr-chat-surface);
            cursor: pointer;
            min-width: 0;
            transition: border-color 0.15s;
        }
        .select-wrap select:focus {
            outline: none;
            border-color: var(--nr-chat-focus-ring);
            box-shadow: 0 0 0 1px var(--nr-chat-focus-ring);
        }
        .select-wrap .chevron {
            position: absolute;
            right: 8px;
            pointer-events: none;
            color: var(--nr-chat-text-variant);
            display: flex;
            align-items: center;
        }

        /* Conversation tab bar (second row in expanded state) */
        .conv-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            padding: 4px 8px 0;
            border-bottom: 1px solid var(--nr-chat-border);
            background: var(--nr-chat-surface-low);
            flex-shrink: 0;
        }
        .conv-tab {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px 6px 0 0;
            border: 1px solid transparent;
            border-bottom: none;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            max-width: 140px;
            background: transparent;
            color: var(--nr-chat-text-variant);
            transition: background 0.1s, color 0.1s;
            line-height: 1.3;
        }
        .conv-tab:hover {
            background: var(--nr-chat-surface-base);
            color: var(--nr-chat-text);
        }
        .conv-tab.active {
            background: var(--nr-chat-surface);
            color: var(--nr-chat-text);
            border-color: var(--nr-chat-border);
            font-weight: 500;
        }
        .conv-tab .tab-title {
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 20px;
        }
        .conv-tab .tab-icon {
            flex-shrink: 0;
            font-size: 11px;
        }
        .conv-tab .tab-icon.status-processing,
        .conv-tab .tab-icon.status-tool_loop,
        .approval-card { margin-top: 8px; }
        .approval-call { margin-bottom: 8px; }
        .approval-call code { font-size: .9em; }
        .approval-preview ul { margin: 4px 0 0; padding-left: 1.2em; }
        .approval-warning { color: var(--nr-chat-status-warning, #ef6c00); margin-left: 6px; }
        .approval-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
        .approval-card pre { margin: 4px 0 0; max-height: 12em; overflow: auto; }
        .conv-tab .tab-icon.status-awaiting_approval,
        .conv-tab .tab-icon.status-locked { color: var(--nr-chat-status-info); }
        .conv-tab .tab-icon.status-failed  { color: var(--nr-chat-status-danger); }
        .conv-tab .tab-icon.status-idle    { color: var(--nr-chat-status-success); }
        .conv-tab .tab-close {
            flex-shrink: 0;
            display: none;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            border-radius: 3px;
            font-size: 11px;
            line-height: 1;
            color: var(--nr-chat-text-variant);
        }
        .conv-tab:hover .tab-close,
        .conv-tab.active .tab-close { display: flex; }
        .conv-tab .tab-close:hover {
            background: var(--nr-chat-danger-bg);
            color: var(--nr-chat-danger-text);
        }
        .conv-tab-new {
            flex-shrink: 0;
            margin-left: auto;
            padding: 4px 6px;
            border-radius: 6px;
            color: var(--nr-chat-text-variant);
        }
        .conv-tab-new:hover { color: var(--nr-chat-text); }
        .conv-tab .tab-rename-input {
            width: 90px;
            padding: 1px 4px;
            font-size: 12px;
            border: 1px solid var(--nr-chat-focus-ring);
            border-radius: 3px;
            outline: none;
            background: var(--nr-chat-surface);
            color: var(--nr-chat-text);
        }

        /* Messages */
        .panel-messages {
            flex: 1;
            overflow-y: auto;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        /* Message row layout (avatar + bubble + timestamp) */
        .message-row {
            display: flex;
            align-items: flex-end;
            gap: 6px;
        }
        .message-row.user { flex-direction: row-reverse; }
        .message-bubble {
            display: flex;
            flex-direction: column;
            max-width: 78%;
        }
        .message-row.user .message-bubble { align-items: flex-end; }
        .avatar {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .avatar-assistant { background: var(--nr-chat-accent); color: var(--nr-chat-on-accent); }
        .avatar-user { background: var(--nr-chat-surface-high); color: #555; }
        .message-time {
            font-size: 10px;
            color: var(--nr-chat-text-variant);
            margin-top: 2px;
            padding: 0 2px;
        }
        .message {
            padding: 5px 9px;
            border-radius: 10px;
            font-size: 12.5px;
            line-height: 1.45;
            word-break: break-word;
        }
        .message.user {
            background: var(--nr-chat-accent);
            color: var(--nr-chat-on-accent);
            border-bottom-right-radius: 3px;
        }
        .message.assistant {
            background: var(--nr-chat-surface-high);
            border-bottom-left-radius: 3px;
        }
        .message.tool {
            align-self: flex-start;
            background: var(--nr-chat-surface-base);
            font-size: 11px;
            font-family: monospace;
            opacity: 0.5;
            max-height: 40px;
            overflow: hidden;
            cursor: pointer;
            position: relative;
            padding: 4px 8px;
        }
        .message.tool.expanded {
            max-height: none;
        }
        .message.tool:not(.expanded)::after {
            content: '...';
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
        .message.system {
            align-self: center;
            font-size: 12px;
            color: var(--nr-chat-text-variant);
            font-style: italic;
        }

        /* Typing indicator — animated dots */
        .typing-indicator {
            display: flex;
            gap: 3px;
            align-items: center;
            padding: 7px 10px;
            background: var(--nr-chat-surface-high);
            border-radius: 10px;
            border-bottom-left-radius: 3px;
            width: fit-content;
        }
        .typing-indicator span {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--nr-chat-text-variant);
            animation: typing-bounce 1.2s infinite ease-in-out;
        }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-4px); opacity: 1; }
        }

        /* Attachment and file badge */
        .file-badge {
            display: flex; align-items: center; gap: 6px;
            padding: 4px 8px; margin: 4px 12px 0;
            background: var(--nr-chat-surface-low);
            border: 1px solid var(--nr-chat-border);
            border-radius: 6px; font-size: 12px;
        }
        .file-badge .file-badge-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-badge .remove { cursor: pointer; opacity: 0.5; font-size: 16px; line-height: 1; }
        .file-badge .remove:hover { opacity: 1; }
        .message-file-badge {
            display: flex; align-items: center; gap: 4px;
            font-size: 11px; margin-bottom: 3px; opacity: 0.85;
        }

        /* Input area */
        .panel-input {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            border-top: 1px solid var(--nr-chat-border);
            background: var(--nr-chat-surface-low);
            flex-shrink: 0;
        }
        .input-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 2px;
            border: 1px solid var(--nr-chat-input-border);
            border-radius: 16px;
            padding: 3px 3px 3px 10px;
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
            padding: 4px 0;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.4;
            min-height: 40px;
            max-height: 120px;
            overflow-y: auto;
            background: transparent;
        }
        .btn-send {
            appearance: none;
            -webkit-appearance: none;
            flex-shrink: 0;
            width: 28px;
            height: 28px;
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
            margin: 0 1px 0 0;
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
            border-radius: 6px;
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
        .empty-state-guidance {
            max-width: 30em;
        }

        .empty-state-guidance h2 {
            margin: 0 0 8px;
            font-size: 16px;
            color: var(--nr-chat-text);
        }

        .empty-state-guidance p {
            margin: 0 0 12px;
        }

        .empty-state-guidance .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .empty-state-hint {
            margin: 12px 0 0 !important;
            font-size: 12px;
            opacity: 0.85;
        }

        .btn-primary {
            background: var(--nr-chat-accent);
            color: var(--nr-chat-on-accent);
            border-color: transparent;
            border-radius: 8px;
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
            border-radius: 6px;
        }
        .btn-icon:hover {
            background: var(--nr-chat-hover);
        }

        .attach-menu-wrap { position: relative; }
        .attach-menu {
            position: absolute;
            bottom: calc(100% + 4px);
            left: 0;
            background: var(--nr-chat-surface);
            border: 1px solid var(--nr-chat-border);
            border-radius: 4px;
            padding: 4px 0;
            margin: 0;
            list-style: none;
            white-space: nowrap;
            z-index: 100;
            box-shadow: var(--typo3-component-box-shadow-flyout, 0 2px 8px rgba(0,0,0,.15));
        }
        .attach-menu li {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        }
        .attach-menu li:hover {
            background: var(--nr-chat-hover);
        }

        /* Status */
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 12px;
            line-height: 1.4;
        }
        .status-idle { background: var(--nr-chat-success-bg); color: var(--nr-chat-success-text); }
        .status-processing, .status-locked, .status-tool_loop {
            background: var(--nr-chat-warning-bg); color: var(--nr-chat-warning-text);
        }
        .status-failed { background: var(--nr-chat-danger-bg); color: var(--nr-chat-danger-text); }

        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--nr-chat-text-variant);
            font-size: 13px;
            text-align: center;
            padding: 16px;
        }

        .issues-banner {
            padding: 6px 12px;
            background: var(--nr-chat-warning-bg);
            border-bottom: 1px solid var(--nr-chat-warning-border);
            font-size: 12px;
            color: var(--nr-chat-warning-text);
            flex-shrink: 0;
        }

        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid color-mix(in srgb, currentColor 15%, transparent);
            border-top-color: var(--nr-chat-accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    `];

    constructor() {
        super();
        this.chat = new ChatCoreController(this);
        this.state = STATES.HIDDEN;
        this._height = DEFAULT_HEIGHT;
        this._width = DEFAULT_WIDTH;
        this._posX = null;
        this._posY = null;
        this._pipWindow = null;
        this._pipHome = null;
        this._attachMenuOpen = false;
        this._lastVisibleState = STATES.EXPANDED;
        this._resizing = false;
        this._dragging = false;
        this._restoreState();
    }

    connectedCallback() {
        super.connectedCallback();
        this.setAttribute('role', 'complementary');
        this.setAttribute('aria-label', lll('panel.title') || 'AI Chat');
        this.setAttribute('tabindex', '-1'); // focusable programmatically but not in tab order
        this._keydownHandler = (e) => this._onKeydown(e);
        document.addEventListener('keydown', this._keydownHandler);
        this._closeAttachMenu = (e) => {
            if (!e.composedPath().includes(this)) {
                this._attachMenuOpen = false;
            }
        };
        document.addEventListener('click', this._closeAttachMenu);
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        document.removeEventListener('keydown', this._keydownHandler);
        document.removeEventListener('click', this._closeAttachMenu);
    }

    updated(changed) {
        if (changed.has('state') || changed.has('_height') || changed.has('_width') || changed.has('_posX') || changed.has('_posY')) {
            this._applySize();
        }
        if (changed.has('state')) {
            this.setAttribute('aria-expanded', String(this.state !== STATES.HIDDEN));
        }
    }

    /** Calculate default bottom-right position */
    _defaultPosition() {
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        return {
            x: vw - this._width - 16,
            y: vh - this._height - 16,
        };
    }

    _applySize() {
        if (this.state === STATES.HIDDEN) return;
        // Don't override styles during active drag or resize — we write directly to this.style
        if (this._dragging || this._resizing) return;

        // Detached: the panel IS the window, so it fills it. Keeping the
        // position:fixed coordinates would place it at the main window's
        // left/top inside a window a fraction of that size — off screen, so the
        // detached window opens empty while every DOM assertion still passes.
        if (this._pipWindow) {
            this.style.top = '0';
            this.style.left = '0';
            this.style.width = '100%';
            this.style.height = '100%';
            this.style.right = '';
            this.style.bottom = '';
            return;
        }

        if (this.state === STATES.MAXIMIZED) {
            this.style.top = '0';
            this.style.left = '0';
            this.style.width = '100vw';
            this.style.height = '100vh';
            this.style.right = '';
            this.style.bottom = '';
            return;
        }

        if (this.state === STATES.COLLAPSED) {
            const pos = this._constrainPosition(this._getPosition().x, this._getPosition().y);
            this.style.top = pos.y + 'px';
            this.style.left = pos.x + 'px';
            this.style.width = this._width + 'px';
            this.style.height = COLLAPSED_HEIGHT + 'px';
            this.style.right = '';
            this.style.bottom = '';
            return;
        }

        // EXPANDED
        const pos = this._constrainPosition(this._getPosition().x, this._getPosition().y);
        this.style.top = pos.y + 'px';
        this.style.left = pos.x + 'px';
        this.style.width = this._width + 'px';
        this.style.height = this._height + 'px';
        this.style.right = '';
        this.style.bottom = '';
    }

    /** Get current position, falling back to default bottom-right */
    _getPosition() {
        if (this._posX !== null && this._posY !== null) {
            return {x: this._posX, y: this._posY};
        }
        return this._defaultPosition();
    }


    /**
     * Can this browser detach the panel into its own window?
     *
     * Document Picture-in-Picture is Chromium-only. Where it is missing the
     * button must not appear at all — one that throws on click is worse than
     * none, and there is nothing to degrade to: window.open() yields a window
     * with browser chrome that cannot float above other applications, which is
     * the entire point of detaching.
     */
    _canPopOut() {
        return typeof window !== 'undefined' && 'documentPictureInPicture' in window;
    }

    /**
     * Move the panel into a separate, always-on-top window.
     *
     * A DOM element cannot leave the browser window — a browser boundary, not a
     * limitation of this code — so "put it next to the browser" means putting it
     * in a window the operating system owns, which the user can drag anywhere,
     * second monitor included.
     *
     * The element is MOVED rather than re-rendered into the new document: the
     * conversation lives on the controller attached to this instance, and a copy
     * would start empty. Lit keeps its styles on the shadow root so they travel
     * with it, and the --nr-chat-* properties fall back to their literals once
     * the backend's --typo3-* are out of reach.
     *
     * @return {Promise<boolean>} whether the panel is now detached
     */
    async popOut() {
        if (!this._canPopOut() || this._pipWindow) {
            return false;
        }

        let pipWindow;
        try {
            pipWindow = await window.documentPictureInPicture.requestWindow({
                width: this._width,
                height: this._height,
            });
        } catch {
            // Chromium refuses without a user gesture, and refuses a second
            // window while one is open. Neither may lose the panel.
            return false;
        }

        this._pipHome = this.parentNode;
        this._pipWindow = pipWindow;

        pipWindow.addEventListener('pagehide', () => this._returnFromPopOut());
        pipWindow.document.body.append(this);
        this._applySize();

        return true;
    }

    /** Put the panel back where it came from when its window goes away. */
    _returnFromPopOut() {
        const home = this._pipHome;
        this._pipWindow = null;
        this._pipHome = null;

        if (home) {
            home.append(this);
        }

        // _applySize() only runs on a reactive property change, and returning
        // home is not one — without this the panel would keep the 100% sizing
        // it wore inside the detached window.
        this._applySize();
    }

    /**
     * Constrain position so the panel stays REACHABLE — not so it stays whole.
     *
     * Keeping it entirely inside the viewport meant it always covered part of
     * whatever was underneath, and the only ways out were collapsing it or
     * closing it. It may now hang off the left, right or bottom edge, down to a
     * MIN_VISIBLE margin that is still large enough to grab and pull back.
     *
     * The top edge is the exception and stays closed: dragging happens by the
     * header, so a panel allowed above y=0 loses its own handle and cannot be
     * recovered at all. A loosened clamp that lets the panel be lost is a worse
     * bug than the one this fixes.
     */
    _constrainPosition(x, y) {
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const w = this._width;
        const h = this.state === STATES.COLLAPSED ? COLLAPSED_HEIGHT : this._height;

        x = Math.max(MIN_VISIBLE - w, Math.min(x, vw - MIN_VISIBLE));
        y = Math.max(0, Math.min(y, vh - MIN_VISIBLE));

        // A panel narrower or shorter than the margin would otherwise be pushed
        // further out than its own size allows.
        if (w < MIN_VISIBLE) {
            x = Math.max(0, Math.min(x, vw - w));
        }
        if (h < MIN_VISIBLE) {
            y = Math.max(0, Math.min(y, vh - h));
        }

        return {x, y};
    }

    // ── Public API ──────────────────────────────────────────────────────

    toggle() {
        if (this.state === STATES.HIDDEN || this.state === STATES.COLLAPSED) {
            this.state = STATES.EXPANDED;
            this.chat.startPollingIfNeeded();
            this.updateComplete.then(() => this.onFocusInput());
        } else {
            this.state = STATES.HIDDEN;
            this.chat.stopPolling();
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
        this.chat.stopPolling();
        this._saveState();
    }

    maximize() {
        this.state = this.state === STATES.MAXIMIZED ? STATES.EXPANDED : STATES.MAXIMIZED;
        this._saveState();
    }

    // ── ChatCoreController callback hooks ───────────────────────────────

    onScrollToBottom(force = false) {
        // Wait for Lit to finish rendering, then scroll
        this.updateComplete.then(() => {
            requestAnimationFrame(() => {
                const el = this.renderRoot?.querySelector('.panel-messages');
                if (!el) return;
                if (force) {
                    el.scrollTop = el.scrollHeight;
                    return;
                }
                // Auto-scroll if user is in the lower half of the scrollable area
                const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
                if (distanceFromBottom < el.clientHeight * 0.5) {
                    el.scrollTop = el.scrollHeight;
                }
            });
        });
    }

    onFocusInput() {
        if (this._renamingUid) return;
        this.updateComplete.then(() => {
            this.renderRoot?.querySelector('.panel-input textarea')?.focus();
        });
    }

    onResetInput() {
        const ta = this.renderRoot?.querySelector('.panel-input textarea');
        if (ta) ta.style.height = 'auto';
    }

    // ── Drag (move) ─────────────────────────────────────────────────────

    _onHeaderClick(e) {
        // Clicking the header in collapsed state expands the panel
        if (this.state === STATES.COLLAPSED) {
            // Don't expand if a button was clicked (collapse/maximize/close)
            const path = e.composedPath();
            const clickedButton = path.some(el => el.tagName === 'BUTTON');
            if (!clickedButton) {
                this.state = STATES.EXPANDED;
                this._saveState();
            }
        }
    }

    _onHeaderDblClick(e) {
        // Double-click header toggles between expanded and maximized
        if (!e.target.closest('button, .btn-icon')) {
            this.maximize();
        }
    }

    _onDragStart(e) {
        if (e.button !== 0) return; // left button only
        if (e.target.closest('button, .btn-icon')) return;
        if (this.state === STATES.COLLAPSED) return;
        e.preventDefault();

        // setPointerCapture ensures pointerup is always received — even when the
        // mouse moves over TYPO3's content iframes or leaves the browser window.
        const handle = e.currentTarget;
        handle.setPointerCapture(e.pointerId);
        this._dragging = true;

        const rect = this.getBoundingClientRect();
        this._dragOffsetX = e.clientX - rect.left;
        this._dragOffsetY = e.clientY - rect.top;

        document.body.style.cursor = 'grabbing';

        const onMove = (ev) => {
            if (!ev.isPrimary) return;
            const constrained = this._constrainPosition(ev.clientX - this._dragOffsetX, ev.clientY - this._dragOffsetY);
            this.style.left = constrained.x + 'px';
            this.style.top = constrained.y + 'px';
        };

        const onEnd = () => {
            this._dragging = false;
            document.body.style.cursor = '';
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onEnd);
            handle.removeEventListener('pointercancel', onEnd);
            this._posX = Number.parseFloat(this.style.left) || 0;
            this._posY = Number.parseFloat(this.style.top) || 0;
            this._saveState();
        };

        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onEnd);
        handle.addEventListener('pointercancel', onEnd);
    }

    // ── Resize (corner grip) ────────────────────────────────────────────

    _onResizeStart(e) {
        if (e.button !== 0) return; // left button only
        e.preventDefault();
        e.stopPropagation();

        const grip = e.currentTarget;
        grip.setPointerCapture(e.pointerId);
        this._resizing = true;

        this._startX = e.clientX;
        this._startY = e.clientY;
        // Use getBoundingClientRect() for actual rendered dimensions and position
        const rect = this.getBoundingClientRect();
        this._startWidth = rect.width;
        this._startHeight = rect.height;
        this._startLeft = rect.left;

        document.body.style.cursor = 'nwse-resize';

        const onMove = (ev) => {
            if (!ev.isPrimary) return;
            // Constrain right edge to viewport, not just a percentage of viewport width
            const maxW = window.innerWidth - this._startLeft;
            const newW = Math.max(MIN_WIDTH, Math.min(this._startWidth + (ev.clientX - this._startX), maxW));
            const newH = Math.max(MIN_HEIGHT, Math.min(this._startHeight + (ev.clientY - this._startY), window.innerHeight));
            this.style.width = newW + 'px';
            this.style.height = newH + 'px';
        };

        const onEnd = () => {
            this._resizing = false;
            document.body.style.cursor = '';
            grip.removeEventListener('pointermove', onMove);
            grip.removeEventListener('pointerup', onEnd);
            grip.removeEventListener('pointercancel', onEnd);

            const w = Number.parseFloat(this.style.width) || this._width;
            const h = Number.parseFloat(this.style.height) || this._height;
            this._width = w;
            if (h < 50) {
                this._height = COLLAPSED_HEIGHT;
                this.state = STATES.COLLAPSED;
            } else if (h > window.innerHeight * 0.9) {
                this._height = window.innerHeight;
                this.state = STATES.MAXIMIZED;
            } else {
                this._height = h;
                this.state = STATES.EXPANDED;
            }
            this._pendingWidth = null;
            this._pendingHeight = null;
            this._saveState();
        };

        grip.addEventListener('pointermove', onMove);
        grip.addEventListener('pointerup', onEnd);
        grip.addEventListener('pointercancel', onEnd);
    }

    _onResizeKeydown(e) {
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            this._height = Math.min(this._height + 50, window.innerHeight);
            if (this._height > window.innerHeight * 0.9) this.state = STATES.MAXIMIZED;
            else this.state = STATES.EXPANDED;
            this._saveState();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            this._height = Math.max(this._height - 50, COLLAPSED_HEIGHT);
            if (this._height < 50) { this._height = COLLAPSED_HEIGHT; this.state = STATES.COLLAPSED; }
            else this.state = STATES.EXPANDED;
            this._saveState();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            const maxW = window.innerWidth * 0.9;
            this._width = Math.min(this._width + 50, maxW);
            this._saveState();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            this._width = Math.max(this._width - 50, MIN_WIDTH);
            this._saveState();
        }
    }

    // ── Persistence ─────────────────────────────────────────────────────

    _saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                state: this.state,
                height: this._height,
                width: this._width,
                x: this._posX,
                y: this._posY,
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
            if (typeof data.width === 'number' && data.width >= MIN_WIDTH) {
                this._width = data.width;
            }
            if (typeof data.x === 'number' && typeof data.y === 'number') {
                this._posX = data.x;
                this._posY = data.y;
            }
        } catch { /* ignore corrupted data */ }
    }

    // ── Keyboard ────────────────────────────────────────────────────────

    _onKeydown(e) {
        if (e.key === 'Escape' && this.state !== STATES.HIDDEN) {
            // Only collapse if focus is within the panel or no modal is open
            const active = document.activeElement;
            const inPanel = active === this || this.contains(active) || this.shadowRoot?.contains(active);
            const modalOpen = !!document.querySelector('.modal.show, typo3-backend-modal[open]');
            if (inPanel || (!modalOpen && !active?.closest('.dropdown-menu'))) {
                this.collapse();
            }
        }
    }

    // ── Input handling ──────────────────────────────────────────────────

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

    // ── Render ──────────────────────────────────────────────────────────

    render() {
        if (this.state === STATES.HIDDEN) return nothing;

        return html`
            ${this._renderHeader()}
            ${this.state === STATES.COLLAPSED ? nothing : this._renderBody()}
            ${this.state !== STATES.COLLAPSED && this.state !== STATES.MAXIMIZED ? html`
                <div class="resize-grip"
                     role="separator"
                     aria-orientation="horizontal"
                     aria-label="${lll('panel.resize')}"
                     tabindex="0"
                     @pointerdown=${(e) => this._onResizeStart(e)}
                     @keydown=${(e) => this._onResizeKeydown(e)}>
                    <svg viewBox="0 0 12 12" fill="currentColor">
                        <circle cx="9" cy="9" r="1.2"/>
                        <circle cx="5" cy="9" r="1.2"/>
                        <circle cx="9" cy="5" r="1.2"/>
                    </svg>
                </div>
            ` : nothing}
        `;
    }

    _renderHeader() {
        const conv = this.chat.getActiveConversation();
        const title = conv?.title || lll('panel.title');

        return html`
            <div class="panel-header"
                 @pointerdown=${(e) => this._onDragStart(e)}
                 @click=${(e) => this._onHeaderClick(e)}
                 @dblclick=${(e) => this._onHeaderDblClick(e)}>
                <span class="title">${title}</span>
                ${this.chat.status ? html`
                    <span class="status-badge status-${this.chat.status}" title="${this.chat.status}">${STATUS_ICONS[this.chat.status] ?? this.chat.status}</span>
                ` : nothing}
                <button class="btn-icon" @click=${() => this.collapse()}
                        title="${lll('panel.collapse')}" aria-label="${lll('panel.collapse')}">${ICON_MINIMIZE(14)}</button>
                <button class="btn-icon" @click=${() => this.maximize()}
                        title="${this.state === STATES.MAXIMIZED ? lll('panel.restore') : lll('panel.maximize')}"
                        aria-label="${this.state === STATES.MAXIMIZED ? lll('panel.restore') : lll('panel.maximize')}">
                    ${this.state === STATES.MAXIMIZED ? ICON_RESTORE(14) : ICON_MAXIMIZE(14)}
                </button>
                ${this._canPopOut() ? html`
                    <button class="btn-icon" data-action="popout" @click=${() => this.popOut()}
                            title="${lll('panel.popOut')}" aria-label="${lll('panel.popOut')}">${ICON_POPOUT(14)}</button>
                ` : nothing}
                <button class="btn-icon" @click=${() => this.hide()}
                        title="${lll('panel.close')}" aria-label="${lll('panel.close')}">${ICON_CLOSE(14)}</button>
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
                    ${this.state === STATES.EXPANDED ? this._renderConvTabs() : nothing}
                    ${this._renderChat()}
                </div>
            </div>
        `;
    }

    _renderSidebar() {
        return html`
            <div class="panel-sidebar">
                <div class="panel-sidebar-header">
                    <h3>${lll('conversations.title')}</h3>
                    <button class="btn-icon"
                            @click=${() => this.chat.handleNewConversation()}
                            ?disabled=${!this.chat.available}
                            title="${lll('conversations.new')}"
                            aria-label="${lll('conversations.new')}">${ICON_COMPOSE(14)}</button>
                </div>
                <div class="sidebar-list" role="listbox" aria-label="${lll('conversations.title')}">
                    ${this.chat.conversations.length === 0
                        ? html`<div class="empty-state" style="font-size:12px;">${lll('conversations.empty')}</div>`
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
                    ${c.pinned ? '\u{1F4CC} ' : ''}${c.title || lll('conversations.newConversation')}
                </span>
                <span class="status-badge status-${c.status}" title="${c.status}">${STATUS_ICONS[c.status] ?? c.status}</span>
                ${isActive ? html`
                    <span class="sidebar-item-actions">
                        <button class="btn-icon btn-sm" @click=${(e) => { e.stopPropagation(); this.chat.handleTogglePin(); }}
                                title="${c.pinned ? lll('conversations.unpin') : lll('conversations.pin')}"
                                aria-label="${c.pinned ? lll('conversations.unpin') : lll('conversations.pin')}">
                            ${'\u{1F4CC}'}
                        </button>
                        <button class="btn-icon btn-sm" @click=${(e) => { e.stopPropagation(); this.chat.handleArchive(); }}
                                title="${lll('conversations.archive')}" aria-label="${lll('conversations.archive')}">
                            \u{1F5C4}
                        </button>
                    </span>
                ` : nothing}
            </div>
        `;
    }

    _renderConvTabs() {
        return html`
            <div class="conv-tabs" role="tablist" aria-label="${lll('conversations.title')}">
                ${this.chat.conversations.map(c => {
                    const isActive = c.uid === this.chat.activeUid;
                    const isRenaming = this._renamingUid === c.uid;
                    const icon = STATUS_ICONS[c.status] ?? '';
                    const title = c.title || lll('conversations.newConversation');
                    return html`
                        <button class="conv-tab ${isActive ? 'active' : ''}"
                                role="tab"
                                aria-selected="${isActive}"
                                title="${title} (${c.status})"
                                @click=${() => this.chat.selectConversation(c.uid)}>
                            <span class="tab-icon status-${c.status}">${icon}</span>
                            ${isRenaming ? html`
                                <input class="tab-rename-input"
                                       .value=${title}
                                       @click=${(e) => e.stopPropagation()}
                                       @keydown=${(e) => {
                                           e.stopPropagation();
                                           if (e.key === 'Enter') { e.preventDefault(); this._commitRename(c.uid, e.target.value); }
                                           if (e.key === 'Escape') { this._renamingUid = null; }
                                       }}
                                       @blur=${(e) => this._commitRename(c.uid, e.target.value)}
                                       ${ref(this._renameInputRef)}
                                />
                            ` : html`
                                <span class="tab-title"
                                      @dblclick=${(e) => { e.stopPropagation(); this._renamingUid = c.uid; }}>
                                    ${title}
                                </span>
                            `}
                            <span class="tab-close"
                                  title="${lll('conversations.archive')}"
                                  @click=${(e) => { e.stopPropagation(); this.chat.handleArchive(c.uid); }}>✕</span>
                        </button>
                    `;
                })}
                <button class="btn-icon conv-tab-new"
                        @click=${() => this.chat.handleNewConversation()}
                        ?disabled=${!this.chat.available}
                        title="${lll('conversations.new')}"
                        aria-label="${lll('conversations.new')}">${ICON_COMPOSE(14)}</button>
            </div>
        `;
    }

    // Stable function reference so Lit's ref directive only fires on mount/unmount,
    // not on every re-render (an inline arrow would be a new reference each render).
    _renameInputRef(el) {
        if (el) requestAnimationFrame(() => { el.focus(); el.select(); });
    }

    _commitRename(uid, value) {
        // Guard against double-fire: Enter sets _renamingUid = null → Lit removes the input
        // from the DOM → blur fires on the detached input → this method is called again.
        if (this._renamingUid !== uid) return;
        this._renamingUid = null;
        this.chat.handleRename(uid, value);
    }

    /**
     * See chat-app.js: the bare sentence named a choice and hid both of its
     * options — the only way to act on it was an icon button in the header.
     */
    _renderEmptyStateGuidance() {
        return html`
            <div class="empty-state-guidance">
                <h2>${lll('chat.empty.title')}</h2>
                <p>${lll('chat.empty.body')}</p>
                <button class="btn btn-primary"
                    @click=${() => this.chat.handleNewConversation()}
                    ?disabled=${!this.chat.available}>
                    ${ICON_COMPOSE(14)} ${lll('conversations.new')}
                </button>
                <p class="empty-state-hint">${lll('chat.empty.hint')}</p>
            </div>
        `;
    }

    _renderChat() {
        if (!this.chat.activeUid) {
            return html`
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
            <div class="panel-messages" aria-live="polite" aria-relevant="additions">
                ${this.chat.messages.map((msg, idx) => this._renderMessage(msg, idx))}
                ${this.chat.isProcessing() ? html`
                    <div class="message-row assistant" aria-label="${lll('chat.processing')}">
                        <div class="avatar avatar-assistant">${AVATAR_ASSISTANT(14)}</div>
                        <div class="typing-indicator" aria-hidden="true"><span></span><span></span><span></span></div>
                    </div>
                ` : nothing}
                ${this._renderStatusNotice(isResumable)}
            </div>
            ${this._renderInput()}
        `;
    }

    _renderMessage(msg, idx) {
        const role = msg.role || 'system';
        if (role === 'assistant' && msg.tool_calls && !msg.content) return nothing;

        // Tool messages — no avatar, collapsible
        if (role === 'tool') {
            const isExpanded = this.chat.expandedTools.has(idx);
            return html`
                <div class="message tool ${isExpanded ? 'expanded' : ''}"
                     role="button"
                     tabindex="0"
                     aria-label="${lll('tool.output')}"
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
            : unsafeHTML(this.chat.renderMessageContent(msg));

        return html`
            <div class="message-row ${role}">
                ${isUser ? nothing : html`<div class="avatar avatar-assistant">${AVATAR_ASSISTANT(14)}</div>`}
                <div class="message-bubble">
                    <div class="message ${role}">${bubbleContent}</div>
                    ${time ? html`<div class="message-time">${time}</div>` : nothing}
                </div>
                ${isUser ? html`<div class="avatar avatar-user">${AVATAR_USER(14)}</div>` : nothing}
            </div>
        `;
    }

    /**
     * The status notice under the transcript: a pending approval, or an error.
     *
     * Extracted from render() because the two are one chained conditional with
     * a further one inside it, which is hard to read and pushed render() past
     * its complexity budget. The branches are unchanged.
     *
     * @param {boolean} isResumable
     */
    _renderStatusNotice(isResumable) {
        const dismiss = () => { this.chat.errorMessage = ''; this.requestUpdate(); };

        if (!this.chat.errorMessage) {
            return nothing;
        }

        if (this.chat.status === 'awaiting_approval') {
            // No Retry here: restarting would step past an approval that is
            // still pending.
            return html`
                <div class="message system" style="color:var(--nr-chat-status-info, #0277bd);">
                    ${lll('chat.approvalPending')}: ${this.chat.errorMessage}
                    ${this._renderApprovalCard()}
                    <button class="btn btn-sm btn-icon" @click=${dismiss}
                        style="margin-left:4px;" title="${lll('chat.dismiss')}" aria-label="${lll('chat.dismiss')}">&times;</button>
                </div>
            `;
        }

        return html`
            <div class="message system" style="color:var(--nr-chat-status-danger, #c62828);">
                Error: ${this.chat.errorMessage}
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
     * The pending tool call, with the decision on it.
     *
     * Rendered inside the notice rather than as a link away from it: the run is
     * this conversation's, the decision goes through the same per-run
     * authorisation as the approvals module, and the answer arrives here. The
     * link to the module stays, as the way to see the whole run.
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

        return html`
            <div class="approval-card">
                ${pending.calls.map((call) => html`
                    <div class="approval-call">
                        <code>${call.name}</code>
                        ${call.toolStillRegistered ? nothing : html`
                            <span class="approval-warning">${lll('chat.approvalToolGone')}</span>
                        `}
                        ${call.previewLines && call.previewLines.length ? html`
                            <div class="approval-preview">
                                <strong>${call.previewFailed
                                    ? lll('chat.approvalPreviewUnavailable')
                                    : lll('chat.approvalPreview')}</strong>
                                <ul>${call.previewLines.map((line) => html`<li>${line}</li>`)}</ul>
                            </div>
                        ` : nothing}
                        <details>
                            <summary>${lll('chat.approvalArguments')}</summary>
                            <pre><code>${call.argumentsJson}</code></pre>
                        </details>
                    </div>
                `)}
                <div class="approval-actions">
                    <button class="btn btn-sm btn-primary" ?disabled=${this.chat.approvalBusy}
                        @click=${() => this.chat.decideApproval(true)}>${lll('chat.approvalApprove')}</button>
                    <button class="btn btn-sm" ?disabled=${this.chat.approvalBusy}
                        @click=${() => this.chat.decideApproval(false)}>${lll('chat.approvalDeny')}</button>
                    ${this._renderApprovalLink()}
                </div>
            </div>
        `;
    }

    /** Link to the run waiting for an approval; absent when there is none. */
    _renderApprovalLink() {
        if (!this.chat.approvalUrl) {
            return nothing;
        }

        return html`
            <a class="btn btn-sm" href="${this.chat.approvalUrl}"
                style="margin-left:8px;">${lll('chat.approvalOpen')}</a>
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
                <button class="btn-icon"
                        ?disabled=${!canAttach}
                        title="${canAttach ? lll('attachment.attach') : lll('attachment.limitReached')}"
                        aria-label="${lll('attachment.attach')}"
                        aria-expanded="${String(this._attachMenuOpen)}"
                        aria-haspopup="menu"
                        @click=${(e) => { e.stopPropagation(); this._attachMenuOpen = !this._attachMenuOpen; }}>
                    ${ICON_PAPERCLIP(14)}${ICON_CHEVRON_DOWN(10)}
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

    _renderInput() {
        return html`
            ${this._renderFileBadge()}
            <div class="panel-input">
                ${this._renderAttachmentMenu()}
                <div class="input-wrap">
                    <textarea
                        .value=${this.chat.inputValue}
                        @input=${this._handleInput}
                        @keydown=${this._handleKeydown}
                        placeholder="${lll('chat.placeholder')}"
                        aria-label="${lll('chat.placeholder')}"
                        ?disabled=${!this.chat.available || this.chat.isProcessing()}
                        rows="2"
                    ></textarea>
                    <button class="btn-send"
                            @click=${() => this.chat.handleSend()}
                            aria-label="${lll('chat.send')}"
                            title="${lll('chat.send')}"
                            ?disabled=${!this.chat.hasInput || this.chat.sending || this.chat.isProcessing() || !this.chat.available}>
                        ${this.chat.sending ? html`<span class="spinner" style="width:12px;height:12px;border-width:2px;"></span>` : ICON_SEND(14)}
                    </button>
                </div>
            </div>
        `;
    }
}

customElements.define('ai-chat-panel', AiChatPanel);
