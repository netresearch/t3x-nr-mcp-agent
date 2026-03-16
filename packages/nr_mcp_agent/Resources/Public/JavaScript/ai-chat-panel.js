import {LitElement, html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';
import {ChatCoreController} from './chat-core.js';

const STATES = {HIDDEN: 'hidden', COLLAPSED: 'collapsed', EXPANDED: 'expanded', MAXIMIZED: 'maximized'};
const DEFAULT_HEIGHT = 350;
const DEFAULT_WIDTH = 480;
const MIN_WIDTH = 320;
const MIN_HEIGHT = 120;
const COLLAPSED_HEIGHT = 36;
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
    };

    static styles = css`
        :host {
            position: fixed;
            z-index: calc(var(--typo3-zindex-modal-backdrop, 1050) - 10);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.18), 0 0 0 1px rgba(0, 0, 0, 0.06);
            border-radius: 12px;
            font-family: var(--typo3-font-family, sans-serif);
            background: var(--typo3-surface-container-lowest, #fff);
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
            outline: 2px solid var(--typo3-primary, #0078d4);
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
            background: linear-gradient(to bottom, var(--typo3-surface-container-low, #f5f5f5), color-mix(in srgb, var(--typo3-surface-container-low, #f5f5f5) 85%, transparent));
            border-bottom: 1px solid var(--typo3-list-border-color, #ccc);
            cursor: grab;
            flex-shrink: 0;
            user-select: none;
            -webkit-user-select: none;
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
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .message {
            max-width: 85%;
            padding: 5px 9px;
            border-radius: 10px;
            font-size: 12.5px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .message.user {
            align-self: flex-end;
            background: #0078d4;
            color: #fff;
            border-bottom-right-radius: 3px;
        }
        .message.assistant {
            align-self: flex-start;
            background: var(--typo3-surface-container-high, #e8e8e8);
            border-bottom-left-radius: 3px;
        }
        .message.tool {
            align-self: flex-start;
            background: var(--typo3-surface-container, #f0f0f0);
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
            border-radius: 6px;
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
            border-radius: 8px;
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
            border-radius: 6px;
        }
        .btn-icon:hover {
            background: var(--typo3-state-hover, rgba(0, 0, 0, 0.04));
        }

        /* Status */
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
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
        this._width = DEFAULT_WIDTH;
        this._posX = null;
        this._posY = null;
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
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        document.removeEventListener('keydown', this._keydownHandler);
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
            const pos = this._getPosition();
            this.style.top = pos.y + 'px';
            this.style.left = pos.x + 'px';
            this.style.width = this._width + 'px';
            this.style.height = COLLAPSED_HEIGHT + 'px';
            this.style.right = '';
            this.style.bottom = '';
            return;
        }

        // EXPANDED
        const pos = this._getPosition();
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

    /** Constrain position so the panel stays within the viewport */
    _constrainPosition(x, y) {
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const w = this._width;
        const h = this.state === STATES.COLLAPSED ? COLLAPSED_HEIGHT : this._height;
        x = Math.max(0, Math.min(x, vw - w));
        y = Math.max(0, Math.min(y, vh - h));
        return {x, y};
    }

    // ── Public API ──────────────────────────────────────────────────────

    toggle() {
        if (this.state === STATES.HIDDEN) {
            this.state = this._lastVisibleState || STATES.EXPANDED;
            this.chat.startPollingIfNeeded();
            this.updateComplete.then(() => this.onFocusInput());
        } else {
            this._lastVisibleState = this.state;
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
                // Auto-scroll if user is near the bottom (within 150px)
                if (el.scrollHeight - el.scrollTop - el.clientHeight < 150) {
                    el.scrollTop = el.scrollHeight;
                }
            });
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
        if (e.target.closest('button, .btn-icon')) return;
        if (this.state === STATES.COLLAPSED) return;
        e.preventDefault();
        this._dragging = true;

        const clientX = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.startsWith('touch') ? e.touches[0].clientY : e.clientY;
        this._dragOffsetX = clientX - (parseFloat(this.style.left) || 0);
        this._dragOffsetY = clientY - (parseFloat(this.style.top) || 0);

        document.body.style.userSelect = 'none';

        const onMove = (ev) => {
            ev.preventDefault();
            const cx = ev.type.startsWith('touch') ? ev.touches[0].clientX : ev.clientX;
            const cy = ev.type.startsWith('touch') ? ev.touches[0].clientY : ev.clientY;
            const constrained = this._constrainPosition(cx - this._dragOffsetX, cy - this._dragOffsetY);
            this.style.left = constrained.x + 'px';
            this.style.top = constrained.y + 'px';
        };

        const onEnd = () => {
            this._dragging = false;
            document.body.style.userSelect = '';
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onEnd);
            this._posX = parseFloat(this.style.left) || 0;
            this._posY = parseFloat(this.style.top) || 0;
            this._saveState();
        };

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchmove', onMove, {passive: false});
        document.addEventListener('touchend', onEnd);
    }

    // ── Resize (corner grip) ────────────────────────────────────────────

    _onResizeStart(e) {
        e.preventDefault();
        e.stopPropagation();
        this._resizing = true;

        const clientX = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.startsWith('touch') ? e.touches[0].clientY : e.clientY;
        this._startX = clientX;
        this._startY = clientY;
        this._startWidth = parseFloat(this.style.width) || this._width;
        this._startHeight = parseFloat(this.style.height) || this._height;

        document.body.style.userSelect = 'none';

        const onMove = (ev) => {
            ev.preventDefault();
            const cx = ev.type.startsWith('touch') ? ev.touches[0].clientX : ev.clientX;
            const cy = ev.type.startsWith('touch') ? ev.touches[0].clientY : ev.clientY;
            const newW = Math.max(MIN_WIDTH, Math.min(this._startWidth + (cx - this._startX), window.innerWidth * 0.9));
            const newH = Math.max(MIN_HEIGHT, Math.min(this._startHeight + (cy - this._startY), window.innerHeight));
            this.style.width = newW + 'px';
            this.style.height = newH + 'px';
        };

        const onEnd = () => {
            this._resizing = false;
            document.body.style.userSelect = '';
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onEnd);

            const w = parseFloat(this.style.width) || this._width;
            const h = parseFloat(this.style.height) || this._height;
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

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchmove', onMove, {passive: false});
        document.addEventListener('touchend', onEnd);
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
                     @mousedown=${(e) => this._onResizeStart(e)}
                     @touchstart=${(e) => this._onResizeStart(e)}
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
                 @mousedown=${(e) => this._onDragStart(e)}
                 @touchstart=${(e) => this._onDragStart(e)}
                 @click=${(e) => this._onHeaderClick(e)}
                 @dblclick=${(e) => this._onHeaderDblClick(e)}>
                <span class="title">${title}</span>
                ${this.chat.status ? html`
                    <span class="status-badge status-${this.chat.status}">${this.chat.status}</span>
                ` : nothing}
                <button class="btn-icon" @click=${() => this.collapse()}
                        title="${lll('panel.collapse')}" aria-label="${lll('panel.collapse')}">&#x2015;</button>
                <button class="btn-icon" @click=${() => this.maximize()}
                        title="${this.state === STATES.MAXIMIZED ? lll('panel.restore') : lll('panel.maximize')}"
                        aria-label="${this.state === STATES.MAXIMIZED ? lll('panel.restore') : lll('panel.maximize')}">
                    ${this.state === STATES.MAXIMIZED ? '\u2913' : '\u2912'}
                </button>
                <button class="btn-icon" @click=${() => this.hide()}
                        title="${lll('panel.close')}" aria-label="${lll('panel.close')}">&times;</button>
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
                    <h3>${lll('conversations.title')}</h3>
                    <button class="btn btn-sm btn-primary"
                            @click=${() => this.chat.handleNewConversation()}
                            ?disabled=${!this.chat.available}
                            aria-label="${lll('conversations.new')}">${lll('conversations.new')}</button>
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
                <span class="status-badge status-${c.status}">${c.status}</span>
                ${isActive ? html`
                    <span class="sidebar-item-actions">
                        <button class="btn-icon btn-sm" @click=${(e) => { e.stopPropagation(); this.chat.handleTogglePin(); }}
                                title="${c.pinned ? lll('conversations.unpin') : lll('conversations.pin')}"
                                aria-label="${c.pinned ? lll('conversations.unpin') : lll('conversations.pin')}">
                            ${c.pinned ? '\u{1F4CC}' : '\u{1F4CC}'}
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

    _renderCompactSwitcher() {
        return html`
            <div class="compact-switcher">
                <select @change=${(e) => this.chat.selectConversation(Number(e.target.value))}
                        aria-label="${lll('conversations.select')}">
                    ${!this.chat.activeUid ? html`<option value="" selected disabled>${lll('conversations.select')}</option>` : nothing}
                    ${this.chat.conversations.map(c => html`
                        <option value=${c.uid} ?selected=${c.uid === this.chat.activeUid}>
                            ${c.pinned ? '\u{1F4CC} ' : ''}${c.title || lll('conversations.newConversation')}
                        </option>
                    `)}
                </select>
                <button class="btn btn-sm btn-primary"
                        @click=${() => this.chat.handleNewConversation()}
                        ?disabled=${!this.chat.available}
                        aria-label="${lll('conversations.new')}">${lll('conversations.new')}</button>
            </div>
        `;
    }

    _renderChat() {
        if (!this.chat.activeUid) {
            return html`
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
            <div class="panel-messages" aria-live="polite" aria-relevant="additions">
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
                    placeholder="${lll('chat.placeholder')}"
                    aria-label="${lll('chat.placeholder')}"
                    ?disabled=${!this.chat.available || this.chat.isProcessing()}
                    rows="1"
                ></textarea>
                <button class="btn btn-primary btn-sm"
                        @click=${() => this.chat.handleSend()}
                        aria-label="${lll('chat.send')}"
                        ?disabled=${!this.chat.hasInput || this.chat.sending || this.chat.isProcessing() || !this.chat.available}>
                    ${this.chat.sending ? html`<span class="spinner"></span>` : lll('chat.send')}
                </button>
            </div>
        `;
    }
}

customElements.define('ai-chat-panel', AiChatPanel);
