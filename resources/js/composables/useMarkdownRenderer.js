/**
 * useMarkdownRenderer — renders decrypted note content as safe HTML.
 *
 * Pipeline: plaintext (already decrypted) -> markdown-it -> DOMPurify -> safe HTML.
 * Never use raw v-html with the output of markdown-it without DOMPurify.
 */
import MarkdownIt from 'markdown-it'
import DOMPurify from 'dompurify'

const md = new MarkdownIt({ linkify: true, typographer: true, breaks: true })

// Allow a safe subset of tags/attributes for note rendering.
const SANITIZE_CONFIG = {
    ALLOWED_TAGS: [
        'p', 'br', 'hr', 'strong', 'em', 'del', 's', 'ins', 'sub', 'sup',
        'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'a', 'span', 'div',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'img',
    ],
    ALLOWED_ATTR: ['href', 'target', 'rel', 'src', 'alt', 'title', 'class'],
}

export function useMarkdownRenderer() {
    /**
     * @param {string} plaintext Decrypted note content (already in plaintext)
     * @param {string} contentType 'markdown' | 'plain'
     * @returns {string} Sanitized HTML safe for v-html
     */
    function renderNote(plaintext, contentType = 'plain') {
        const safeInput = plaintext ?? ''
        if (contentType !== 'markdown') {
            return DOMPurify.sanitize(escapeHtml(safeInput).replace(/\n/g, '<br>'), SANITIZE_CONFIG)
        }
        const rawHtml = md.render(safeInput)
        return DOMPurify.sanitize(rawHtml, SANITIZE_CONFIG)
    }

    return { renderNote }
}

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
}
