<script setup>
// Sync history, errors and warnings, and failed work to retry or dismiss.
import { t } from '../i18n.js';
import { action, ago, levelVariant, post, runVariant, stateVariant, when, withQuery } from '../lib/shared.js';
import ShopifyNav from '../components/ShopifyNav.vue';

const {
    layouts: { AppLayout },
    Inertia: { Head, Link, router },
    ui: { PageHeader, Card, StatTile, Badge, Button },
} = window.Inventoros;

const props = defineProps({
    title: { type: String, default: 'Shopify sync activity' },
    connections: { type: Array, default: () => [] },
    connection: { type: Object, default: null },
    health: { type: Object, default: null },
    tab: { type: String, default: 'runs' },
    runs: { type: Array, default: () => [] },
    run: { type: Object, default: null },
    logs: { type: Array, default: () => [] },
    deadLetters: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({ page: 1, perPage: 50, total: 0 }) },
    canManage: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const tabs = [
    ['runs', 'activity.runs'],
    ['errors', 'activity.errors'],
    ['failed', 'activity.failed'],
];

const go = (params) => router.get(withQuery(window.location.href, { page: null, ...params }), {}, { preserveScroll: true });
const totalPages = () => Math.max(1, Math.ceil(props.pagination.total / props.pagination.perPage));
const retry = (letter) => post(action(props.urls, `failed/${letter.id}/retry`));
const dismiss = (letter) => post(action(props.urls, `failed/${letter.id}/dismiss`));
const resource = (value) => (['locations', 'products', 'inventory', 'orders'].includes(value) ? t(`resources.${value}`) : value);
</script>

<template>
    <Head :title="title" />
    <AppLayout>
        <div class="shopify-page">
            <PageHeader :title="t('activity.title')" :description="t('activity.description')" />

            <ShopifyNav :urls="urls" current="activity" :connection="connection?.id ?? null" />

            <Card v-if="!connection">
                <p class="shopify-empty">{{ t('mappings.noStore') }}</p>
            </Card>

            <template v-else>
                <div class="shopify-spread">
                    <div class="shopify-row">
                        <label v-if="connections.length > 1" class="shopify-row">
                            <span class="shopify-label">{{ t('mappings.store') }}</span>
                            <select class="shopify-select" :value="connection.id" @change="go({ connection: $event.target.value, run: null })">
                                <option v-for="item in connections" :key="item.id" :value="item.id">{{ item.name }} ({{ item.shop_domain }})</option>
                            </select>
                        </label>
                        <span class="shopify-mono">{{ connection.shop_domain }}</span>
                        <Badge v-if="health" :variant="stateVariant(health.state)" dot>{{ t(`states.${health.state}`) }}</Badge>
                    </div>
                </div>

                <div v-if="health" class="shopify-tiles">
                    <StatTile :label="t('health.lastSync')" :value="health.last_sync_at ? ago(health.last_sync_at) : t('health.never')" />
                    <StatTile :label="t('health.queue')" :value="health.queue_depth" :hint="t('health.queueHint', health.queue)" />
                    <StatTile :label="t('health.errorRate')" :value="`${health.error_rate}%`" :hint="t('health.errorRateHint', { failed: health.failed_runs_24h, total: health.runs_24h })" />
                    <StatTile :label="t('health.failed')" :value="health.open_dead_letters" />
                </div>

                <div class="shopify-chips" role="tablist">
                    <Link
                        v-for="[value, label] in tabs"
                        :key="value"
                        class="shopify-chip"
                        role="tab"
                        :aria-current="tab === value ? 'true' : null"
                        :href="withQuery(urls.activity, { connection: connection.id, tab: value })"
                        preserve-scroll
                    >
                        {{ t(label) }}<span v-if="value === 'failed' && health?.open_dead_letters"> ({{ health.open_dead_letters }})</span>
                    </Link>
                </div>

                <div v-if="tab === 'runs'" class="shopify-table-wrap">
                    <table v-if="runs.length" class="shopify-table">
                        <thead>
                            <tr>
                                <th>{{ t('activity.when') }}</th>
                                <th>{{ t('activity.type') }}</th>
                                <th>{{ t('activity.resource') }}</th>
                                <th>{{ t('activity.result') }}</th>
                                <th>{{ t('activity.counts') }}</th>
                                <th>{{ t('activity.details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in runs" :key="item.id" :class="{ 'shopify-selected': run && run.id === item.id }">
                                <td class="shopify-nowrap">{{ when(item.started_at) }}</td>
                                <td>{{ item.type }} <span class="shopify-faint">· {{ item.trigger }}</span></td>
                                <td>{{ resource(item.resource) }}</td>
                                <td>
                                    <Badge :variant="runVariant(item.status)">{{ t(`runStatus.${item.status}`) }}</Badge>
                                    <div v-if="item.error_summary" class="shopify-error">{{ item.error_summary }}</div>
                                </td>
                                <td class="shopify-muted">{{ t('activity.countsValue', item) }}</td>
                                <td>
                                    <Link
                                        class="shopify-link"
                                        :href="withQuery(urls.activity, { connection: connection.id, tab: 'runs', page: pagination.page, run: run && run.id === item.id ? null : item.id })"
                                        preserve-scroll
                                    >
                                        {{ run && run.id === item.id ? t('activity.hideDetails') : t('activity.details') }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="shopify-empty">{{ t('activity.noRuns') }}</p>
                </div>

                <Card v-if="tab === 'runs' && run">
                    <div class="shopify-page">
                        <h2 class="shopify-title">{{ t('activity.runLog') }} #{{ run.id }}</h2>
                        <div class="shopify-table-wrap">
                            <table v-if="run.logs.length" class="shopify-table">
                                <tbody>
                                    <tr v-for="log in run.logs" :key="log.id">
                                        <td class="shopify-nowrap"><Badge :variant="levelVariant(log.level)" size="sm">{{ t(`levels.${log.level}`) }}</Badge></td>
                                        <td>{{ log.message }}</td>
                                        <td class="shopify-faint shopify-nowrap">{{ when(log.created_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="shopify-empty">{{ t('activity.noErrors') }}</p>
                        </div>
                    </div>
                </Card>

                <div v-if="tab === 'errors'" class="shopify-table-wrap">
                    <table v-if="logs.length" class="shopify-table">
                        <thead>
                            <tr>
                                <th>{{ t('activity.when') }}</th>
                                <th>{{ t('activity.result') }}</th>
                                <th>{{ t('activity.message') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in logs" :key="log.id">
                                <td class="shopify-nowrap">{{ when(log.created_at) }}</td>
                                <td><Badge :variant="levelVariant(log.level)">{{ t(`levels.${log.level}`) }}</Badge></td>
                                <td>
                                    {{ log.message }}
                                    <div v-if="log.external_id" class="shopify-faint shopify-mono">{{ log.entity }} #{{ log.external_id }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="shopify-empty">{{ t('activity.noErrors') }}</p>
                </div>

                <div v-if="tab === 'failed'" class="shopify-table-wrap">
                    <table v-if="deadLetters.length" class="shopify-table">
                        <thead>
                            <tr>
                                <th>{{ t('activity.when') }}</th>
                                <th>{{ t('activity.kind') }}</th>
                                <th>{{ t('activity.error') }}</th>
                                <th>{{ t('activity.attempts') }}</th>
                                <th v-if="canManage">{{ t('mappings.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="letter in deadLetters" :key="letter.id">
                                <td class="shopify-nowrap">{{ when(letter.failed_at) }}</td>
                                <td>{{ letter.kind }}<div v-if="letter.reference" class="shopify-faint">{{ letter.reference }}</div></td>
                                <td>{{ letter.error }}</td>
                                <td>{{ letter.attempts }}</td>
                                <td v-if="canManage">
                                    <div class="shopify-row">
                                        <Button size="xs" @click="retry(letter)">{{ t('activity.retry') }}</Button>
                                        <Button size="xs" variant="ghost" @click="dismiss(letter)">{{ t('activity.dismiss') }}</Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="shopify-empty">{{ t('activity.noFailed') }}</p>
                </div>

                <div v-if="totalPages() > 1" class="shopify-row">
                    <Button size="sm" variant="secondary" :disabled="pagination.page <= 1" @click="go({ page: pagination.page - 1, run: null })">{{ t('mappings.previous') }}</Button>
                    <span class="shopify-muted">{{ t('mappings.page', { page: pagination.page, pages: totalPages() }) }}</span>
                    <Button size="sm" variant="secondary" :disabled="pagination.page >= totalPages()" @click="go({ page: pagination.page + 1, run: null })">{{ t('mappings.next') }}</Button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
