// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

import {LitElement, html, css, nothing} from 'lit';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import {ref} from 'lit/directives/ref.js';
import {lll} from '@typo3/core/lit-helper.js';
import {ChatCoreController, downloadTextFile, statusLabel, statusTone} from '@netresearch/nr-mcp-agent/chat-core.js';
import {conversationTitle, conversationTooltip, filterConversations, listedConversations, splitConversationTabs} from '@netresearch/nr-mcp-agent/conversation-tabs.js';
import {conversationListStyles, conversationTime, renderConversationSummary, renderStatusIcon} from '@netresearch/nr-mcp-agent/conversation-list.js';
import {markdownStyles} from '@netresearch/nr-mcp-agent/markdown-styles.js';
import {themeStyles} from '@netresearch/nr-mcp-agent/theme.js';
import {AVATAR_ASSISTANT, AVATAR_USER, ICON_PAPERCLIP, ICON_SEND, ICON_COMPOSE, ICON_MINIMIZE, ICON_MAXIMIZE, ICON_RESTORE, ICON_CLOSE, ICON_COLUMN, ICON_POPOUT, ICON_CHEVRON_DOWN, ICON_UPLOAD, ICON_DOWNLOAD, ICON_INSTRUCTIONS, ICON_ACTIVITY, ICON_APPROVAL} from '@netresearch/nr-mcp-agent/icons.js';
import {COLUMN_DEFAULT_WIDTH, COLUMN_MIN_WIDTH, LAYOUT, clampColumnWidth, columnMaxWidth, findColumnMount, markColumnMount, readColumnWidth, readLayoutPreference, saveColumnWidth, saveLayoutPreference, readFoldedModuleMenu, saveFoldedModuleMenu, setModuleMenuOpen, splitterKeyWidth} from '@netresearch/nr-mcp-agent/chat-column.js';
import {chatActivityStyles, renderActivity} from '@netresearch/nr-mcp-agent/chat-activity.js';
import {chatGuidedStyles, renderEndTour, renderReadOnlyNotice, renderHighlightAnnouncement, renderPageChange, renderPageChoice, renderProgress, renderRoundLimit, renderTourEnd, sendHighlight} from '@netresearch/nr-mcp-agent/chat-guided.js';
import {chatReplyOptionsStyles, decisionLabel, renderDenyButtons, renderReplyOptions, replyPlaceholder} from '@netresearch/nr-mcp-agent/chat-reply-options.js';
import {chatProposalStyles, isProposal, renderProposal} from '@netresearch/nr-mcp-agent/chat-proposal.js';
import {approveBlockedLabel, chatAltTextStyles, isAltTextCard, renderAltTextCard, renderAltTextMessage, renderAltTextNext} from '@netresearch/nr-mcp-agent/chat-alt-text.js';
import {contextChipsStyles, renderContextChips} from '@netresearch/nr-mcp-agent/chat-context-chips.js';
import {chatOutcomesStyles, renderOutcomeLines, renderSummary} from '@netresearch/nr-mcp-agent/chat-outcomes.js';
import {chatSlashStyles, handleSlashKeydown, renderActiveSkill, renderSlashList, slashAria, SLASH_LIST_ID} from '@netresearch/nr-mcp-agent/chat-slash-commands.js';
import {chatEditingStyles, renderMessageBody, renderInstructionsEditor, instructionsLabel} from '@netresearch/nr-mcp-agent/chat-editing.js';
import {registerPanel, unregisterPanel} from '@netresearch/nr-mcp-agent/chat-panel-registry.js';

const STATES = {HIDDEN: 'hidden', COLLAPSED: 'collapsed', EXPANDED: 'expanded', MAXIMIZED: 'maximized'};
const DEFAULT_HEIGHT = 350;
const DEFAULT_WIDTH = 480;
const MIN_WIDTH = 320;
const MIN_HEIGHT = 120;
const COLLAPSED_HEIGHT = 36;
// How much of the panel must stay on screen when it is pushed off an edge —
// enough of the header to grab and drag it back.
const MIN_VISIBLE = 64;
const STORAGE_KEY = 'ai-chat-panel';
// The page tree's selection and a loaded module change the page the chat is
// about (ModuleStateStorage, dispatched on the backend's top document).
const PAGE_CONTEXT_EVENTS = [
    'typo3:module-state-storage:update:web',
    'typo3:module-state-storage:update-with-tree-identifier:web',
    'typo3-module-loaded',
    'typo3:pagetree:refresh',
];
// After these the page may carry a new title: saving the page properties
// reloads the page module, and renaming the page in the page module's header
// saves by AJAX and only refreshes the page tree.
const PAGE_TITLE_EVENTS = new Set(['typo3-module-loaded', 'typo3:pagetree:refresh']);
// The column's width is written to the user's settings this long after the
// last key press on the splitter: one request per adjustment, not per step.
const COLUMN_PERSIST_DELAY = 400;
const COLUMN_BODY_ID = 'nr-chat-column-body';
// How much wider than twice the column's minimum the content area must get
// before a column given way to the floating panel docks again.
const NARROW_DEAD_BAND = 24;

/**
 * <ai-chat-panel> - Floating draggable panel for AI chat.
 *
 * Panel states: HIDDEN (display:none), COLLAPSED (header only),
 * EXPANDED (chat + compact conversation switcher),
 * MAXIMIZED (full height with sidebar).
 *
 * Layouts (ADR-024): FLOATING, the panel above the backend, or COLUMN, docked
 * beside the page tree in the backend's content area (chat-column.js). In the
 * column COLLAPSED is a small bar in the bottom right corner, and there is no
 * MAXIMIZED.
 *
 * All chat logic is delegated to ChatCoreController.
 */
export class AiChatPanel extends LitElement {
    static properties = {
        state: {type: String, reflect: true},
        layout: {type: String, reflect: true},
        // The column is chosen but its content area is too narrow for it
        // (less than twice the column's minimum): the chat floats meanwhile.
        narrow: {type: Boolean, reflect: true},
        _columnWidth: {state: true},
        _areaWidth: {state: true},
        _height: {state: true},
        _width: {state: true},
        _posX: {state: true},
        _posY: {state: true},
        _attachMenuOpen: {type: Boolean, state: true},
        _renamingUid: {state: true},
        _moreOpen: {state: true},
        _moreQuery: {state: true},
        _moreIndex: {state: true},
    };

