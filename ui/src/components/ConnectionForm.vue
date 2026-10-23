<script setup>
// Connect a store, or change a connected store's settings. Credentials are
// write-only: the page never receives them, and blank fields keep them.
import { t } from '../i18n.js';

const { useForm } = window.Inventoros.Inertia;
const { PrimaryButton, SecondaryButton } = window.Inventoros.ui;

const props = defineProps({
    action: { type: String, required: true },
    connection: { type: Object, default: null },
    defaults: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['done']);

const settings = props.connection?.settings ?? props.defaults;
const today = new Date().toISOString().slice(0, 10);
const editing = props.connection !== null;
const prefix = editing ? `shopify-conn-${props.connection.id}` : 'shopify-new';

const form = useForm({
    name: props.connection?.name ?? '',
    shop_domain: props.connection?.shop_domain ?? '',
    auth_mode: settings.auth_mode ?? 'token',
    client_id: '',
    access_token: '',
    webhook_secret: '',
    stock_source: settings.stock_source ?? 'inventoros',
    product_source: settings.product_source ?? 'shopify',
    import_orders: settings.import_orders ?? true,
    import_test_orders: settings.import_test_orders ?? false,
    orders_since: settings.orders_since ?? today,
    history_before: settings.history_before ?? '',
});

const submit = () => {
    form.post(props.action, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('access_token', 'webhook_secret', 'client_id');
            emit('done');
        },
    });
};
</script>

<template>
    <form class="shopify-form" @submit.prevent="submit">
        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-name`">{{ t('form.name') }}</label>
            <input :id="`${prefix}-name`" v-model="form.name" class="shopify-input" type="text" maxlength="120" required :placeholder="t('form.namePlaceholder')" />
            <p v-if="form.errors.name" class="shopify-error">{{ form.errors.name }}</p>
        </div>

        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-domain`">{{ t('form.shopDomain') }}</label>
            <input
                :id="`${prefix}-domain`"
                v-model="form.shop_domain"
                class="shopify-input"
                type="text"
                autocomplete="off"
                spellcheck="false"
                placeholder="my-store.myshopify.com"
                :disabled="editing"
                :required="!editing"
            />
            <p class="shopify-faint">{{ t('form.shopDomainHint') }}</p>
            <p v-if="form.errors.shop_domain" class="shopify-error">{{ form.errors.shop_domain }}</p>
        </div>

        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-auth`">{{ t('form.authMode') }}</label>
            <select :id="`${prefix}-auth`" v-model="form.auth_mode" class="shopify-select">
                <option value="token">{{ t('form.authToken') }}</option>
                <option value="client_credentials">{{ t('form.authClient') }}</option>
            </select>
            <p class="shopify-faint">{{ t('form.authHint') }}</p>
            <p v-if="form.errors.auth_mode" class="shopify-error">{{ form.errors.auth_mode }}</p>
        </div>
        <div v-if="form.auth_mode === 'client_credentials'" class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-client`">{{ t('form.clientId') }}</label>
            <input :id="`${prefix}-client`" v-model="form.client_id" class="shopify-input" autocomplete="off" :required="!editing" />
            <p v-if="editing" class="shopify-faint">{{ t('form.keepSecret') }}</p>
            <p v-if="form.errors.client_id" class="shopify-error">{{ form.errors.client_id }}</p>
        </div>
        <div v-else class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-token`">{{ t('form.accessToken') }}</label>
            <input :id="`${prefix}-token`" v-model="form.access_token" class="shopify-input" type="password" autocomplete="new-password" :required="!editing" />
            <p class="shopify-faint">{{ editing ? t('form.keepSecret') : t('form.accessTokenHint') }}</p>
            <p v-if="form.errors.access_token" class="shopify-error">{{ form.errors.access_token }}</p>
        </div>

        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-secret`">{{ t('form.webhookSecret') }}</label>
            <input :id="`${prefix}-secret`" v-model="form.webhook_secret" class="shopify-input" type="password" autocomplete="new-password" :required="!editing" />
            <p class="shopify-faint">{{ editing ? t('form.keepSecret') : t('form.webhookSecretHint') }}</p>
            <p v-if="form.errors.webhook_secret" class="shopify-error">{{ form.errors.webhook_secret }}</p>
        </div>

        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-stock`">{{ t('form.stockSource') }}</label>
            <select :id="`${prefix}-stock`" v-model="form.stock_source" class="shopify-select">
                <option value="inventoros">{{ t('form.stockInventoros') }}</option>
                <option value="shopify">{{ t('form.stockShopify') }}</option>
            </select>
        </div>

        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-products`">{{ t('form.productSource') }}</label>
            <select :id="`${prefix}-products`" v-model="form.product_source" class="shopify-select">
                <option value="shopify">{{ t('form.productShopify') }}</option>
                <option value="inventoros">{{ t('form.productInventoros') }}</option>
            </select>
        </div>

        <div class="shopify-field">
            <label class="shopify-check">
                <input v-model="form.import_orders" type="checkbox" />
                {{ t('form.importOrders') }}
            </label>
            <label class="shopify-check">
                <input v-model="form.import_test_orders" type="checkbox" :disabled="!form.import_orders" />
                {{ t('form.importTestOrders') }}
            </label>
        </div>

        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-since`">{{ t('form.ordersSince') }}</label>
            <input :id="`${prefix}-since`" v-model="form.orders_since" class="shopify-input" type="date" :disabled="!form.import_orders" />
            <p v-if="form.errors.orders_since" class="shopify-error">{{ form.errors.orders_since }}</p>
        </div>

        <div class="shopify-field">
            <label class="shopify-label" :for="`${prefix}-history`">{{ t('form.historyBefore') }}</label>
            <input :id="`${prefix}-history`" v-model="form.history_before" class="shopify-input" type="date" :disabled="!form.import_orders" />
            <p class="shopify-faint">{{ t('form.historyHint') }}</p>
            <p v-if="form.errors.history_before" class="shopify-error">{{ form.errors.history_before }}</p>
        </div>

        <div class="shopify-field shopify-field--wide">
            <div class="shopify-row">
                <PrimaryButton type="submit" :disabled="form.processing">{{ editing ? t('form.save') : t('form.saveAndTest') }}</PrimaryButton>
                <SecondaryButton type="button" @click="emit('done')">{{ t('form.cancel') }}</SecondaryButton>
            </div>
        </div>
    </form>
</template>
