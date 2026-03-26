import {ApiClient} from './api-client.js';
import {lll} from '@typo3/core/lit-helper.js';
import {renderMarkdown} from './markdown.js';

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
    /** @type {{fileUid: number, name: string, mimeType: string}|null} */
    pendingFile = null;
    visionSupported = false;
    maxFileSize = 0;
    /** @type {string[]} */
    supportedFormats = [];

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
    /** @type {number|null} */
    _falPickerPollTimer = null;

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
            await this._api.sendMessage(this.activeUid, content, fileUid);
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
        if (!confirm(lll('conversations.archiveConfirm'))) return;
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

        // TYPO3 element browser sends {actionName:'typo3:elementBrowser:elementAdded', fieldName, value, label}
        // via postMessage to window.opener (our window). value = sys_file UID, either as a plain numeric
        // string ("42") or in table_uid format ("sys_file_42") depending on the TYPO3 file browser version.
        this._falPickerListener = (event) => {
            if (event.data?.actionName !== 'typo3:elementBrowser:elementAdded') return;
            if (event.data?.fieldName !== fieldName) return;
            this._cleanupFalPicker();
            // Extract the trailing integer — handles both "42" and "sys_file_42"
            const match = String(event.data.value ?? '').match(/(\d+)$/);
            const uid = match ? parseInt(match[1], 10) : 0;
            if (uid > 0) {
                this._onFalFileSelected(uid);
            }
        };
        globalThis.addEventListener('message', this._falPickerListener);

        const popup = globalThis.open(url, 'typo3FileBrowser', 'height=600,width=900,status=0,menubar=0,scrollbars=1');
        if (!popup) {
            this._cleanupFalPicker();
            this._setError(lll('fal_picker_popup_blocked'));
            return;
        }

        // Poll for popup being closed by the user without selecting a file.
        // Without this, _falPickerListener would stay set and block reopening the picker.
        this._falPickerPollTimer = setInterval(() => {
            if (popup.closed) {
                this._cleanupFalPicker();
            }
        }, 500);
    }

    _cleanupFalPicker() {
        if (this._falPickerPollTimer) {
            clearInterval(this._falPickerPollTimer);
            this._falPickerPollTimer = null;
        }
        if (this._falPickerListener) {
            globalThis.removeEventListener('message', this._falPickerListener);
            this._falPickerListener = null;
            this.host.requestUpdate();
        }
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
