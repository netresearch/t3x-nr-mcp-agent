/**
 * Entry point for AI Chat panel - auto-loaded in the outer backend frame
 * via the backend.module import map tag.
 *
 * Finds the toolbar button rendered by ChatToolbarItem and wires it
 * to the <ai-chat-panel> component.
 */
import '../ai-chat-panel.js';

class ChatPanelToolbarInit {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this._init());
        } else {
            this._init();
        }
    }

    _init() {
        const btn = document.querySelector('.ai-chat-toolbar-btn');
        if (!btn) return;

        const panel = document.createElement('ai-chat-panel');
        document.body.appendChild(panel);

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            panel.toggle();
        });
    }
}

new ChatPanelToolbarInit();
