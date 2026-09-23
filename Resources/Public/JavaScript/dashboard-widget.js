/**
 * Clicks in the "AI Chat" dashboard widget (NEXT-172).
 *
 * The dashboard runs inside the module frame; the floating chat panel lives in
 * the backend frame around it (ADR-011). A conversation or "New chat" clicked
 * in the widget opens there, next to the dashboard. Where the panel is not
 * available — a user without the toolbar item, or a frame that is not the
 * backend's — the link is followed and the chat module opens instead.
 */

function findPanel() {
    try {
        return (globalThis.top ?? globalThis).document.querySelector('ai-chat-panel');
    } catch {
        return null;
    }
}

function openPanel(panel) {
    if (panel.state === 'hidden' || panel.state === 'collapsed') {
        panel.toggle();
    }
}

document.addEventListener('click', (event) => {
    const link = event.composedPath().find(
        (el) => el instanceof Element && (el.hasAttribute('data-nr-chat-conversation') || el.hasAttribute('data-nr-chat-new')),
    );
    if (!link) {
        return;
    }

    const panel = findPanel();
    if (!panel || !panel.chat) {
        return; // follow the link to the module
    }

    event.preventDefault();
    openPanel(panel);

    const uid = Number.parseInt(link.getAttribute('data-nr-chat-conversation') ?? '', 10);
    if (uid > 0) {
        panel.chat.selectConversation(uid);
    } else {
        panel.chat.handleNewConversation();
    }
});
