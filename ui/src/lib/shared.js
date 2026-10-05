// Helpers shared by the Shopify pages.
import { t } from '../i18n.js';

export const sdk = () => window.Inventoros;

const locale = () => document.documentElement.lang || undefined;

export const when = (iso) => (iso ? new Date(iso).toLocaleString(locale()) : t('health.never'));

export const ago = (iso) => {
    if (!iso) {
        return t('health.never');
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
        ['second', 1],
    ];

    try {
        const format = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });
        for (const [unit, size] of units) {
            if (Math.abs(seconds) >= size || unit === 'second') {
                return format.format(Math.round(seconds / size), unit);
            }
        }
    } catch {
        // Older browsers: fall through to the absolute time.
    }

    return when(iso);
};

// Badge variants of the core Badge component.
export const stateVariant = (state) =>
    ({
        healthy: 'success',
        degraded: 'warning',
        paused: 'warning',
        error: 'danger',
        disconnected: 'neutral',
        pending: 'info',
    })[state] || 'neutral';

export const mappingVariant = (status) =>
    ({
        mapped: 'success',
        suggested: 'info',
        unmatched: 'neutral',
        conflict: 'danger',
        ignored: 'neutral',
        pending: 'warning',
    })[status] || 'neutral';

export const runVariant = (status) =>
    ({
        succeeded: 'success',
        partial: 'warning',
        failed: 'danger',
        running: 'info',
        queued: 'neutral',
    })[status] || 'neutral';

export const levelVariant = (level) => ({ info: 'info', warning: 'warning', error: 'danger' })[level] || 'neutral';

// POST to a plugin action and keep the page where it is.
export const post = (url, data = {}, options = {}) =>
    sdk().Inertia.router.post(url, data, { preserveScroll: true, preserveState: true, ...options });

export const action = (urls, path) => `${urls.actions}/${path}`;

export const withQuery = (base, params) => {
    const url = new URL(base, window.location.origin);
    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    });

    return url.pathname + url.search;
};
