<script setup>
// The three Shopify pages, as tabs under the page header.
import { t } from '../i18n.js';

const { Link } = window.Inventoros.Inertia;

defineProps({
    urls: { type: Object, required: true },
    current: { type: String, required: true },
    connection: { type: [Number, null], default: null },
});

const href = (url, connection) => (connection ? `${url}?connection=${connection}` : url);
</script>

<template>
    <nav class="shopify-nav" :aria-label="t('overview.title')">
        <Link :href="urls.overview" :aria-current="current === 'overview' ? 'page' : null">{{ t('nav.overview') }}</Link>
        <Link :href="href(urls.mappings, connection)" :aria-current="current === 'mappings' ? 'page' : null">{{ t('nav.mappings') }}</Link>
        <Link :href="href(urls.activity, connection)" :aria-current="current === 'activity' ? 'page' : null">{{ t('nav.activity') }}</Link>
    </nav>
</template>
