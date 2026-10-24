// SPDX-License-Identifier: GPL-2.0-or-later
// SPDX-FileCopyrightText: Netresearch DTT GmbH
(() => {
    const preferenceKey = 'nr-theme';
    const darkScheme = window.matchMedia('(prefers-color-scheme: dark)');
    let preference = 'system';
    try {
        const stored = localStorage.getItem(preferenceKey);
        if (['system', 'light', 'dark'].includes(stored)) preference = stored;
    } catch { /* The system preference also works when storage is unavailable. */ }

    const applyTheme = () => {
        document.documentElement.dataset.theme = preference === 'system'
            ? (darkScheme.matches ? 'dark' : 'light') : preference;
    };
    applyTheme();
    darkScheme.addEventListener('change', applyTheme);

    document.addEventListener('DOMContentLoaded', () => {
        const select = document.querySelector('[data-theme-control]');
        if (select) {
            select.closest('.theme-control').hidden = false;
            select.value = preference;
            select.addEventListener('change', () => {
                preference = select.value;
                try { localStorage.setItem(preferenceKey, preference); } catch { /* Optional preference. */ }
                applyTheme();
            });
        }

        const button = document.querySelector('.menu-toggle');
        const navigation = document.getElementById('site-navigation');
        if (!button || !navigation) return;
        const mobile = window.matchMedia('(max-width: 63.99rem)');
        const close = () => {
            button.setAttribute('aria-expanded', 'false');
            navigation.hidden = mobile.matches;
        };
        const resize = () => {
            button.hidden = !mobile.matches;
            close();
        };
        button.addEventListener('click', () => {
            const expanded = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(expanded));
            navigation.hidden = !expanded;
        });
        navigation.addEventListener('click', (event) => {
            if (event.target.closest('a')) close();
        });
        document.addEventListener('focusin', (event) => {
            if (mobile.matches && !navigation.contains(event.target) && event.target !== button) close();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
                close();
                button.focus();
            }
        });
        mobile.addEventListener('change', resize);
        resize();
    });
})();
