<script setup>
// The plugin's settings page, rendered by register_page('shopify.settings').
// Layout, building blocks and Inertia come from the app (window.Inventoros),
// so the page looks and behaves like a core page and shares its state.
import { computed } from 'vue';
import { t } from '../i18n.js';

const {
    layouts: { AppLayout },
    ui,
    Inertia: { Head, useForm },
} = window.Inventoros;

const { PageHeader, Card, Button } = ui;
// Newer cores share their form input; older ones get a native input styled
// with the same design tokens.
const Input = ui.Input ?? null;

const props = defineProps({
    title: { type: String, default: 'Shopify' },
    settings: { type: Object, required: true },
    activity: { type: Array, default: () => [] },
    updateUrl: { type: String, required: true },
});

const form = useForm({
    greeting: props.settings.greeting ?? '',
    log_low_stock: Boolean(props.settings.log_low_stock),
});

const submit = () => form.put(props.updateUrl, { preserveScroll: true });

const formatDate = (iso) =>
    iso ? new Date(iso).toLocaleString(document.documentElement.lang || undefined) : '';

const hasActivity = computed(() => props.activity.length > 0);
</script>

<template>
    <Head :title="title" />
    <AppLayout>
        <div class="shopify-page">
            <PageHeader :title="t('settings.title')" :description="t('settings.description')" />

            <p class="shopify-greeting">{{ settings.greeting }}</p>

            <Card>
                <form class="shopify-form" @submit.prevent="submit">
                    <div class="shopify-field">
                        <label for="shopify-greeting" class="shopify-label">{{ t('settings.greeting') }}</label>
                        <component
                            :is="Input"
                            v-if="Input"
                            id="shopify-greeting"
                            v-model="form.greeting"
                            :invalid="Boolean(form.errors.greeting)"
                            maxlength="120"
                        />
                        <input
                            v-else
                            id="shopify-greeting"
                            v-model="form.greeting"
                            class="shopify-input"
                            type="text"
                            maxlength="120"
                        />
                        <p class="shopify-help">{{ t('settings.greetingHelp') }}</p>
                        <p v-if="form.errors.greeting" class="shopify-error">{{ form.errors.greeting }}</p>
                    </div>

                    <div class="shopify-field shopify-check">
                        <input id="shopify-log-low-stock" v-model="form.log_low_stock" type="checkbox" />
                        <div>
                            <label for="shopify-log-low-stock" class="shopify-label">{{ t('settings.logLowStock') }}</label>
                            <p class="shopify-help">{{ t('settings.logLowStockHelp') }}</p>
                        </div>
                    </div>

                    <div>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? t('settings.saving') : t('settings.save') }}
                        </Button>
                    </div>
                </form>
            </Card>

            <Card class="shopify-activity">
                <h2 class="shopify-heading">{{ t('settings.activity') }}</h2>
                <ul v-if="hasActivity" class="shopify-list">
                    <li v-for="line in activity" :key="line.id">
                        <span class="shopify-type">{{ line.type }}</span>
                        <span>{{ line.message }}</span>
                        <time :datetime="line.created_at" class="shopify-help">{{ formatDate(line.created_at) }}</time>
                    </li>
                </ul>
                <p v-else class="shopify-help">{{ t('settings.noActivity') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>

<style scoped>
/* The app's Tailwind build does not scan plugins: style with the design
   tokens (HSL triplets that follow the light and dark theme). */
.shopify-page {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.shopify-greeting {
    color: hsl(var(--text-secondary));
}
.shopify-form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-width: 36rem;
}
.shopify-field {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}
.shopify-check {
    flex-direction: row;
    align-items: flex-start;
    gap: 0.75rem;
}
.shopify-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: hsl(var(--text-primary));
}
.shopify-input {
    border: 1px solid hsl(var(--border-subtle));
    background: hsl(var(--surface-canvas));
    color: hsl(var(--text-primary));
    border-radius: 0.375rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}
.shopify-input:focus {
    outline: 2px solid hsl(var(--ring));
    outline-offset: 1px;
}
.shopify-help {
    font-size: 0.75rem;
    color: hsl(var(--text-tertiary));
}
.shopify-error {
    font-size: 0.75rem;
    color: hsl(var(--status-danger));
}
.shopify-heading {
    font-size: 0.875rem;
    font-weight: 600;
    color: hsl(var(--text-primary));
    margin-bottom: 0.75rem;
}
.shopify-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: hsl(var(--text-primary));
}
.shopify-list li {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: baseline;
}
.shopify-type {
    font-size: 0.75rem;
    padding: 0 0.375rem;
    border-radius: 0.25rem;
    background: hsl(var(--accent-soft));
    color: hsl(var(--text-secondary));
}
</style>