    static styles = [themeStyles, markdownStyles, chatEditingStyles, chatActivityStyles, chatGuidedStyles, chatReplyOptionsStyles, chatSlashStyles, chatProposalStyles, chatOutcomesStyles, chatAltTextStyles, contextChipsStyles, conversationListStyles, css`
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
            color: var(--nr-chat-text-variant);
            transition: color 0.15s;
        }
        .resize-grip:hover svg,
        .resize-grip:active svg,
        .resize-grip:focus-visible svg {
            color: var(--nr-chat-text);
        }
        .resize-grip:focus-visible {
            outline: 2px solid var(--nr-chat-focus-ring);
            outline-offset: -2px;
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
            position: relative;
            container-type: inline-size;
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
        .panel-sidebar-header h2 {
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
        .sidebar-item.active:focus-visible {
            outline: 2px solid var(--nr-chat-on-active);
            outline-offset: -4px;
            box-shadow: inset 0 0 0 2px var(--nr-chat-focus-ring);
            border-bottom-color: var(--nr-chat-focus-ring);
        }
        .sidebar-item.active .btn-icon {
            color: inherit;
        }
        .sidebar-item.active .btn-icon:hover {
            background: color-mix(in srgb, currentColor 15%, transparent);
        }
        .sidebar-item.active .btn-icon:focus-visible {
            outline: 2px solid var(--nr-chat-on-active);
            outline-offset: -2px;
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

        /*
         * Conversation tab bar (second row in expanded state).
         *
         * One row, never wrapped: it holds the active conversation and the
         * most recent ones, the rest are behind the "more" button. Wrapping
         * grew one row per handful of conversations until the chat area
         * below had no height left (NEXT-172).
         */
        .conv-tabs-wrap {
            position: relative;
            flex-shrink: 0;
        }
        .conv-tabs {
            display: flex;
            flex-wrap: nowrap;
            align-items: flex-end;
            gap: 2px;
            padding: 4px 8px 0;
            border-bottom: 1px solid var(--nr-chat-border);
            background: var(--nr-chat-surface-low);
            overflow: hidden;
        }
        .conv-tablist {
            display: flex;
            flex-wrap: nowrap;
            gap: 2px;
            flex: 0 1 auto;
            min-width: 0;
            overflow: hidden;
        }
        .conv-tab {
            flex: 0 1 auto;
            min-width: 0;
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
            /* Shrinks last: the open conversation's title is the one worth reading. */
            flex-shrink: 0.25;
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
        /* The approval frame: a system step, not a message (ADR-023). */
        .approval-frame {
            /* A flex item of the scrolling message column: without this it
               shrinks to its border once the transcript overflows. */
            flex-shrink: 0;
            align-self: stretch;
            border: 1px solid var(--nr-chat-border);
            border-radius: 6px;
            background: var(--nr-chat-surface-low);
            color: var(--nr-chat-text);
            font-size: 14px;
            font-style: normal;
            line-height: 1.5;
            overflow: hidden;
        }
        .approval-frame-header {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: var(--nr-chat-info-bg);
            color: var(--nr-chat-info-text);
            border-bottom: 1px solid var(--nr-chat-border);
            font-size: 12px;
            font-weight: 600;
        }
        .approval-frame-body { padding: 10px 14px; }
        .approval-pending { margin: 0; }
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
        .conv-tab-action {
            flex-shrink: 0;
            padding: 4px 6px;
            border-radius: 6px;
            color: var(--nr-chat-text-variant);
        }
        .conv-tab-action:hover { color: var(--nr-chat-text); }
        .conv-tab-more {
            flex-shrink: 0;
            max-width: none;
            border-radius: 6px 6px 0 0;
        }
        .conv-tab-more[aria-expanded="true"] {
            background: var(--nr-chat-surface);
            color: var(--nr-chat-text);
            border-color: var(--nr-chat-border);
        }
        .conv-tab-more:focus-visible,
        .conv-tab:focus-visible {
            outline: 2px solid var(--nr-chat-focus-ring);
            outline-offset: -2px;
        }

        /* "More" popover: search field + listbox (combobox pattern) */
        .conv-more-popover {
            position: absolute;
            top: 100%;
            left: 8px;
            right: 8px;
            z-index: 20;
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 6px;
            background: var(--nr-chat-surface);
            border: 1px solid var(--nr-chat-border);
            border-radius: 8px;
            box-shadow: var(--typo3-component-box-shadow-flyout, 0 2px 8px rgba(0,0,0,.15));
        }
        .conv-more-search {
            width: 100%;
            box-sizing: border-box;
            padding: 5px 8px;
            font-size: 12px;
            border: 1px solid var(--nr-chat-input-border);
            border-radius: 6px;
            background: var(--nr-chat-surface);
            color: var(--nr-chat-text);
        }
        .conv-more-search:focus {
            outline: none;
            border-color: var(--nr-chat-focus-ring);
            box-shadow: 0 0 0 1px var(--nr-chat-focus-ring);
        }
        .conv-more-list {
            list-style: none;
            margin: 0;
            padding: 0;
            max-height: 240px;
            overflow-y: auto;
        }
        .conv-more-option {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            color: var(--nr-chat-text);
        }
        .conv-more-option.highlighted {
            background: var(--nr-chat-hover);
            outline: 1px solid var(--nr-chat-focus-ring);
            outline-offset: -1px;
        }
        .conv-more-empty {
            padding: 5px 8px;
            font-size: 12px;
            color: var(--nr-chat-text-variant);
        }
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
        .avatar-user { background: var(--nr-chat-surface-high); color: var(--nr-chat-text); }
        .message-time {
            font-size: 10px;
            color: var(--nr-chat-text-variant);
            margin-top: 2px;
            padding: 0 2px;
        }
        .message {
            padding: 5px 9px;
            border-radius: 10px;
            font-size: 14px;
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
            color: var(--nr-chat-text-variant);
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
        .file-badge .remove { cursor: pointer; color: var(--nr-chat-text-variant); font-size: 16px; line-height: 1; }
        .file-badge .remove:hover { color: var(--nr-chat-text); }
        .message-file-badge {
            display: flex; align-items: center; gap: 4px;
            font-size: 11px; margin-bottom: 3px;
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
        /* The answers the reader gives: card buttons, reply options, tour
           buttons. Larger than the compact .btn-sm of the header. */
        .btn-answer {
            padding: 6px 12px;
            font-size: 14px;
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
        /*
         * The tones of every list (ADR-027): ready is neutral, never green;
         * waiting for the editor is a warning; running is information.
         */
        .status-neutral { background: var(--nr-chat-surface-variant); color: var(--nr-chat-text-variant); }
        .status-info { background: var(--nr-chat-info-bg); color: var(--nr-chat-info-text); }
        .status-warning { background: var(--nr-chat-warning-bg); color: var(--nr-chat-warning-text); box-shadow: inset 0 0 0 1px var(--nr-chat-warning-border); }
        .status-danger { background: var(--nr-chat-danger-bg); color: var(--nr-chat-danger-text); }
        .status-badge .conv-status-icon { color: inherit; }

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

        /* ── Column layout (ADR-024) ─────────────────────────────────── */

        /* A flex item of the backend's content area, before the module router. */
        :host([layout="column"]:not([narrow])) {
            position: relative;
            z-index: auto;
            flex: 0 0 auto;
            align-self: stretch;
            min-width: 0;
            border-radius: 0;
            box-shadow: none;
            border-inline-end: 1px solid var(--nr-chat-border);
        }
        /*
         * Its content must not size the row: in 13.4 the content area is a
         * grid cell that grows with what it holds, so a long transcript made
         * the column as tall as the transcript instead of scrolling it. The
         * width is set explicitly, the height comes from the row.
         */
        :host([layout="column"]:not([narrow]):not([state="collapsed"])) {
            contain: size;
        }
        :host([layout="column"]:not([narrow])) .panel-header {
            height: auto;
            min-height: 56px;
            padding: 4px 4px 4px 12px;
            border-radius: 0;
            cursor: default;
            touch-action: auto;
            user-select: auto;
            -webkit-user-select: auto;
            background: var(--nr-chat-surface);
        }
        :host([layout="column"]:not([narrow])) .panel-header:active {
            cursor: default;
        }
        .column-badge {
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--nr-chat-accent);
            color: var(--nr-chat-on-accent);
            font-size: 11px;
            font-weight: 700;
        }
        .column-heading {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }
        .column-heading .title {
            font-size: 14px;
        }
        .column-status {
            font-size: 12px;
            color: var(--nr-chat-text-variant);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /*
         * Icon buttons of the column header and the minimized bar: 44px
         * targets in the bar. In the header five of them share a column that
         * may be 320px wide with the title, so they are 36px there, above the
         * 24px minimum of WCAG 2.5.8, and sit closer together.
         */
        .layout-btn {
            flex-shrink: 0;
            min-width: 44px;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--nr-chat-text);
        }
        :host([layout="column"]:not([narrow])) .panel-header {
            gap: 4px;
        }
        .panel-header .layout-btn {
            min-width: 36px;
            min-height: 36px;
        }
        .layout-btn:focus-visible {
            outline: 2px solid var(--nr-chat-focus-ring);
            outline-offset: -2px;
        }
        [data-action="layout"][aria-pressed="true"] {
            background: var(--nr-chat-hover);
            box-shadow: inset 0 -2px 0 var(--nr-chat-focus-ring);
        }

        /* The splitter on the column's trailing edge (WAI-ARIA Window Splitter). */
        .column-splitter {
            position: absolute;
            inset-block: 0;
            inset-inline-end: -5px;
            width: 9px;
            cursor: col-resize;
            touch-action: none;
            z-index: 11;
        }
        .column-splitter:hover,
        .column-splitter.dragging {
            background: color-mix(in srgb, var(--nr-chat-focus-ring) 45%, transparent);
        }
        .column-splitter:focus-visible {
            outline: 2px solid var(--nr-chat-focus-ring);
            outline-offset: -2px;
            background: color-mix(in srgb, var(--nr-chat-focus-ring) 45%, transparent);
        }

        /* Minimized in the column: a bar in the bottom right corner. */
        :host([layout="column"]:not([narrow])[state="collapsed"]) {
            position: fixed;
            inset-inline-end: 24px;
            bottom: 24px;
            width: min(360px, calc(100vw - 48px));
            z-index: calc(var(--typo3-zindex-modal-backdrop, 1050) - 10);
            border-radius: 10px;
            border-inline-end: none;
            box-shadow: var(--typo3-component-box-shadow-flyout, 0 4px 24px rgba(0, 0, 0, 0.18)), 0 0 0 1px var(--nr-chat-border);
        }
        .minimized-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 4px 4px 14px;
        }
        .minimized-text {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }
        .minimized-title {
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .minimized-status {
            font-size: 12px;
            color: var(--nr-chat-text-variant);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .status-dot {
            flex-shrink: 0;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--nr-chat-status-warning);
        }
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
        this._moreOpen = false;
        this._moreQuery = '';
        this._moreIndex = 0;
        this._lastVisibleState = STATES.EXPANDED;
        this._resizing = false;
        this._dragging = false;
        this.layout = LAYOUT.FLOATING;
        this.narrow = false;
        // Read from the user's settings the first time the column opens.
        this._columnWidth = null;
        this._areaWidth = 0;
        this._markedMount = null;
        // Focus to put back after a template swap or a move (_focusKey()).
        this._refocus = null;
        // While startConversation() runs; a second call meanwhile is refused.
        this._starting = false;
        // The layout was taken for this opening only (setLayout()).
        this._layoutForThisOpening = false;
        // The conversation startConversation() created last (_reusableStart()).
        this._startedUid = null;
        // Whether optimiseAltText() folded the module menu, which then
        // unfolds when the chat closes (ADR-026); kept in the user's settings,
        // so a chat closed after a reload still unfolds it.
        this._foldedModuleMenu = readFoldedModuleMenu();
        // A row whose "Optimieren" came while the proposing turn ran.
        this._queuedAltText = 0;
        this._restoreState();
    }

    connectedCallback() {
        super.connectedCallback();
        // On the backend window, where the dashboard widgets look: in its own
        // window (popOut()) the element is not in the backend's document.
        registerPanel(window, this);
        // Every listener of this connection goes with it: moving the panel
        // (into the column, out of it, into its own window) reconnects it.
        this._connection = new AbortController();
        const {signal} = this._connection;
        // The backend's top document: this module's realm, also while the
        // panel is in its own window.
        const refreshPage = () => this.chat.refreshBackendPage();
        for (const type of PAGE_CONTEXT_EVENTS) {
            const listener = PAGE_TITLE_EVENTS.has(type)
                ? () => this.chat.refreshBackendPage(undefined, {reloadTitle: true})
                : refreshPage;
            document.addEventListener(type, listener, {signal});
        }
        // Which module and page are beside the panel now, for the row above
        // the input (NEXT-221); the events above keep it current.
        refreshPage();
        this._syncColumnMount();
        this.setAttribute('role', 'complementary');
        this.setAttribute('aria-label', lll('panel.title') || 'AI Chat');
        this.setAttribute('tabindex', '-1'); // focusable programmatically but not in tab order
        this._keydownHandler = (e) => this._onKeydown(e);
        document.addEventListener('keydown', this._keydownHandler);
        this._closeAttachMenu = (e) => {
            if (!e.composedPath().includes(this)) {
                this._attachMenuOpen = false;
            }
            // The popover and its button stop their own clicks, so any click
            // that reaches the document happened outside both.
            this._moreOpen = false;
        };
        document.addEventListener('click', this._closeAttachMenu);
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        // A move reconnects the element in the same step; only a panel that is
        // still out of every document afterwards is gone.
        queueMicrotask(() => {
            if (!this.isConnected) {
                unregisterPanel(window, this);
            }
        });
        document.removeEventListener('keydown', this._keydownHandler);
        document.removeEventListener('click', this._closeAttachMenu);
        this.chat.stopPageWatch();
        this._connection?.abort();
        this._areaObserver?.disconnect();
        this._areaObserver = null;
        this._observedArea = null;
        // Wherever the panel goes, the content area it left gets its full
        // width back.
        this._unmarkColumnMount();
    }

    updated(changed) {
        // Which page the page module beside the panel shows, while the guided
        // process asks for one or runs on one (ADR-023).
        this.chat.syncPageWatch();
        // The page title for the row above the input is read only while the
        // input shows (NEXT-221), and once when it comes into view.
        const wanted = this.state !== STATES.HIDDEN && this.state !== STATES.COLLAPSED;
        if (wanted !== this.chat.pageTitleWanted) {
            this.chat.pageTitleWanted = wanted;
            if (wanted) {
                void this.chat.loadPageTitle();
            }
        }
        // The page module beside the panel shows the element the guided
        // process is about (ADR-020).
        if (sendHighlight(this.chat, this.ownerDocument?.defaultView ?? globalThis)) {
            // The announcement of the new mark renders on the next pass.
            this.requestUpdate();
        }
        if (changed.has('state') || changed.has('layout') || changed.has('narrow')) {
            this._syncColumnMount();
        }
        // After the update, not during it: a move reconnects the element.
        // Runs on every update, so a move held back by a request in flight
        // happens with the update that follows its end.
        void Promise.resolve().then(() => {
            this._placeForLayout();
            if (this._refocus !== null) {
                const target = this._refocus;
                this._refocus = null;
                void this.updateComplete.then(() => this._restoreFocus(target));
            }
        });
        if (changed.has('state') || changed.has('layout') || changed.has('narrow') || changed.has('_height') || changed.has('_width')
            || changed.has('_posX') || changed.has('_posY') || changed.has('_columnWidth') || changed.has('_areaWidth')) {
            this._applySize();
        }
        if (changed.has('state')) {
            this.setAttribute('aria-expanded', String(this.state !== STATES.HIDDEN));
        }
        // Focus moves into the search field in the same update that opens the
        // list — not a frame later, where keys typed right after opening would
        // still reach the button, and a space there closes the list again.
        // Not from a ref callback either: Lit calls those before a new
        // template is attached, when focus() does nothing.
        if (changed.has('_moreOpen') && this._moreOpen) {
            this.renderRoot?.querySelector('.conv-more-search')?.focus();
        }
        // The highlighted option follows the arrow keys out of the visible
        // part of the list; keep it in view.
        if (changed.has('_moreIndex') && this._moreOpen) {
            this.renderRoot?.querySelector('.conv-more-option.highlighted')?.scrollIntoView?.({block: 'nearest'});
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

        // Column: a flex item as high as the content area. The floating
        // coordinates would pull it out of the row; minimized, the bar's size
        // and corner come from the stylesheet.
        if (this._docked()) {
            this.style.top = '';
            this.style.left = '';
            this.style.right = '';
            this.style.bottom = '';
            this.style.height = '';
            this.style.width = this.state === STATES.COLLAPSED ? '' : this._columnWidthNow() + 'px';
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

    // ── Column layout (ADR-024) ─────────────────────────────────────────

    /**
     * The content area the panel sits in as a column, or null when it is not
     * in one (floating, detached, or a column requested where the backend
     * has no content area).
     */
    _columnMount() {
        if (this.layout !== LAYOUT.COLUMN || !this.parentElement) {
            return null;
        }
        const mount = findColumnMount(document);

        return mount?.element === this.parentElement ? mount : null;
    }

    /**
     * Whether the panel is drawn as a column now: chosen, in its content
     * area, and the area is wide enough. A narrow area (a small window, the
     * preference followed the user to a smaller device) lets the module keep
     * its width and the chat float, without changing the stored choice.
     */
    _docked() {
        return this.layout === LAYOUT.COLUMN && !this.narrow;
    }

    /**
     * Whether the backend has a content area for the column and it is too
     * narrow for one: less than twice the column's minimum.
     */
    _areaTooNarrow() {
        return findColumnMount(document) !== null && this._areaWidthNow() < 2 * COLUMN_MIN_WIDTH;
    }

    /**
     * Whether a column can be opened here: not detached, the backend has a
     * content area, and that area is wide enough. The area's width is
     * observed in every layout, so a floating panel follows it too.
     */
    _canDock() {
        return !this._pipWindow && findColumnMount(document) !== null && !this._areaTooNarrow();
    }

    /**
     * Whether the panel may move now. A move reconnects the element, which
     * aborts the controller's requests in flight: a message being sent or
     * edited, a free-text answer, an approval decision, ending a guided
     * process, saving the instructions, uploading an attachment. Repeating
     * one could duplicate what the server already took.
     */
    _canMove() {
        const chat = this.chat;

        return !(chat.sending || chat.inputBusy || chat.approvalBusy || chat.endingTour || chat.systemPromptSaving || chat.uploading);
    }

    /**
     * Whether the column should give way to the floating panel. A dead band
     * of NARROW_DEAD_BAND px above the threshold keeps an area dragged
     * around 640 px (the 14.3 tree's splitter, the module menu) from moving
     * the panel back and forth: it undocks below 640 px and docks again
     * only from 640 px plus the band.
     */
    _wantNarrow() {
        if (this.layout !== LAYOUT.COLUMN || findColumnMount(document) === null) {
            return false;
        }
        const limit = 2 * COLUMN_MIN_WIDTH + (this.narrow ? NARROW_DEAD_BAND : 0);

        return this._areaWidthNow() < limit;
    }

    /** Whether the panel's parent already fits the drawing for `narrow`. */
    _placedFor(narrow) {
        const mount = this.layout === LAYOUT.COLUMN && !this._pipWindow ? findColumnMount(document) : null;
        if (!mount || !this.isConnected) {
            return true;
        }

        return narrow ? this.parentElement !== mount.element : this.parentElement === mount.element;
    }

    willUpdate(changed) {
        // On every update: a change held back while a request was in flight
        // is taken with the update that follows its end.
        const narrow = this._wantNarrow();
        // Drawing and parent change together: a panel that may not move now
        // keeps the drawing that fits where it is.
        if (narrow !== this.narrow && (this._canMove() || this._placedFor(narrow))) {
            // Crossing the threshold swaps the template, and maybe the
            // parent; focus inside the panel would fall to the body.
            this._refocus = this._focusKey();
            this.narrow = narrow;
        }
        // Maximized while floating in a narrow area, then docked again:
        // the column has no maximized state.
        if (this._docked() && this.state === STATES.MAXIMIZED) {
            this.state = STATES.EXPANDED;
        }
    }

    _focusIsInside() {
        return Boolean(this.shadowRoot?.activeElement) || document.activeElement === this;
    }

    /**
     * Which control has focus, so it can get it back after a template swap or
     * a move: a selector for it, '' for focus inside without one, null when
     * focus is elsewhere.
     */
    _focusKey() {
        if (!this._focusIsInside()) {
            return null;
        }
        const active = this.shadowRoot?.activeElement;
        if (active?.dataset?.action) {
            return `[data-action="${active.dataset.action}"]`;
        }

        return active?.matches?.('.panel-input textarea') ? '.panel-input textarea' : '';
    }

    /**
     * Put focus back into the panel after its template or parent changed:
     * the control that had it where it is still there, else the input where
     * it can take it, else the first button of the header, else the
     * minimized bar's way back.
     *
     * @param {string} key from _focusKey()
     */
    _restoreFocus(key = '') {
        const kept = key ? this.renderRoot?.querySelector(key) : null;
        if (kept && !kept.disabled) {
            kept.focus();
            return;
        }
        const input = this.renderRoot?.querySelector('.panel-input textarea');
        const target = input && !input.disabled
            ? input
            : (this.renderRoot?.querySelector('.panel-header button:not([disabled])')
                ?? this.renderRoot?.querySelector('[data-action="reopen"]'));
        target?.focus();
    }

    /**
     * Keep the panel where its drawing needs it. Docked, it is a column in
     * the content area; floating in an area too narrow for the column, it
     * lives in the body like the floating panel: inside the content area it
     * would be confined to the area's stacking layer, which in 14.3 lies
     * under the backend header (and the page tree's flyout) once the area is
     * narrower than 750 px. The move waits while a request is in flight; the
     * next update after it tries again.
     */
    _placeForLayout() {
        if (this._pipWindow || this.layout !== LAYOUT.COLUMN || !this.isConnected || !this._canMove()) {
            return;
        }
        const mount = findColumnMount(document);
        if (!mount) {
            return;
        }
        const inArea = this.parentElement === mount.element;
        if (this.narrow !== inArea) {
            return;
        }
        // A move takes focus out of the document.
        if (this._refocus === null) {
            this._refocus = this._focusKey();
        }
        if (!this.narrow && !inArea) {
            mount.element.insertBefore(this, mount.router);
        } else if (this.narrow && inArea) {
            document.body.append(this);
        } else {
            return;
        }
        if (this.state !== STATES.HIDDEN) {
            this.chat.startPollingIfNeeded();
        }
        this._syncColumnMount();
    }

    /** Width of the content area the column shares with the module. */
    _areaWidthNow() {
        if (this._areaWidth > 0) {
            return this._areaWidth;
        }
        const width = findColumnMount(document)?.element.getBoundingClientRect?.().width ?? 0;

        return width > 0 ? width : window.innerWidth;
    }

    /** The width the column shows: the user's choice, kept inside the area's limits. */
    _columnWidthNow() {
        return clampColumnWidth(this._columnWidth ?? COLUMN_DEFAULT_WIDTH, this._areaWidthNow());
    }

    /**
     * Mark the content area while the column is open in it, so the module
     * router shrinks beside it (chat-column.js), and follow the area's width.
     * Hidden and minimized, the column takes no room and the mark goes.
     */
    _syncColumnMount() {
        this._observeArea();
        const mount = this._columnMount();
        const open = mount !== null && this._docked() && (this.state === STATES.EXPANDED || this.state === STATES.MAXIMIZED);

        if (this._markedMount && this._markedMount !== mount?.element) {
            this._unmarkColumnMount();
        }
        if (!mount) {
            return;
        }

        if (this._columnWidth === null) {
            this._columnWidth = readColumnWidth();
        }
        markColumnMount(mount.element, open);
        this._markedMount = open ? mount.element : null;
    }

    /**
     * Follow the content area's width in every layout: the column's limits
     * and whether it fits depend on it, and so does the floating panel's
     * offer to dock. Not from the detached window, which has no content area.
     */
    _observeArea() {
        const area = this._pipWindow || !this.isConnected ? null : findColumnMount(document)?.element ?? null;
        if (area === this._observedArea) {
            return;
        }
        this._areaObserver?.disconnect();
        this._areaObserver = null;
        this._observedArea = area;
        if (!area || typeof ResizeObserver !== 'function') {
            return;
        }
        // The limits follow the area; the user's choice is not rewritten.
        this._areaObserver = new ResizeObserver((entries) => {
            const width = entries[entries.length - 1]?.contentRect?.width ?? 0;
            if (width > 0 && width !== this._areaWidth) {
                this._areaWidth = width;
            }
        });
        this._areaObserver.observe(area);
    }

    _unmarkColumnMount() {
        if (this._markedMount) {
            markColumnMount(this._markedMount, false);
            this._markedMount = null;
        }
    }

    /**
     * Switch between the floating panel and the column. The choice is stored
     * in the backend user's settings, so it follows the user to other
     * browsers.
     *
     * Moving the element reconnects it, which re-initialises the chat
     * controller and stops its poll; the conversation, the draft and an
     * attachment stay on the controller, and the poll is restarted here.
     *
     * Asked for by something other than the user's own choice (the prompt
     * widget), with `remember: false`, the layout holds for this opening
     * only: nothing is stored, and the panel goes back to the stored layout
     * once it is closed (hide()).
     *
     * @param {'floating'|'column'} layout
     * @param {{remember?: boolean}} [options]
     * @return {boolean} whether the layout is now the one asked for
     */
    setLayout(layout, {remember = true} = {}) {
        if (this._pipWindow || !this._canMove()) {
            return false;
        }

        if (layout === LAYOUT.COLUMN) {
            const mount = findColumnMount(document);
            if (!mount) {
                return false;
            }
            // Measured now, not from the last observation: there is no column
            // where the area is too narrow for one, and nothing is stored.
            const width = mount.element.getBoundingClientRect().width || this._areaWidthNow();
            if (width < 2 * COLUMN_MIN_WIDTH) {
                return false;
            }
            this.layout = LAYOUT.COLUMN;
            // No maximized state in the column, and asking for the column
            // from the collapsed panel means opening it, not the corner bar.
            if (this.state === STATES.MAXIMIZED || this.state === STATES.COLLAPSED) {
                this.state = STATES.EXPANDED;
            }
            if (this.parentElement !== mount.element || this.nextElementSibling !== mount.router) {
                mount.element.insertBefore(this, mount.router);
            }
        } else {
            this.layout = LAYOUT.FLOATING;
            if (this.parentElement !== document.body) {
                document.body.append(this);
            }
        }

        this._recordLayout(remember);
        this._syncColumnMount();
        this._saveState();
        if (this.state !== STATES.HIDDEN) {
            this.chat.startPollingIfNeeded();
        }

        return true;
    }

    /**
     * Store the layout as the user's choice, or note that it holds for this
     * opening only (setLayout()).
     *
     * @param {boolean} remember
     */
    _recordLayout(remember) {
        if (!remember) {
            this._layoutForThisOpening = this.layout !== readLayoutPreference();
            return;
        }
        saveLayoutPreference(this.layout);
        this._layoutForThisOpening = false;
        // Focus was on the toggle, which the move took out of the document.
        void this.updateComplete.then(() => this.renderRoot?.querySelector('[data-action="layout"]')?.focus());
    }

    toggleLayout() {
        return this.setLayout(this.layout === LAYOUT.COLUMN ? LAYOUT.FLOATING : LAYOUT.COLUMN);
    }

    _isRtl() {
        return getComputedStyle(this).direction === 'rtl';
    }

    /** Arrow keys, Home and End on the splitter (WAI-ARIA Window Splitter). */
    _onSplitterKeydown(e) {
        const next = splitterKeyWidth(e.key, this._columnWidthNow(), this._areaWidthNow(), this._isRtl());
        if (next === null) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        this._columnWidth = next;
        clearTimeout(this._persistWidthTimer);
        this._persistWidthTimer = setTimeout(() => saveColumnWidth(this._columnWidth), COLUMN_PERSIST_DELAY);
    }

    /**
     * Drag the splitter. Pointer capture keeps the events coming while the
     * pointer is over the module's iframe; the width is stored once, at the
     * end, not on every move.
     */
    _onSplitterPointerDown(e) {
        if (e.button !== 0) return;
        e.preventDefault();

        const handle = e.currentTarget;
        handle.setPointerCapture?.(e.pointerId);
        handle.classList.add('dragging');
        const startX = e.clientX;
        const startWidth = this._columnWidthNow();
        const area = this._areaWidthNow();
        const direction = this._isRtl() ? -1 : 1;
        document.body.style.cursor = 'col-resize';

        const onMove = (ev) => {
            if (!ev.isPrimary) return;
            this._columnWidth = clampColumnWidth(startWidth + direction * (ev.clientX - startX), area);
        };
        const onEnd = () => {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onEnd);
            handle.removeEventListener('pointercancel', onEnd);
            handle.removeEventListener('lostpointercapture', onEnd);
            handle.classList.remove('dragging');
            document.body.style.cursor = '';
            saveColumnWidth(this._columnWidth);
        };

        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onEnd);
        handle.addEventListener('pointercancel', onEnd);
        handle.addEventListener('lostpointercapture', onEnd);
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
     * would start empty. The backend's --typo3-* tokens and its colour scheme
     * do not exist in the new document either; _dressWindow() carries them over,
     * or the --nr-chat-* properties would fall back to their light literals.
     *
     * The styles do NOT travel with the element, which this comment used to
     * claim. Lit applies `static styles` through `adoptedStyleSheets`, and a
     * constructed stylesheet belongs to the document that made it — so after the
     * move the shadow root holds sheets the new document will not apply, and the
     * panel renders as bare serif HTML. They are rebuilt for the target document
     * instead, on the way out and on the way back.
     *
     * @return {Promise<boolean>} whether the panel is now detached
     */
    async popOut() {
        if (!this._canPopOut() || this._pipWindow || !this._canMove()) {
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
        // Detached, the panel is a window of its own, not a column; the
        // stored preference stays, and the column returns with the panel.
        this._layoutBeforePopOut = this.layout;
        this.layout = LAYOUT.FLOATING;

        pipWindow.addEventListener('pagehide', () => this._returnFromPopOut());
        // Clicks in the detached window never reach the backend document, where
        // the outside-click handler of the menus is registered.
        pipWindow.document.addEventListener('click', this._closeAttachMenu);
        pipWindow.document.body.append(this);
        this._adoptStylesInto(pipWindow);
        this._dressWindow(pipWindow);
        this._watchBackendScheme();
        this._applySize();
        // The move stopped the poll; a turn still running must keep
        // reaching the detached window.
        if (this.state !== STATES.HIDDEN) {
            this.chat.startPollingIfNeeded();
        }

        return true;
    }

    /**
     * Rebuild the shadow root's stylesheets for a given document.
     *
     * `adoptedStyleSheets` only accepts sheets constructed by the same document
     * as the node, so moving the host between documents silently drops them.
     * The rules are re-created from the same `static styles` Lit used, which
     * keeps one source for them.
     *
     * Guarded end to end: a window without constructible stylesheets, or a rule
     * the target rejects, must leave the panel where it is rather than take the
     * conversation down with it.
     *
     * @param {Window} targetWindow
     */
    _adoptStylesInto(targetWindow) {
        const root = this.shadowRoot;
        if (!root || typeof targetWindow?.CSSStyleSheet !== 'function') {
            return;
        }

        const sheets = [];
        for (const style of (this.constructor.styles ?? []).flat(Infinity)) {
            const text = style?.cssText;
            if (typeof text !== 'string') {
                continue;
            }

            try {
                const sheet = new targetWindow.CSSStyleSheet();
                sheet.replaceSync(text);
                sheets.push(sheet);
            } catch {
                // One unusable rule set must not cost the others.
            }
        }

        if (sheets.length > 0) {
            root.adoptedStyleSheets = sheets;
        }
    }

    /**
     * Give the detached window the little it needs around the panel.
     *
     * A picture-in-picture document starts empty: no reset, no background. The
     * panel is `position: fixed` and sized to fill, so the default body margin
     * shows as a white frame, and until it has painted the window is the
     * browser's blank white whatever theme the backend is in.
     *
     * The --typo3-* tokens the panel's styles reference are declared on the
     * BACKEND document's root, so a var() reference in the new document could
     * only ever produce its fallback — the light literals in theme.js. They are
     * copied onto the new root as the backend computes them. A computed custom
     * property still holds `light-dark(…)` unresolved; it picks its branch
     * where it is used, from that document's `color-scheme`. So the backend
     * root's computed `color-scheme` is copied too: core derives it from the
     * user's TYPO3 setting (`data-color-scheme`), which makes the detached
     * window follow that setting rather than the operating system.
     *
     * Nothing is set for a token that does not resolve — a literal here would
     * be the hardcoded colour the colour-scheme guard forbids.
     *
     * Called again whenever the backend switches scheme or theme while the
     * window is open (see _watchBackendScheme()); it then rewrites the one
     * style element it created instead of adding another.
     *
     * @param {Window} targetWindow
     */
    _dressWindow(targetWindow) {
        const doc = targetWindow?.document;
        if (!doc?.head) {
            return;
        }

        const backendRoot = getComputedStyle(document.documentElement);
        const declarations = [];
        const scheme = backendRoot.getPropertyValue('color-scheme').trim();
        if (scheme !== '') {
            declarations.push(`color-scheme:${scheme};`);
        }
        for (const name of this._backendTokenNames()) {
            const value = backendRoot.getPropertyValue(name).trim();
            if (value !== '') {
                declarations.push(`${name}:${value};`);
            }
        }

        let style = this._dressStyle;
        if (style?.ownerDocument !== doc) {
            style = doc.createElement('style');
            doc.head.append(style);
            this._dressStyle = style;
        }
        style.textContent = 'html,body{margin:0;padding:0;height:100%;overflow:hidden;}'
            + (declarations.length > 0 ? `:root{${declarations.join('')}}` : '');

        const background = backendRoot.getPropertyValue('--typo3-component-bg').trim();
        if (background !== '') {
            doc.documentElement.style.background = background;
        } else {
            // A re-dress after a switch must not keep the previous theme's colour.
            doc.documentElement.style.background = '';
        }
    }

    /**
     * Every --typo3-* token the panel's own styles reference, read from the
     * same `static styles` _adoptStylesInto() rebuilds — so a token added to a
     * stylesheet later travels with the panel without a list to maintain.
     *
     * @return {Set<string>}
     */
    _backendTokenNames() {
        const names = new Set(['--typo3-component-bg']);
        for (const style of (this.constructor.styles ?? []).flat(Infinity)) {
            const text = style?.cssText;
            if (typeof text !== 'string') {
                continue;
            }
            for (const [name] of text.matchAll(/--typo3-[a-z0-9-]+/g)) {
                names.add(name);
            }
        }

        return names;
    }

    /**
     * Keep the detached window in step with the backend's scheme and theme.
     *
     * Core switches both live, without a reload: on `typo3:color-scheme:update`
     * and `typo3:theme:update` it sets `data-color-scheme` / `data-theme` on
     * the backend root. Observing those two attributes catches the change after
     * it has been applied, whichever event caused it, so the values
     * _dressWindow() reads are already the new ones.
     */
    _watchBackendScheme() {
        this._schemeObserver?.disconnect();
        if (typeof MutationObserver !== 'function') {
            return;
        }
        this._schemeObserver = new MutationObserver(() => {
            if (this._pipWindow) {
                this._dressWindow(this._pipWindow);
            }
        });
        this._schemeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-color-scheme', 'data-theme'],
        });
    }

    /** Put the panel back where it came from when its window goes away. */
    _returnFromPopOut() {
        const home = this._pipHome;
        this._pipWindow?.document?.removeEventListener('click', this._closeAttachMenu);
        this._schemeObserver?.disconnect();
        this._schemeObserver = null;
        this._dressStyle = null;
        this._pipWindow = null;
        this._pipHome = null;
        if (this._layoutBeforePopOut) {
            this.layout = this._layoutBeforePopOut;
            this._layoutBeforePopOut = null;
        }

        if (home) {
            // Back into the column's place, before the module router, not
            // after it.
            const mount = findColumnMount(document);
            if (this.layout === LAYOUT.COLUMN && mount?.element === home) {
                home.insertBefore(this, mount.router);
            } else {
                home.append(this);
            }
            this._adoptStylesInto(window);
        }

        // _applySize() only runs on a reactive property change, and returning
        // home is not one — without this the panel would keep the 100% sizing
        // it wore inside the detached window.
        this._applySize();
        if (this.state !== STATES.HIDDEN) {
            this.chat.startPollingIfNeeded();
        }
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
            this._backToChosenLayout();
            this._closeAltText();
        }
        this._saveState();
    }

    collapse() {
        this.state = STATES.COLLAPSED;
        this._saveState();
        // The column's minimize button is gone with the column; keyboard
        // focus moves to the bar's way back instead of falling to the body.
        if (this._docked()) {
            void this.updateComplete.then(() => this.renderRoot?.querySelector('[data-action="reopen"]')?.focus());
        }
    }

    hide() {
        this._lastVisibleState = this.state !== STATES.HIDDEN ? this.state : STATES.EXPANDED;
        this.state = STATES.HIDDEN;
        this.chat.stopPolling();
        this._backToChosenLayout();
        this._saveState();
        this._closeAltText();
    }

    // ── The list of missing alt texts (ADR-026) ─────────────────────────

    /**
     * The image the open chat works on, for the list beside it; 0 when the
     * chat is closed or on no image.
     */
    get activeAltTextReference() {
        return this.state === STATES.HIDDEN ? 0 : (this.chat.altText?.reference ?? 0);
    }

    /**
     * "Optimieren" in the list of missing alt texts: the chat opens as the
     * column beside the list, for this opening only, the module menu folds,
     * and the chat works on the image: in the open conversation about an
     * image, which moves to it ("Treffer gewechselt"), or in a new one. The
     * keyboard focus goes to the image's card.
     *
     * The list learns what happens through the event `nr-mcp-agent:alt-text`
     * on this element (detail `{version: 1, type, reference, tab?, page?}`):
     * `active` when the chat is on an image (reference 0: on none), `done`
     * when it is fixed, `gone` when it was fixed before the chat got to it,
     * `closed` when the chat closes.
     *
     * @param {number} reference the image's sys_file_reference uid
     * @returns {Promise<{ok: boolean, code: string, error: string}>}
     */
    async optimiseAltText(reference) {
        if (this._starting) {
            return {ok: false, code: 'busy', error: lll('chat.stillSending')};
        }
        this._starting = true;
        try {
            await this.chat.ready;
            if (!this.chat.available) {
                return {ok: false, code: 'unavailable', error: lll('chat.notAvailable')};
            }
            if (this.layout !== LAYOUT.COLUMN) {
                this.setLayout(LAYOUT.COLUMN, {remember: false});
            }
            if (this.state === STATES.HIDDEN || this.state === STATES.COLLAPSED) {
                this.expand();
            }
            if (setModuleMenuOpen(globalThis.document, false)) {
                this._foldedModuleMenu = true;
                saveFoldedModuleMenu(true);
            }
            await this.chat.ready;
            await this.updateComplete;

            // While the proposing turn runs the image cannot change under it:
            // the row is queued and taken up when the turn ends.
            if (this.chat.altText?.reference && this.chat.isProcessing()) {
                this._queuedAltText = reference;
                return {ok: true, code: 'queued', error: ''};
            }

            this._queuedAltText = 0;
            const result = this.chat.altText?.reference
                ? await this.chat.switchAltText(reference)
                : await this.chat.startAltText(reference);
            if (result.ok) {
                this._emitAltText('active', {reference, tab: result.tab, page: result.page});
                this._focusAltTextCard();
            } else if (result.code === 'done') {
                this._emitAltText('gone', {reference});
            }
            if (!result.ok && !this.chat.activeUid) {
                // No conversation is open, so its status line is not
                // rendered: the reason goes into the banner.
                this.chat.issues = [...this.chat.issues.filter((issue) => issue !== result.error), result.error];
                this.requestUpdate();
            }
            return result;
        } finally {
            this._starting = false;
        }
    }

    /** The controller's state changed: a fixed image, or another one. */
    onAltTextChange(previous, state) {
        if (previous?.reference && previous.reference === state?.reference && previous.open && !state.open) {
            this._emitAltText('done', {reference: state.reference});
            return;
        }
        if (previous?.reference !== state?.reference && this.state !== STATES.HIDDEN) {
            this._emitAltText('active', {reference: state?.reference ?? 0});
        }
    }

    /** The turn on the image has ended: take up a row queued meanwhile. */
    onAltTextIdle() {
        const reference = this._queuedAltText;
        if (!reference || this._starting) {
            return;
        }

        if (reference !== this.chat.altText?.reference) {
            this._queuedAltText = 0;
            void this.optimiseAltText(reference);
        } else {
            this._queuedAltText = 0;
        }
    }

    /** "Nächsten Treffer optimieren" moved the chat to another image. */
    onAltTextTakenUp(result) {
        this._emitAltText('active', {reference: result.reference, tab: result.tab, page: result.page});
        this._focusAltTextCard();
    }

    _closeAltText() {
        // A row queued while the proposing turn ran is dropped with the
        // chat: reopening it must not take that row up unasked.
        this._queuedAltText = 0;
        const reference = this.chat.altText?.reference ?? 0;
        if (reference) {
            this._emitAltText('closed', {reference});
        }
        if (this._foldedModuleMenu) {
            this._foldedModuleMenu = false;
            saveFoldedModuleMenu(false);
            setModuleMenuOpen(globalThis.document, true);
        }
    }

    _emitAltText(type, detail) {
        this.dispatchEvent(new CustomEvent('nr-mcp-agent:alt-text', {detail: {version: 1, type, ...detail}}));
    }

    /** Focus the heading of the card of the image the chat is on. */
    _focusAltTextCard() {
        void this.updateComplete.then(() => {
            const cards = this.renderRoot?.querySelectorAll('[data-alt-text-current]') ?? [];
            cards[cards.length - 1]?.focus();
        });
    }

    /**
     * A layout taken for one opening only (setLayout() with `remember:
     * false`) ends when the chat is closed: the next opening finds the
     * layout the user chose. A move refused now is tried at the next close.
     */
    _backToChosenLayout() {
        if (this._layoutForThisOpening && this.setLayout(readLayoutPreference(), {remember: false})) {
            this._layoutForThisOpening = false;
        }
    }

    /**
     * Open the chat again from the minimized bar. Focus goes back into the
     * column: the input where a conversation is open, else the minimize
     * button that leads back to the bar.
     */
    expand() {
        this.state = STATES.EXPANDED;
        this.chat.startPollingIfNeeded();
        this._saveState();
        void this.updateComplete.then(() => {
            const input = this.renderRoot?.querySelector('.panel-input textarea');
            const target = input && !input.disabled ? input : this.renderRoot?.querySelector('[data-action="minimize"]');
            target?.focus();
        });
    }

    maximize() {
        // The column is as high as the backend already; its width is the splitter's.
        if (this._docked()) return;
        this.state = this.state === STATES.MAXIMIZED ? STATES.EXPANDED : STATES.MAXIMIZED;
        this._saveState();
    }

    /**
     * Open the chat on a new conversation whose first message is `text`: the
     * question asked in the prompt widget on the dashboard. The chat opens as
     * a column where the backend has room for one (ADR-024), for this opening
     * only: the layout the user chose is not changed, and the chat goes back
     * to it when it is closed. Where the user popped the chat out into its
     * own window, it stays there. The text is sent as a message typed into
     * the chat is (ChatCoreController::handleSend()).
     *
     * What would refuse the message is checked before a conversation is
     * created: another message still being sent, a text over the configured
     * maximum, a question from another widget still starting. A send that
     * fails after the conversation was created leaves it open and empty, and
     * the next question uses it instead of creating another, without an
     * attachment picked for it in the meantime.
     *
     * Docking reconnects the panel, and a reconnect aborts the controller's
     * requests in flight. So the panel is placed first, and the conversation
     * is created and the message sent from the connection it ends up with.
     *
     * @param {string} text
     * @returns {Promise<{sent: boolean, error: string, invalid?: true}>}
     *     whether the message was sent, and if not, why, in the user's
     *     language ('' when the chat shows the reason itself); `invalid` when
     *     the question itself is what the chat refuses, not the moment or
     *     the chat's state
     */
    async startConversation(text) {
        const content = String(text ?? '').trim();
        if (content === '') {
            return {sent: false, error: ''};
        }
        // A question from a second widget while the first one starts.
        if (this._starting) {
            return {sent: false, error: lll('chat.stillSending')};
        }
        this._starting = true;
        try {
            await this.chat.ready;
            if (!this.chat.available) {
                return {sent: false, error: lll('chat.notAvailable')};
            }
            const refusal = this.chat.refusalFor(content);
            if (refusal !== '') {
                // refusalFor() names a message still being sent first; any
                // other refusal is about the question itself (too long).
                return this.chat.sending ? {sent: false, error: refusal} : {sent: false, error: refusal, invalid: true};
            }

            if (this.layout !== LAYOUT.COLUMN) {
                // Refused in an area too narrow for the column, in its own
                // window, or while a request runs: the chat stays where it is.
                this.setLayout(LAYOUT.COLUMN, {remember: false});
            }
            if (this.state === STATES.HIDDEN || this.state === STATES.COLLAPSED) {
                this.expand();
            }
            // The status and the conversations, read by the connection the
            // move started.
            await this.chat.ready;
            await this.updateComplete;

            if (this._reusableStart()) {
                // As a new conversation would: an attachment picked for it
                // does not go with a question asked elsewhere.
                this.chat.clearPendingFile();
            } else {
                this._startedUid = await this.chat.handleNewConversation();
                if (!this._startedUid) {
                    return {sent: false, error: this.chat.errorMessage};
                }
            }

            const sent = await this.chat.handleSend(content);

            return {sent, error: sent ? '' : this.chat.errorMessage};
        } finally {
            this._starting = false;
        }
    }

    /**
     * Whether the conversation the last question created is open and still
     * empty: its message was refused, and it takes the next question.
     */
    _reusableStart() {
        return this._startedUid !== null
            && this.chat.activeUid === this._startedUid
            && this.chat.messages.length === 0;
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
        if (!this._docked() && !e.target.closest('button, .btn-icon')) {
            this.maximize();
        }
    }

    _onDragStart(e) {
        if (e.button !== 0) return; // left button only
        if (e.target.closest('button, .btn-icon')) return;
        if (this.state === STATES.COLLAPSED) return;
        // The column stays where it is docked.
        if (this._docked()) return;
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
            // The column sits beside the page tree and its filter: Escape
            // there belongs to them, not to the chat.
            if (this._docked() && !inPanel) {
                return;
            }
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

    // ── Render ──────────────────────────────────────────────────────────

    render() {
        if (this.state === STATES.HIDDEN) return nothing;

        if (this._docked()) {
            if (this.state === STATES.COLLAPSED) {
                return this._renderMinimizedBar();
            }

            return html`
                ${this._renderColumnHeader()}
                ${this._renderBody()}
                ${this._renderSplitter()}
            `;
        }

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
        const title = conv ? conversationTitle(conv, lll('panel.title')) : lll('panel.title');

        return html`
            <div class="panel-header"
                 @pointerdown=${(e) => this._onDragStart(e)}
                 @click=${(e) => this._onHeaderClick(e)}
                 @dblclick=${(e) => this._onHeaderDblClick(e)}>
                <span class="title">${title}</span>
                ${renderProgress(this.chat)}
                ${renderEndTour(this.chat)}
                ${renderHighlightAnnouncement(this.chat)}
                ${this.chat.status ? html`
                    <span class="status-badge status-${statusTone(this.chat.status)}" title="${statusLabel(this.chat.status)}">${renderStatusIcon(this.chat.status)}</span>
                ` : nothing}
                <button class="btn-icon" data-action="minimize" @click=${() => this.collapse()}
                        title="${lll('panel.collapse')}" aria-label="${lll('panel.collapse')}">${ICON_MINIMIZE(14)}</button>
                <button class="btn-icon" @click=${() => this.maximize()}
                        title="${this.state === STATES.MAXIMIZED ? lll('panel.restore') : lll('panel.maximize')}"
                        aria-label="${this.state === STATES.MAXIMIZED ? lll('panel.restore') : lll('panel.maximize')}">
                    ${this.state === STATES.MAXIMIZED ? ICON_RESTORE(14) : ICON_MAXIMIZE(14)}
                </button>
                ${this._canPopOut() ? html`
                    <button class="btn-icon" data-action="popout" ?disabled=${!this._canMove()} @click=${() => this.popOut()}
                            title="${lll('panel.popOut')}" aria-label="${lll('panel.popOut')}">${ICON_POPOUT(14)}</button>
                ` : nothing}
                ${this._canDock() ? html`
                    <button class="btn-icon" data-action="layout" aria-pressed="false" ?disabled=${!this._canMove()} @click=${() => this.toggleLayout()}
                            title="${lll('panel.columnMode')}" aria-label="${lll('panel.columnMode')}">${ICON_COLUMN(14)}</button>
                ` : nothing}
                <button class="btn-icon" data-action="close" @click=${() => this.hide()}
                        title="${lll('panel.close')}" aria-label="${lll('panel.close')}">${ICON_CLOSE(14)}</button>
            </div>
        `;
    }

    /**
     * The column's header (ADR-024): the conversation's title with a status
     * line below it — the guided process's page and step, else the status —
     * and the buttons to detach, leave the column, minimize and close.
     */
    _renderColumnHeader() {
        const conv = this.chat.getActiveConversation();
        const title = conv ? conversationTitle(conv, lll('panel.title')) : lll('panel.title');

        return html`
            <div class="panel-header">
                <span class="column-badge" aria-hidden="true">${lll('panel.badge')}</span>
                <div class="column-heading">
                    <span class="title">${title}</span>
                    <span class="column-status">${this._renderStatusLine()}</span>
                </div>
                ${renderEndTour(this.chat)}
                ${renderHighlightAnnouncement(this.chat)}
                ${this._canPopOut() ? html`
                    <button class="btn-icon layout-btn" data-action="popout" ?disabled=${!this._canMove()} @click=${() => this.popOut()}
                            title="${lll('panel.popOut')}" aria-label="${lll('panel.popOut')}">${ICON_POPOUT(16)}</button>
                ` : nothing}
                <button class="btn-icon layout-btn" data-action="layout" aria-pressed="true" ?disabled=${!this._canMove()} @click=${() => this.toggleLayout()}
                        title="${lll('panel.columnMode')}" aria-label="${lll('panel.columnMode')}">${ICON_COLUMN(16)}</button>
                <button class="btn-icon layout-btn" data-action="minimize" @click=${() => this.collapse()}
                        title="${lll('panel.collapse')}" aria-label="${lll('panel.collapse')}">${ICON_MINIMIZE(16)}</button>
                <button class="btn-icon layout-btn" data-action="close" @click=${() => this.hide()}
                        title="${lll('panel.close')}" aria-label="${lll('panel.close')}">${ICON_CLOSE(16)}</button>
            </div>
        `;
    }

    /**
     * The guided process's page and step where one runs, else the
     * conversation's status. The progress is a live region of its own
     * (renderProgress); `live` makes the plain status one too, for the
     * minimized bar, where the column's other announcements are not on screen.
     *
     * @param {boolean} [live]
     */
    _renderStatusLine(live = false) {
        const progress = renderProgress(this.chat);
        if (progress !== nothing) {
            return progress;
        }
        if (!this.chat.status) {
            return nothing;
        }

        return live ? html`<span role="status">${statusLabel(this.chat.status)}</span>` : statusLabel(this.chat.status);
    }

    /**
     * The column minimized: a bar in the bottom right corner with the
     * conversation, its state — "waiting for your approval" when a change
     * waits — and the way back.
     */
    _renderMinimizedBar() {
        const conv = this.chat.getActiveConversation();
        const title = conv ? conversationTitle(conv, lll('panel.title')) : lll('panel.title');
        const waiting = statusTone(this.chat.status) === 'warning';

        return html`
            <div class="minimized-bar">
                <span class="column-badge" aria-hidden="true">${lll('panel.badge')}</span>
                <div class="minimized-text" title="${conv ? conversationTooltip(conv, statusLabel(this.chat.status), lll('panel.title'), conversationTime(conv, this.chat.listLocale)?.absolute ?? '') : nothing}">
                    <span class="minimized-title">${title}</span>
                    <span class="minimized-status">${this._renderStatusLine(true)}</span>
                </div>
                ${waiting ? html`<span class="status-dot" aria-hidden="true"></span>` : nothing}
                <button class="btn-icon layout-btn" data-action="reopen" @click=${() => this.expand()}
                        title="${lll('panel.reopen')}" aria-label="${lll('panel.reopen')}">${ICON_COLUMN(16)}</button>
                <button class="btn-icon layout-btn" data-action="close" @click=${() => this.hide()}
                        title="${lll('panel.close')}" aria-label="${lll('panel.close')}">${ICON_CLOSE(16)}</button>
            </div>
        `;
    }

    /**
     * The splitter on the column's trailing edge, after the WAI-ARIA Window
     * Splitter pattern: focusable, its value the column's width in px, the
     * arrow keys step, Home and End jump to the limits.
     */
    _renderSplitter() {
        return html`
            <div class="column-splitter"
                 role="separator"
                 aria-orientation="vertical"
                 aria-controls="${COLUMN_BODY_ID}"
                 aria-label="${lll('panel.columnResize')}"
                 aria-valuenow="${this._columnWidthNow()}"
                 aria-valuemin="${COLUMN_MIN_WIDTH}"
                 aria-valuemax="${columnMaxWidth(this._areaWidthNow())}"
                 tabindex="0"
                 @pointerdown=${(e) => this._onSplitterPointerDown(e)}
                 @keydown=${(e) => this._onSplitterKeydown(e)}></div>
        `;
    }

    _renderBody() {
        if (this.chat.loading) {
            return html`<div class="panel-body" id="${COLUMN_BODY_ID}"><div class="empty-state"><span class="spinner"></span></div></div>`;
        }

        return html`
            ${this.chat.issues.length > 0 ? html`
                <div class="issues-banner">
                    ${this.chat.issues.map(i => html`<div>${i}</div>`)}
                </div>
            ` : nothing}
            <div class="panel-body" id="${COLUMN_BODY_ID}">
                ${this.state === STATES.MAXIMIZED ? this._renderSidebar() : nothing}
                <div class="panel-content">
                    ${this.state === STATES.EXPANDED ? this._renderConvTabs() : nothing}
                    ${this.state === STATES.EXPANDED ? renderActivity(this.chat, 'strip') : nothing}
                    ${this._renderChat()}
                </div>
                ${this.state === STATES.MAXIMIZED ? renderActivity(this.chat, 'sidebar') : nothing}
            </div>
        `;
    }

    /** The conversations the lists show: with a message, or open (ADR-027). */
    _listed() {
        return listedConversations(this.chat.conversations, this.chat.activeUid);
    }

    _renderSidebar() {
        return html`
            <div class="panel-sidebar">
                <div class="panel-sidebar-header">
                    <h2>${lll('conversations.title')}</h2>
                    <button class="btn-icon"
                            @click=${() => this.chat.handleNewConversation()}
                            ?disabled=${!this.chat.available}
                            title="${lll('conversations.new')}"
                            aria-label="${lll('conversations.new')}">${ICON_COMPOSE(14)}</button>
                </div>
                <div class="sidebar-list" role="listbox" aria-label="${lll('conversations.title')}">
                    ${this._listed().length === 0
                        ? html`<div class="empty-state" style="font-size:12px;">${lll('conversations.empty')}</div>`
                        : this._listed().map(c => this._renderSidebarItem(c))
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
                ${renderConversationSummary(c, {locale: this.chat.listLocale})}
                ${isActive ? html`
                    <span class="sidebar-item-actions">
                        <button class="btn-icon btn-sm" @click=${(e) => { e.stopPropagation(); this.chat.handleTogglePin(); }}
                                title="${c.pinned ? lll('conversations.unpin') : lll('conversations.pin')}"
                                aria-label="${c.pinned ? lll('conversations.unpin') : lll('conversations.pin')}">
                            ${'\u{1F4CC}'}
                        </button>
                        <button class="btn-icon btn-sm" data-action="activity"
                                aria-pressed="${String(this.chat.activityOpen)}"
                                @click=${(e) => { e.stopPropagation(); this.chat.toggleActivity(); }}
                                title="${lll('activity.button')}" aria-label="${lll('activity.button')}">
                            ${ICON_ACTIVITY(12)}
                        </button>
                        <button class="btn-icon btn-sm ${this.chat.systemPrompt ? 'has-instructions' : ''}" data-action="instructions"
                                @click=${(e) => { e.stopPropagation(); this.chat.toggleSystemPrompt(); }}
                                title="${instructionsLabel(this.chat)}" aria-label="${instructionsLabel(this.chat)}">
                            ${ICON_INSTRUCTIONS(12)}
                        </button>
                        <button class="btn-icon btn-sm" data-action="export" ?disabled=${!this.chat.canExport()} @click=${(e) => { e.stopPropagation(); this._exportConversation(); }}
                                title="${lll('conversations.export')}" aria-label="${lll('conversations.export')}">
                            ${ICON_DOWNLOAD(12)}
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
        const {visible, overflow} = splitConversationTabs(this.chat.conversations, this.chat.activeUid);
        return html`
            <div class="conv-tabs-wrap">
                <div class="conv-tabs">
                    <div class="conv-tablist" role="tablist" aria-label="${lll('conversations.title')}">
                        ${visible.map(c => this._renderConvTab(c))}
                    </div>
                    ${this._renderConvTabDescriptions(visible)}
                    ${overflow.length > 0 ? html`
                        <button class="conv-tab conv-tab-more"
                                aria-haspopup="listbox"
                                aria-expanded="${String(this._moreOpen)}"
                                aria-controls="${this._moreOpen ? 'conv-more-list' : nothing}"
                                title="${lll('conversations.moreTitle')}"
                                @click=${(e) => { e.stopPropagation(); this._toggleMore(); }}>
                            ${lll('conversations.more', overflow.length)}
                        </button>
                    ` : nothing}
                    ${this.chat.activeUid ? html`
                        <button class="btn-icon conv-tab-action"
                                data-action="activity"
                                aria-pressed="${String(this.chat.activityOpen)}"
                                @click=${() => this.chat.toggleActivity()}
                                title="${lll('activity.button')}"
                                aria-label="${lll('activity.button')}">${ICON_ACTIVITY(14)}</button>
                    ` : nothing}
                    ${this.chat.activeUid ? html`
                        <button class="btn-icon conv-tab-action ${this.chat.systemPrompt ? 'has-instructions' : ''}"
                                data-action="instructions"
                                aria-pressed="${String(this.chat.systemPromptOpen)}"
                                @click=${() => this.chat.toggleSystemPrompt()}
                                title="${instructionsLabel(this.chat)}"
                                aria-label="${instructionsLabel(this.chat)}">${ICON_INSTRUCTIONS(14)}</button>
                    ` : nothing}
                    ${this.chat.activeUid ? html`
                        <button class="btn-icon conv-tab-action"
                                data-action="export"
                                ?disabled=${!this.chat.canExport()}
                                @click=${() => this._exportConversation()}
                                title="${lll('conversations.export')}"
                                aria-label="${lll('conversations.export')}">${ICON_DOWNLOAD(14)}</button>
                    ` : nothing}
                    <button class="btn-icon conv-tab-new"
                            @click=${() => this.chat.handleNewConversation()}
                            ?disabled=${!this.chat.available}
                            title="${lll('conversations.new')}"
                            aria-label="${lll('conversations.new')}">${ICON_COMPOSE(14)}</button>
                </div>
                ${this._moreOpen && overflow.length > 0 ? this._renderMorePopover(overflow) : nothing}
            </div>
        `;
    }

    /**
     * The conversations that do not fit the tab row, as a searchable list.
     *
     * ARIA combobox pattern: focus stays in the search field, the arrow keys
     * move the highlighted option (aria-activedescendant), Enter opens it,
     * Escape closes the list and returns focus to the button that opened it.
     */
    _renderMorePopover(overflow) {
        const untitled = lll('conversations.newConversation');
        const items = filterConversations(overflow, this._moreQuery, untitled);
        const index = items.length === 0 ? -1 : Math.min(Math.max(this._moreIndex, 0), items.length - 1);
        const activeId = index >= 0 ? `conv-more-option-${items[index].uid}` : null;

        return html`
            <div class="conv-more-popover" @click=${(e) => e.stopPropagation()}>
                <input type="search"
                       class="conv-more-search"
                       role="combobox"
                       aria-expanded="true"
                       aria-autocomplete="list"
                       aria-controls="conv-more-list"
                       aria-activedescendant="${activeId ?? nothing}"
                       aria-label="${lll('conversations.search')}"
                       placeholder="${lll('conversations.search')}"
                       .value=${this._moreQuery}
                       @input=${(e) => { this._moreQuery = e.target.value; this._moreIndex = 0; }}
                       @keydown=${(e) => this._onMoreKeydown(e, items, index)}>
                <ul id="conv-more-list" class="conv-more-list" role="listbox" aria-label="${lll('conversations.title')}">
                    ${items.length === 0
                        ? html`<li class="conv-more-empty" role="presentation">${lll('conversations.noMatch')}</li>`
                        : items.map((c, i) => html`
                            <li id="conv-more-option-${c.uid}"
                                class="conv-more-option ${i === index ? 'highlighted' : ''}"
                                role="option"
                                aria-selected="${i === index}"
                                @mousemove=${() => { if (this._moreIndex !== i) this._moreIndex = i; }}
                                @click=${() => this._pickMore(c.uid)}>
                                ${renderConversationSummary(c, {locale: this.chat.listLocale})}
                            </li>
                        `)}
                </ul>
            </div>
        `;
    }

    _toggleMore() {
        if (this._moreOpen) {
            this._closeMore(false);
            return;
        }
        this._moreQuery = '';
        this._moreIndex = 0;
        this._moreOpen = true;
    }

    _closeMore(returnFocus) {
        this._moreOpen = false;
        if (returnFocus) {
            this.updateComplete.then(() => this.renderRoot?.querySelector('.conv-tab-more')?.focus());
        }
    }

    _pickMore(uid) {
        this._closeMore(false);
        this.chat.selectConversation(uid);
    }

    _onMoreKeydown(e, items, index) {
        // Keys handled here must not reach the document handler: Escape there
        // collapses the whole panel.
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            e.stopPropagation();
            if (items.length === 0) return;
            const step = e.key === 'ArrowDown' ? 1 : -1;
            this._moreIndex = (index + step + items.length) % items.length;
        } else if (e.key === 'Home' || e.key === 'End') {
            e.preventDefault();
            e.stopPropagation();
            if (items.length === 0) return;
            this._moreIndex = e.key === 'Home' ? 0 : items.length - 1;
        } else if (e.key === 'Enter') {
            e.preventDefault();
            e.stopPropagation();
            if (index >= 0) this._pickMore(items[index].uid);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            this._closeMore(true);
        } else if (e.key === 'Tab') {
            this._closeMore(false);
        }
    }

    _exportConversation() {
        if (!this.chat.canExport()) return;
        const doc = this.ownerDocument || document;
        downloadTextFile(doc, this.chat.exportFileName(), this.chat.buildMarkdownExport());
    }

    /**
     * What a tab's tooltip says beyond the title, as text the tabs point to
     * with aria-describedby: outside the tab list, which may own tabs only.
     * `hidden`, not visually hidden: aria-describedby still resolves it, and
     * a screen reader's browse mode does not read it a second time.
     */
    _renderConvTabDescriptions(tabs) {
        return html`
            <div class="conv-tab-descriptions" hidden>
                ${tabs.map((c) => {
                    const time = conversationTime(c, this.chat.listLocale);
                    return html`<span id="conv-tab-desc-${c.uid}">${[c.contextLabel, time?.label].filter(Boolean).join(', ')}</span>`;
                })}
            </div>
        `;
    }

    _renderConvTab(c) {
        const isActive = c.uid === this.chat.activeUid;
        const isRenaming = this._renamingUid === c.uid;
        const untitled = lll('conversations.newConversation');
        const title = conversationTitle(c, untitled);
        const time = conversationTime(c, this.chat.listLocale);
        // Narrow: the status icon and the title; the context, the status in
        // words and the time are in the tooltip for the mouse, and in the
        // tab's description for keyboard, touch and screen readers (ADR-027).
        return html`
            <button class="conv-tab ${isActive ? 'active' : ''}"
                    role="tab"
                    aria-selected="${isActive}"
                    aria-describedby="conv-tab-desc-${c.uid}"
                    title="${conversationTooltip(c, statusLabel(c.status), untitled, time?.absolute ?? '')}"
                    @click=${() => this.chat.selectConversation(c.uid)}>
                ${renderStatusIcon(c.status)}
                ${isRenaming ? html`
                    <input class="tab-rename-input"
                           .value=${c.fullTitle || title}
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
     *
     * The hint says where earlier conversations are, and that depends on the
     * layout: the tab row above the chat when expanded, the sidebar on the
     * left when maximized. It used to say "on the left" in both (NEXT-172).
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
                <p class="empty-state-hint">${this.state === STATES.EXPANDED ? lll('chat.empty.hintTabs') : lll('chat.empty.hint')}</p>
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
            ${renderInstructionsEditor(this.chat)}
            <div class="panel-messages" aria-live="polite" aria-relevant="additions">
                ${this.chat.messages.map((msg, idx) => html`${renderOutcomeLines(this.chat, idx)}${this._renderMessage(msg, idx)}`)}
                ${renderOutcomeLines(this.chat, this.chat.messages.length)}
                ${renderSummary(this.chat)}
                ${this.chat.isProcessing() ? html`
                    <div class="message-row assistant" aria-label="${lll('chat.processing')}">
                        <div class="avatar avatar-assistant">${AVATAR_ASSISTANT(14)}</div>
                        <div class="typing-indicator" aria-hidden="true"><span></span><span></span><span></span></div>
                    </div>
                ` : nothing}
                ${this._renderStatusNotice(isResumable)}
                ${renderAltTextNext(this.chat)}
            </div>
            ${this._renderInput()}
        `;
    }

    _renderMessage(msg, idx) {
        // The chat's own lines in a conversation about a missing alt text
        // (ADR-026): the image card, the divider, the confirmations.
        const altText = renderAltTextMessage(this.chat, msg, idx);
        if (altText !== null) {
            return altText;
        }

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
            : this._renderAssistantContent(msg);

        return html`
            <div class="message-row ${role}">
                ${isUser ? nothing : html`<div class="avatar avatar-assistant">${AVATAR_ASSISTANT(14)}</div>`}
                <div class="message-bubble">
                    ${renderMessageBody(this.chat, idx, html`<div class="message ${role}">${bubbleContent}</div>${this._renderMessageNotice(msg)}`, time)}
                </div>
                ${isUser ? html`<div class="avatar avatar-user">${AVATAR_USER(14)}</div>` : nothing}
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

        // A conversation about a deleted page offers nothing to decide, retry
        // or resume: the read-only notice below says why (_renderInput()).
        if (this.chat.isReadOnly()) {
            return nothing;
        }

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

            // Not a message: the system paused the run because the backend
            // requires approval, and this frame says so (ADR-023, 2026-10-23
            // amendment). It sits outside .message and .message-row, carries
            // no avatar, and spans the column.
            return html`
                <section class="approval-frame status-notice" tabindex="-1" aria-labelledby="approval-frame-header">
                    <div class="approval-frame-header" id="approval-frame-header">
                        ${ICON_APPROVAL(14)}<span>${lll('chat.approvalHeader')}</span>
                    </div>
                    <div class="approval-frame-body">
                        <p class="approval-pending">${lll('chat.approvalPending')}${reason}</p>
                        ${this._renderApprovalCard()}
                    </div>
                </section>
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

        if (isAltTextCard(pending)) {
            return renderAltTextCard(this.chat, pending);
        }

        if (isProposal(pending)) {
            return this._renderProposalCard(pending);
        }

        return html`
            <div class="approval-card">
                ${pending.calls.map((call) => html`
                    <div class="approval-call">
                        <p class="approval-title" role="heading" aria-level="3"><strong>${call.heading || call.actionLabel || lll('chat.approvalTitleGeneric')}</strong></p>
                        ${call.toolStillRegistered ? nothing : html`
                            <p class="approval-warning approval-stale">${lll('chat.approvalToolGone')}</p>
                        `}
                        ${call.previewStale ? html`
                            <p class="approval-warning approval-stale">${lll('chat.approvalPreviewStale')}</p>
                        ` : nothing}
                        ${this._renderApprovalPreview(call)}
                        ${this.chat.showTechnicalDetails ? html`
                            <details class="approval-technical">
                                <summary>${lll('chat.approvalTechnicalDetails')}</summary>
                                ${call.technicalDetails ? html`<p>${call.technicalDetails}</p>` : nothing}
                                <p>${lll('chat.approvalTool')} <code>${call.name}</code></p>
                                <p>${lll('chat.approvalArguments')}</p>
                                <pre><code>${call.argumentsJson}</code></pre>
                            </details>
                        ` : nothing}
                    </div>
                `)}
                ${this._renderApproveBlocked(pending)}
                <div class="approval-actions">
                    ${pending.approveBlocked ? nothing : html`
                        <button class="btn btn-sm btn-answer btn-primary" ?disabled=${this.chat.approvalBusy}
                            @click=${() => this._decide(true)}>${this._approveLabel(pending)}</button>
                    `}
                    ${renderDenyButtons(pending, this.chat.approvalBusy, (reason) => this._decide(false, reason))}
                </div>
                ${this._lacksPreview(pending) || this.chat.errorPointsToRun ? this._renderRunDetailsLink() : nothing}
            </div>
        `;
    }

    /**
     * Why the card offers no approve button, when it does not (ADR-021): the
     * configuration requires a second person, or the reader decides by the
     * chat's own permission and a change has no preview built with their
     * rights. The way to deny stays.
     */
    _renderApproveBlocked(pending) {
        if (!pending.approveBlocked) {
            return nothing;
        }

        return html`
            <p class="approval-warning approval-blocked">${lll(approveBlockedLabel(pending.approveBlocked))}</p>
            ${this._renderRunDetailsLink()}
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
                ${this._renderApproveBlocked(pending)}
                <div class="approval-actions">
                    ${pending.approveBlocked ? nothing : html`
                        <button class="btn btn-sm btn-answer btn-primary" ?disabled=${this.chat.approvalBusy}
                            @click=${() => this._decide(true)}>${lll('chat.approvalApply')}</button>
                    `}
                    ${renderDenyButtons(pending, this.chat.approvalBusy, (reason) => this._decide(false, reason))}
                </div>
                ${this.chat.showTechnicalDetails ? html`
                    <details class="approval-technical">
                        <summary>${lll('chat.approvalTechnicalDetails')}</summary>
                        ${call.technicalDetails ? html`<p>${call.technicalDetails}</p>` : nothing}
                        <p>${lll('chat.approvalTool')} <code>${call.name}</code></p>
                        <p>${lll('chat.approvalArguments')}</p>
                        <pre><code>${call.argumentsJson}</code></pre>
                    </details>
                ` : nothing}
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
        // A conversation about a deleted page is read, not continued: the
        // notice takes the place of the input (the server refuses a send too).
        if (this.chat.isReadOnly()) {
            return renderReadOnlyNotice(this.chat, 'panel-input');
        }
        return html`
            ${renderReplyOptions(this.chat)}
            ${renderPageChange(this.chat)}
            ${renderPageChoice(this.chat)}
            ${renderRoundLimit(this.chat)}
            ${renderTourEnd(this.chat)}
            ${renderActiveSkill(this.chat)}
            ${renderContextChips(this.chat, {win: this.ownerDocument?.defaultView ?? globalThis})}
            ${renderSlashList(this.chat)}
            ${this._renderFileBadge()}
            <div class="panel-input">
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
