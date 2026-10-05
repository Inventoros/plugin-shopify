<script setup>
// Review and change which Shopify record stands for which Inventoros record.
import { reactive, ref } from 'vue';
import { t } from '../i18n.js';
import { action, ago, mappingVariant, post, withQuery } from '../lib/shared.js';
import ShopifyNav from '../components/ShopifyNav.vue';

const {
    layouts: { AppLayout },
    Inertia: { Head, Link, router },
    ui: { PageHeader, Card, Badge, Button },
} = window.Inventoros;

const props = defineProps({
    title: { type: String, default: 'Shopify mappings' },
    connections: { type: Array, default: () => [] },
    connection: { type: Object, default: null },
    entity: { type: String, default: 'variant' },
    status: { type: String, default: null },
    search: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    pagination: { type: Object, default: () => ({ page: 1, perPage: 50, total: 0 }) },
    warehouses: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const entities = [
    ['variant', 'mappings.products'],
    ['location', 'mappings.locations'],
    ['order', 'mappings.orders'],
];
const statuses = ['conflict', 'suggested', 'unmatched', 'pending', 'mapped', 'ignored'];

const query = ref(props.search);
const skus = reactive({});
const errors = reactive({});
const locations = reactive(
    Object.fromEntries(
        props.rows
            .filter((row) => row.entity === 'location')
            .map((row) => [row.id, { warehouse_id: row.internal_type === 'organization' ? 'all' : row.internal_id ?? '', primary: row.primary }]),
    ),
);

const go = (params) => router.get(withQuery(window.location.href, { page: null, ...params }), {}, { preserveScroll: true });
const totalPages = () => Math.max(1, Math.ceil(props.pagination.total / props.pagination.perPage));
const total = () => Object.values(props.counts).reduce((sum, count) => sum + count, 0);

const run = (row, verb, data = {}) =>
    post(action(props.urls, `mappings/${row.id}/${verb}`), data, {
        onError: (bag) => {
            errors[row.id] = Object.values(bag)[0];
        },
        onSuccess: () => {
            delete errors[row.id];
        },
    });

const link = (row) => run(row, 'link', { sku: skus[row.id] ?? '' });
const saveLocation = (row) => run(row, 'link', locations[row.id]);
const confirmAll = () => post(action(props.urls, `connections/${props.connection.id}/confirm-all`));
</script>

<template>
    <Head :title="title" />
    <AppLayout>
        <div class="shopify-page">
            <PageHeader :title="t('mappings.title')" :description="t('mappings.description')">
                <template #actions>
                    <Button v-if="canManage && connection && entity === 'variant' && counts.suggested" @click="confirmAll">
                        {{ t('mappings.confirmAll') }} ({{ counts.suggested }})
                    </Button>
                </template>
            </PageHeader>

            <ShopifyNav :urls="urls" current="mappings" :connection="connection?.id ?? null" />

            <Card v-if="!connection">
                <p class="shopify-empty">{{ t('mappings.noStore') }}</p>
            </Card>

            <template v-else>
                <div class="shopify-spread">
                    <div class="shopify-row">
                        <label v-if="connections.length > 1" class="shopify-row">
                            <span class="shopify-label">{{ t('mappings.store') }}</span>
                            <select class="shopify-select" :value="connection.id" @change="go({ connection: $event.target.value, status: null, search: null })">
                                <option v-for="item in connections" :key="item.id" :value="item.id">{{ item.name }} ({{ item.shop_domain }})</option>
                            </select>
                        </label>
                        <div class="shopify-chips" role="tablist">
                            <Link
                                v-for="[value, label] in entities"
                                :key="value"
                                class="shopify-chip"
                                role="tab"
                                :aria-current="entity === value ? 'true' : null"
                                :href="withQuery(urls.mappings, { connection: connection.id, entity: value })"
                                preserve-scroll
                            >
                                {{ t(label) }}
                            </Link>
                        </div>
                    </div>
                    <form class="shopify-row" role="search" @submit.prevent="go({ search: query })">
                        <label class="shopify-visually-hidden" for="shopify-map-search">{{ t('mappings.search') }}</label>
                        <input id="shopify-map-search" v-model="query" class="shopify-input shopify-input--compact" type="search" :placeholder="t('mappings.search')" />
                    </form>
                </div>

                <div class="shopify-chips">
                    <button type="button" class="shopify-chip" :aria-current="status === null ? 'true' : null" @click="go({ status: null })">
                        {{ t('mappings.all') }} ({{ total() }})
                    </button>
                    <button
                        v-for="value in statuses.filter((s) => counts[s])"
                        :key="value"
                        type="button"
                        class="shopify-chip"
                        :aria-current="status === value ? 'true' : null"
                        @click="go({ status: value })"
                    >
                        {{ t(`statuses.${value}`) }} ({{ counts[value] }})
                    </button>
                </div>

                <p v-if="entity === 'location'" class="shopify-muted">{{ t('mappings.primaryHint') }}</p>

                <div class="shopify-table-wrap">
                    <table v-if="rows.length" class="shopify-table">
                        <thead>
                            <tr>
                                <th>{{ t('mappings.shopify') }}</th>
                                <th>{{ t('mappings.inventoros') }}</th>
                                <th>{{ t('mappings.status') }}</th>
                                <th v-if="canManage">{{ t('mappings.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id">
                                <td>
                                    <div>{{ row.external_label || row.external_id }}</div>
                                    <div class="shopify-faint">
                                        <span v-if="row.sku" class="shopify-mono">{{ row.sku }}</span>
                                        <span v-if="row.sku"> · </span>
                                        <span class="shopify-mono">#{{ row.external_id }}</span>
                                        <span v-if="row.price"> · {{ row.price }}</span>
                                    </div>
                                </td>
                                <td>
                                    <template v-if="row.internal">
                                        <Link v-if="row.internal.url" class="shopify-link" :href="row.internal.url">{{ row.internal.label }}</Link>
                                        <span v-else-if="row.internal.label === 'all'">{{ t('mappings.allStock') }}</span>
                                        <span v-else>{{ row.internal.label }}</span>
                                        <div class="shopify-faint">
                                            <span v-if="row.internal.sku" class="shopify-mono">{{ row.internal.sku }}</span>
                                            <span v-if="row.internal.stock !== undefined"> · {{ t('mappings.stock', { count: row.internal.stock }) }}</span>
                                            <span v-if="row.internal.status"> · {{ row.internal.status }}</span>
                                        </div>
                                    </template>
                                    <span v-else class="shopify-faint">{{ t('mappings.none') }}</span>
                                </td>
                                <td>
                                    <div class="shopify-row">
                                        <Badge :variant="mappingVariant(row.status)">{{ t(`statuses.${row.status}`) }}</Badge>
                                        <Badge v-if="row.primary" variant="brand">{{ t('mappings.primary') }}</Badge>
                                    </div>
                                    <div v-if="row.conflict" class="shopify-error">{{ row.conflict }}</div>
                                    <div v-if="row.synced_at" class="shopify-faint">{{ ago(row.synced_at) }}</div>
                                </td>
                                <td v-if="canManage">
                                    <div v-if="row.entity === 'variant'" class="shopify-row">
                                        <Button v-if="row.status === 'suggested'" size="xs" @click="run(row, 'confirm')">{{ t('mappings.confirm') }}</Button>
                                        <form class="shopify-row" @submit.prevent="link(row)">
                                            <label class="shopify-visually-hidden" :for="`shopify-sku-${row.id}`">{{ t('mappings.linkBySku') }}</label>
                                            <input :id="`shopify-sku-${row.id}`" v-model="skus[row.id]" class="shopify-input shopify-input--compact" :placeholder="t('mappings.linkBySku')" />
                                            <Button type="submit" size="xs" variant="secondary">{{ t('mappings.link') }}</Button>
                                        </form>
                                        <Button v-if="row.status !== 'ignored'" size="xs" variant="ghost" @click="run(row, 'ignore')">{{ t('mappings.ignore') }}</Button>
                                        <Button size="xs" variant="ghost" @click="run(row, 'reset')">{{ t('mappings.reset') }}</Button>
                                        <Button v-if="row.status === 'mapped'" size="xs" variant="ghost" @click="run(row, 'resync')">{{ t('mappings.resync') }}</Button>
                                    </div>
                                    <form v-else-if="row.entity === 'location'" class="shopify-row" @submit.prevent="saveLocation(row)">
                                        <label class="shopify-visually-hidden" :for="`shopify-wh-${row.id}`">{{ t('mappings.warehouse') }}</label>
                                        <select :id="`shopify-wh-${row.id}`" v-model="locations[row.id].warehouse_id" class="shopify-select shopify-input--compact" required>
                                            <option value="" disabled>{{ t('mappings.warehouse') }}</option>
                                            <option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                                            <option value="all">{{ t('mappings.allStock') }}</option>
                                        </select>
                                        <label class="shopify-check">
                                            <input v-model="locations[row.id].primary" type="checkbox" />
                                            {{ t('mappings.primary') }}
                                        </label>
                                        <Button type="submit" size="xs" variant="secondary">{{ t('mappings.save') }}</Button>
                                        <Button v-if="row.status !== 'ignored'" size="xs" variant="ghost" @click="run(row, 'ignore')">{{ t('mappings.ignore') }}</Button>
                                    </form>
                                    <div v-else class="shopify-row">
                                        <Button size="xs" variant="secondary" @click="run(row, 'resync')">{{ t('mappings.resync') }}</Button>
                                    </div>
                                    <p v-if="errors[row.id]" class="shopify-error">{{ errors[row.id] }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="shopify-empty">{{ t('mappings.empty') }}</p>
                </div>

                <div v-if="totalPages() > 1" class="shopify-row">
                    <Button size="sm" variant="secondary" :disabled="pagination.page <= 1" @click="go({ page: pagination.page - 1 })">{{ t('mappings.previous') }}</Button>
                    <span class="shopify-muted">{{ t('mappings.page', { page: pagination.page, pages: totalPages() }) }}</span>
                    <Button size="sm" variant="secondary" :disabled="pagination.page >= totalPages()" @click="go({ page: pagination.page + 1 })">{{ t('mappings.next') }}</Button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
