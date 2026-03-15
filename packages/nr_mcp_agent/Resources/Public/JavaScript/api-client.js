/**
 * Thin wrapper around the TYPO3 AJAX routes for ai-chat.
 * All methods return parsed JSON or throw on HTTP errors.
 */
export class ApiClient {
    constructor() {}

    /** @returns {Promise<{available: boolean, mcpEnabled: boolean, issues: string[]}>} */
    async getStatus() {
        return this._get('ai_chat_status');
    }

    /** @returns {Promise<{conversations: Array}>} */
    async listConversations() {
        return this._get('ai_chat_conversations');
    }

    /** @returns {Promise<{uid: number}>} */
    async createConversation() {
        return this._post('ai_chat_conversation_create', {});
    }

    /**
     * @param {number} conversationUid
     * @param {number} after – message index offset
     * @returns {Promise<{status: string, messages: Array, totalCount: number, errorMessage: string}>}
     */
    async getMessages(conversationUid, after = 0) {
        return this._get('ai_chat_conversation_messages', {conversationUid, after});
    }

    /**
     * @param {number} conversationUid
     * @param {string} content
     * @returns {Promise<{status: string}>}
     */
    async sendMessage(conversationUid, content) {
        return this._post('ai_chat_conversation_send', {conversationUid, content});
    }

    /**
     * @param {number} conversationUid
     * @returns {Promise<{status: string}>}
     */
    async resumeConversation(conversationUid) {
        return this._post('ai_chat_conversation_resume', {conversationUid});
    }

    /**
     * @param {number} conversationUid
     * @returns {Promise<{status: string}>}
     */
    async archiveConversation(conversationUid) {
        return this._post('ai_chat_conversation_archive', {conversationUid});
    }

    /**
     * @param {number} conversationUid
     * @returns {Promise<{pinned: boolean}>}
     */
    async togglePin(conversationUid) {
        return this._post('ai_chat_conversation_pin', {conversationUid});
    }

    /**
     * Resolve a TYPO3 AJAX route URL.
     * @param {string} routeName
     * @returns {string}
     */
    _url(routeName) {
        // TYPO3 injects AJAX URLs into a global object
        if (typeof TYPO3 !== 'undefined' && TYPO3.settings && TYPO3.settings.ajaxUrls) {
            const url = TYPO3.settings.ajaxUrls[routeName];
            if (url) return url;
        }
        // In production this must never be reached — TYPO3 always provides ajaxUrls.
        throw new Error(`AJAX route "${routeName}" not found. Is the backend module loaded correctly?`);
    }

    /**
     * @param {string} routeName
     * @param {Record<string, any>} [params]
     */
    async _get(routeName, params) {
        let url = this._url(routeName);
        if (params) {
            const qs = new URLSearchParams();
            for (const [k, v] of Object.entries(params)) {
                qs.set(k, String(v));
            }
            url += (url.includes('?') ? '&' : '?') + qs.toString();
        }
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        return this._handleResponse(res);
    }

    /**
     * @param {string} routeName
     * @param {Record<string, any>} body
     */
    async _post(routeName, body) {
        const res = await fetch(this._url(routeName), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        });
        return this._handleResponse(res);
    }

    /** @param {Response} res */
    async _handleResponse(res) {
        let data;
        try {
            data = await res.json();
        } catch {
            throw new Error(`HTTP ${res.status}: unexpected response`);
        }
        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
    }
}
