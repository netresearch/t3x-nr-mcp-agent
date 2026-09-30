// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH

/**
 * Entry point for AI Chat panel - auto-loaded in the outer backend frame
 * via the backend.module import map tag.
 *
 * Finds the toolbar button rendered by ChatToolbarItem and wires it
 * to the <ai-chat-panel> component.
 */
import '../ai-chat-panel.js';

class ChatPanelToolbarInit {
    static init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => ChatPanelToolbarInit._wire());
        } else {
            ChatPanelToolbarInit._wire();
        }
    }

    static _wire() {
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

ChatPanelToolbarInit.init();
