/** Formatting helpers ported from the pre-Laravel common.js. */

export function escapeHtml(value: unknown): string {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const ANSI_CLASSES: Record<string, string> = {
    '1': 'ansi-bold',
    '2': 'ansi-dim',
    '30': 'ansi-gray',
    '31': 'ansi-red',
    '32': 'ansi-green',
    '33': 'ansi-yellow',
    '34': 'ansi-blue',
    '35': 'ansi-magenta',
    '36': 'ansi-cyan',
    '90': 'ansi-gray',
    '91': 'ansi-red',
    '92': 'ansi-green',
    '93': 'ansi-yellow',
    '94': 'ansi-blue',
    '95': 'ansi-magenta',
    '96': 'ansi-cyan',
};

/**
 * Escapes terminal output and turns ANSI colour codes into <span class="ansi-*">.
 * The result is safe for dangerouslySetInnerHTML (all text is escaped first).
 */
export function ansiToHtml(rawText: string | null | undefined): string {
    if (!rawText) return '';
    let escaped = escapeHtml(String(rawText).replace(/\r\n/g, '\n'));

    let openSpans = 0;
    // eslint-disable-next-line no-control-regex
    escaped = escaped.replace(/\x1b\[([0-9;]*)m/g, (_m, codesStr: string) => {
        if (!codesStr || codesStr === '0') {
            const closers = '</span>'.repeat(openSpans);
            openSpans = 0;
            return closers;
        }
        let out = '';
        for (const c of codesStr.split(';')) {
            if (c === '0') {
                out += '</span>'.repeat(openSpans);
                openSpans = 0;
            } else if (ANSI_CLASSES[c]) {
                out += `<span class="${ANSI_CLASSES[c]}">`;
                openSpans++;
            }
        }
        return out;
    });

    // Strip any remaining non-colour CSI control codes
    // eslint-disable-next-line no-control-regex
    escaped = escaped.replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '');

    return escaped + '</span>'.repeat(openSpans);
}

export function formatDuration(ms: number | string | null | undefined): string {
    if (ms === null || ms === undefined) return '—';
    const n = Number(ms);
    if (Number.isNaN(n)) return '—';
    if (n < 1000) return n + 'ms';
    if (n < 60000) return (n / 1000).toFixed(2) + 's';
    const mins = Math.floor(n / 60000);
    const secs = ((n % 60000) / 1000).toFixed(0);
    return mins + 'm ' + secs + 's';
}

export function formatDateTime(dt: string | null | undefined): string {
    return dt ? String(dt) : 'Never';
}

/** Strip HTML tags (for plain-text fallbacks such as window.confirm). */
export function stripTags(html: string): string {
    return html.replace(/<[^>]+>/g, '');
}

export async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        ta.remove();
        return ok;
    }
}
