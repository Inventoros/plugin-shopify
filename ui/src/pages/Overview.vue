<script setup>
// Connected stores: health, settings, syncs, and connecting a new store.
import { ref } from 'vue';
import { t } from '../i18n.js';
import { action, ago, post, stateVariant, when } from '../lib/shared.js';
import ShopifyNav from '../components/ShopifyNav.vue';
import ConnectionForm from '../components/ConnectionForm.vue';

const {
    layouts: { AppLayout },
    Inertia: { Head, Link },
    ui: { PageHeader, Card, StatTile, Badge, Button },
} = window.Inventoros;

const props = defineProps({
    title: { type: String, default: 'Shopify' },
    connections: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    supportsPaymentImport: { type: Boolean, default: false },
    apiVersion: { type: String, default: '' },
    defaults: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const adding = ref(props.connections.length === 0);
const editing = ref(null);

const resources = ['products', 'inventory', 'orders', 'locations'];

const sync = (connection, resource, mode = 'manual') => post(action(props.urls, `connections/${connection.id}/sync`), { resource, mode });
const test = (connection) => post(action(props.urls, `connections/${connection.id}/test`));
const resume = (connection) => post(action(props.urls, `connections/${connection.id}/resume`));
const disconnect = (connection) => {
    if (window.confirm(t('connection.confirmDisconnect', { name: connection.name }))) {
        post(action(props.urls, `connections/${connection.id}/delete`), {}, { preserveState: false });
    }
};
</script>

<template>
    <Head :title="title" />
    <AppLayout>
        <div class="shopify-page">
            <PageHeader :title="t('overview.title')" :description="t('overview.description')">
                <template #actions>
                    <Button v-if="canManage && connections.length && !adding" variant="default" @click="adding = true">{{ t('overview.connectAnother') }}</Button>
                </template>
            </PageHeader>

            <ShopifyNav :urls="urls" current="overview" />

            <Card v-if="adding && canManage">
                <div class="shopify-page">
                    <div>
                        <h2 class="shopify-title">{{ t('overview.connect') }}</h2>
                        <p class="shopify-muted">{{ t('overview.setupHint') }}</p>
                    </div>
                    <ConnectionForm :supports-payments="supportsPaymentImport" :action="urls.connections" :defaults="defaults" @done="adding = false" />
                </div>
            </Card>

            <Card v-if="!connections.length && !adding">
                <p class="shopify-empty">{{ canManage ? t('overview.empty') : t('overview.emptyReadOnly') }}</p>
            </Card>

            <Card v-for="connection in connections" :key="connection.id">
                <div class="shopify-page">
                    <div class="shopify-spread">
                        <div>
                            <div class="shopify-row">
                                <h2 class="shopify-title">{{ connection.name }}</h2>
                                <Badge :variant="stateVariant(connection.health.state)" dot>{{ t(`states.${connection.health.state}`) }}</Badge>
                            </div>
                            <p class="shopify-muted">
                                <span class="shopify-mono">{{ connection.shop_domain }}</span>
                                <span v-if="connection.shop_name"> · {{ connection.shop_name }}</span>
                                <span v-if="connection.currency"> · {{ connection.currency }}</span>
                            </p>
                            <p class="shopify-faint">{{ t('overview.apiVersion', { version: apiVersion }) }}<span v-if="connection.last_tested_at"> · {{ t('connection.lastTested', { time: ago(connection.last_tested_at) }) }}</span></p>
                        </div>
                        <div v-if="canManage" class="shopify-row">
                            <Button variant="secondary" size="sm" @click="test(connection)">{{ t('connection.test') }}</Button>
                            <Button variant="secondary" size="sm" @click="editing = editing === connection.id ? null : connection.id">{{ t('connection.edit') }}</Button>
                            <Button variant="ghost" size="sm" @click="disconnect(connection)">{{ t('connection.disconnect') }}</Button>
                        </div>
                    </div>

                    <div class="shopify-tiles">
                        <StatTile
                            :label="t('health.lastSync')"
                            :value="connection.health.last_sync_at ? ago(connection.health.last_sync_at) : t('health.never')"
                            :hint="connection.health.last_sync_status ? t(`runStatus.${connection.health.last_sync_status}`) : null"
                        />
                        <StatTile
                            :label="t('health.queue')"
                            :value="connection.health.queue_depth"
                            :hint="t('health.queueHint', connection.health.queue)"
                        />
                        <StatTile
                            :label="t('health.errorRate')"
                            :value="`${connection.health.error_rate}%`"
                            :hint="t('health.errorRateHint', { failed: connection.health.failed_runs_24h, total: connection.health.runs_24h })"
                        />
                        <StatTile :label="t('health.failed')" :value="connection.health.open_dead_letters" :hint="t('health.failedHint')" />
                    </div>

                    <div v-if="connection.health.paused_until" class="shopify-notice shopify-spread">
                        <span>{{ t('connection.pausedUntil', { time: when(connection.health.paused_until) }) }}</span>
                        <Button v-if="canManage" size="sm" variant="secondary" @click="resume(connection)">{{ t('connection.resume') }}</Button>
                    </div>

                    <div v-if="connection.health.last_error && connection.health.state !== 'healthy'" class="shopify-notice shopify-notice--danger">
                        <strong>{{ t('connection.lastError') }}:</strong> {{ connection.health.last_error }}
                    </div>

                    <div class="shopify-form">
                        <div class="shopify-field">
                            <span class="shopify-label">{{ t('connection.webhookUrl') }}</span>
                            <span class="shopify-mono">{{ connection.webhook_url }}</span>
                            <span class="shopify-faint">{{ connection.webhooks_registered_at ? `${t('connection.webhooksRegistered')} ${ago(connection.webhooks_registered_at)}` : t('connection.webhooksMissing') }}</span>
                        </div>
                        <div class="shopify-field">
                            <span class="shopify-label">{{ t('connection.credentials') }}</span>
                            <span>{{ (connection.settings.auth_mode === 'client_credentials' ? connection.has_client_id : connection.has_access_token) && connection.has_webhook_secret ? t('connection.credentialsSet') : t('connection.credentialsMissing') }}</span>
                        </div>
                    </div>

                    <div v-if="canManage" class="shopify-field">
                        <span class="shopify-label">{{ t('connection.sync') }}</span>
                        <div class="shopify-row">
                            <Button size="sm" :disabled="connection.health.state !== 'healthy' && connection.health.state !== 'degraded'" @click="sync(connection, 'all', 'initial')">{{ t('connection.initialSync') }}</Button>
                            <Button
                                v-for="resource in resources"
                                :key="resource"
                                size="sm"
                                variant="outline"
                                :disabled="connection.health.state !== 'healthy' && connection.health.state !== 'degraded'"
                                @click="sync(connection, resource)"
                            >
                                {{ t('connection.resync', { resource: t(`resources.${resource}`) }) }}
                            </Button>
                            <Link class="shopify-link shopify-muted" :href="`${urls.mappings}?connection=${connection.id}`">{{ t('nav.mappings') }}</Link>
                            <Link class="shopify-link shopify-muted" :href="`${urls.activity}?connection=${connection.id}`">{{ t('nav.activity') }}</Link>
                        </div>
                    </div>

                    <ConnectionForm
                        v-if="editing === connection.id"
                        :action="`${urls.actions}/connections/${connection.id}`"
                        :connection="connection"
                        :supports-payments="supportsPaymentImport"
                        @done="editing = null"
                    />
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
