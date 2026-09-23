import {html, css, nothing} from 'lit';
import {lll} from '@typo3/core/lit-helper.js';

/**
 * Editing parts shared by the module (chat-app.js) and the floating panel
 * (ai-chat-panel.js): editing a sent message and running the conversation
 * again from it, and the conversation's own instructions (NEXT-172).
 *
 * The state lives on the ChatCoreController both surfaces hold; these
 * functions only render it, so the two surfaces cannot drift apart.
 */

export const chatEditingStyles = css`
    .msg-edit {
        appearance: none;
        border: none;
        background: transparent;
        color: var(--nr-chat-text-variant);
        cursor: pointer;
        font-size: 11px;
        padding: 0 4px;
        border-radius: 4px;
    }
    .msg-edit:hover { color: var(--nr-chat-text); background: var(--nr-chat-hover); }
    .msg-edit:focus-visible { outline: 2px solid var(--nr-chat-focus-ring); outline-offset: 1px; }
    .message-meta {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .message-editor {
        display: flex;
        flex-direction: column;
        gap: 6px;
        width: 100%;
        min-width: 220px;
    }
    .message-editor textarea,
    .instructions-editor textarea {
        width: 100%;
        box-sizing: border-box;
        font: inherit;
        font-size: 13px;
        padding: 6px 8px;
        border: 1px solid var(--nr-chat-input-border);
        border-radius: 8px;
        background: var(--nr-chat-surface);
        color: var(--nr-chat-text);
        resize: vertical;
    }
    .message-editor textarea:focus,
    .instructions-editor textarea:focus {
        outline: none;
        border-color: var(--nr-chat-focus-ring);
        box-shadow: 0 0 0 1px var(--nr-chat-focus-ring);
    }
    .editor-actions {
        display: flex;
        gap: 6px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    .editor-hint {
        margin: 0;
        font-size: 11px;
        color: var(--nr-chat-text-variant);
    }
    .instructions-editor {
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 8px 10px;
        border-bottom: 1px solid var(--nr-chat-border);
        background: var(--nr-chat-surface-low);
        flex-shrink: 0;
    }
    .instructions-editor label {
        font-size: 12px;
        font-weight: 600;
        color: var(--nr-chat-text);
    }
    .has-instructions {
        color: var(--nr-chat-accent);
    }
`;

/** The small "edit" control under a message the user can still change. */
export function renderEditButton(chat, idx) {
    if (!chat.canEditMessage(idx) || chat.editingIndex !== -1) {
        return nothing;
    }
    return html`
        <button class="msg-edit" data-action="edit-message"
                title="${lll('chat.editMessage')}"
                aria-label="${lll('chat.editMessage')}"
                @click=${() => chat.startEdit(idx)}>✎ ${lll('chat.edit')}</button>
    `;
}

/**
 * The message being edited, in place of its bubble. Saving drops every later
 * message, which the hint says before the click rather than after it.
 */
export function renderMessageEditor(chat) {
    return html`
        <div class="message-editor">
            <textarea rows="3"
                      aria-label="${lll('chat.editMessage')}"
                      .value=${chat.editDraft}
                      @input=${(e) => { chat.editDraft = e.target.value; chat.host.requestUpdate(); }}
                      @keydown=${(e) => {
                          if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); chat.cancelEdit(); }
                          if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); chat.submitEdit(); }
                      }}></textarea>
            <p class="editor-hint">${lll('chat.editHint')}</p>
            <div class="editor-actions">
                <button class="btn btn-sm" @click=${() => chat.cancelEdit()}>${lll('chat.editCancel')}</button>
                <button class="btn btn-sm btn-primary" data-action="submit-edit"
                        ?disabled=${!chat.editDraft.trim() || chat.sending}
                        @click=${() => chat.submitEdit()}>${lll('chat.editSubmit')}</button>
            </div>
        </div>
    `;
}

/** Title of the instructions button: says whether the conversation has any. */
export function instructionsLabel(chat) {
    return chat.systemPrompt ? lll('instructions.buttonSet') : lll('instructions.button');
}

/**
 * The conversation's own instructions. The help text says what they do:
 * they take the place of the instructions configured for the chat, and the
 * assistant's identity and the rules about languages still apply.
 */
export function renderInstructionsEditor(chat) {
    if (!chat.systemPromptOpen) {
        return nothing;
    }
    return html`
        <div class="instructions-editor">
            <label for="nr-chat-instructions">${lll('instructions.label')}</label>
            <p class="editor-hint">${lll('instructions.help')}</p>
            <textarea id="nr-chat-instructions" rows="4" maxlength="10000"
                      .value=${chat.systemPromptDraft}
                      @input=${(e) => { chat.systemPromptDraft = e.target.value; chat.host.requestUpdate(); }}
                      @keydown=${(e) => {
                          if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); chat.closeSystemPrompt(); }
                      }}></textarea>
            <div class="editor-actions">
                <button class="btn btn-sm" @click=${() => chat.closeSystemPrompt()}>${lll('chat.editCancel')}</button>
                <button class="btn btn-sm btn-primary" data-action="save-instructions"
                        ?disabled=${chat.systemPromptSaving || chat.isProcessing()}
                        @click=${() => chat.saveSystemPrompt()}>${lll('instructions.save')}</button>
            </div>
        </div>
    `;
}
