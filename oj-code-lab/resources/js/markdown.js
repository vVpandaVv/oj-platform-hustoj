/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('../vendor/markdown-it/markdown-it.js'));
    } else {
        root.OclMarkdown = factory(root.markdownit);
    }
}(typeof window !== 'undefined' ? window : globalThis, function (MarkdownIt) {
    'use strict';
    var parser;
    // This is a preview limit, not a storage limit. Larger notes remain intact.
    var MAX_PREVIEW_LENGTH = 100000;

    function safeUrl(value, image) {
        if (typeof value !== 'string' || !value) { return false; }
        var decoded = value;
        // Reject encoded controls/backslashes as well as literal obfuscation.
        for (var i = 0; i < 3; i++) {
            if (/[\u0000-\u001f\u007f-\u009f\\]/.test(decoded)) { return false; }
            var next;
            try { next = decodeURIComponent(decoded); } catch (_) { return false; }
            if (next === decoded) { break; }
            decoded = next;
        }
        if (/[\u0000-\u001f\u007f-\u009f\\]/.test(decoded) || /^\/\//.test(decoded)) { return false; }
        if (/^https?:\/\/[^/?#\s]+(?:[/?#]|$)/i.test(value)) { return true; }
        if (!image && (/^mailto:[^@\s]+@[^@\s]+$/i.test(value) || /^#[^\s]*$/.test(value))) { return true; }
        return false;
    }

    function getParser() {
        if (parser) { return parser; }
        if (typeof MarkdownIt !== 'function') { throw new Error('Markdown renderer unavailable'); }
        parser = new MarkdownIt({
            html: false,
            linkify: false,
            typographer: false,
            breaks: true,
            maxNesting: 20
        });
        parser.validateLink = function (url) { return safeUrl(url, false); };
        parser.renderer.rules.link_open = function (tokens, index, options, env, renderer) {
            tokens[index].attrSet('rel', 'noopener noreferrer');
            tokens[index].attrSet('referrerpolicy', 'no-referrer');
            return renderer.renderToken(tokens, index, options);
        };
        var imageRule = parser.renderer.rules.image;
        parser.renderer.rules.image = function (tokens, index, options, env, renderer) {
            var token = tokens[index];
            if (!safeUrl(token.attrGet('src'), true)) {
                return parser.utils.escapeHtml(renderer.renderInlineAsText(token.children || [], options, env));
            }
            token.attrSet('loading', 'lazy');
            token.attrSet('referrerpolicy', 'no-referrer');
            return imageRule(tokens, index, options, env, renderer);
        };
        return parser;
    }

    function render(container, source) {
        var text = typeof source === 'string' ? source : '';
        try {
            if (text.length > MAX_PREVIEW_LENGTH) { throw new Error('Preview size limit'); }
            // No raw HTML, custom plugins or syntax highlighters can inject HTML.
            container.innerHTML = getParser().render(text);
            if (container.classList) { container.classList.remove('ocl-markdown-plain'); }
            return true;
        } catch (_) {
            container.textContent = text;
            if (container.classList) { container.classList.add('ocl-markdown-plain'); }
            return false;
        }
    }

    return { render: render };
}));
