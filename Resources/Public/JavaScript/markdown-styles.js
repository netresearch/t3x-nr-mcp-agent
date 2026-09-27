import {css} from 'lit';

/**
 * Scoped CSS for Markdown-rendered assistant messages.
 * Shared between ai-chat-panel.js and chat-app.js.
 *
 * Color-scheme neutral: structural tints are derived from currentColor
 * via color-mix() so they adapt to light and dark backend schemes; the
 * link color uses the shared --nr-chat-link token (see theme.js).
 */
export const markdownStyles = css`
    .message.assistant p { margin: 0 0 6px; }
    .message.assistant p:last-child { margin-bottom: 0; }
    .message.assistant ul, .message.assistant ol { margin: 4px 0 6px; padding-left: 20px; }
    .message.assistant li { margin-bottom: 2px; }
    .message.assistant h1, .message.assistant h2, .message.assistant h3,
    .message.assistant h4, .message.assistant h5, .message.assistant h6 {
        margin: 8px 0 4px; font-weight: 600; line-height: 1.3;
    }
    .message.assistant h1 { font-size: 1.2em; }
    .message.assistant h2 { font-size: 1.1em; }
    .message.assistant h3 { font-size: 1em; }
    .message.assistant code {
        background: color-mix(in srgb, currentColor 8%, transparent); border-radius: 3px;
        padding: 1px 4px; font-family: monospace; font-size: 0.9em;
    }
    .message.assistant pre {
        background: color-mix(in srgb, currentColor 8%, transparent); border-radius: 4px;
        padding: 8px 10px; overflow-x: auto; margin: 4px 0 6px;
    }
    .message.assistant pre code { background: none; padding: 0; }
    .message.assistant blockquote {
        border-left: 3px solid color-mix(in srgb, currentColor 20%, transparent); margin: 4px 0;
        padding: 2px 10px; color: var(--nr-chat-text-variant);
    }
    .message.assistant hr { border: none; border-top: 1px solid color-mix(in srgb, currentColor 15%, transparent); margin: 8px 0; }
    .message.assistant table { border-collapse: collapse; margin: 6px 0; font-size: 0.9em; }
    .message.assistant th, .message.assistant td {
        border: 1px solid color-mix(in srgb, currentColor 15%, transparent); padding: 3px 8px; text-align: left;
    }
    .message.assistant th { background: color-mix(in srgb, currentColor 6%, transparent); font-weight: 600; }
    .message.assistant a { color: var(--nr-chat-link, #0078d4); text-decoration: underline; }
`;
