<script setup>
// The plugin's settings page, rendered by register_page('starter.settings').
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
    title: { type: String, default: 'Starter' },
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
        <div class="starter-page">
            <PageHeader :title="t('settings.title')" :description="t('settings.description')" />

            <p class="starter-greeting">{{ settings.greeting }}</p>

            <Card>
                <form class="starter-form" @submit.prevent="submit">
                    <div class="starter-field">
                        <label for="starter-greeting" class="starter-label">{{ t('settings.greeting') }}</label>
                        <component
                            :is="Input"
                            v-if="Input"
                            id="starter-greeting"
                            v-model="form.greeting"
                            :invalid="Boolean(form.errors.greeting)"
                            maxlength="120"
                        />
                        <input
                            v-else
                            id="starter-greeting"
                            v-model="form.greeting"
                            class="starter-input"
                            type="text"
                            maxlength="120"
                        />
                        <p class="starter-help">{{ t('settings.greetingHelp') }}</p>
                        <p v-if="form.errors.greeting" class="starter-error">{{ form.errors.greeting }}</p>
                    </div>

                    <div class="starter-field starter-check">
                        <input id="starter-log-low-stock" v-model="form.log_low_stock" type="checkbox" />
                        <div>
                            <label for="starter-log-low-stock" class="starter-label">{{ t('settings.logLowStock') }}</label>
                            <p class="starter-help">{{ t('settings.logLowStockHelp') }}</p>
                        </div>
                    </div>

                    <div>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? t('settings.saving') : t('settings.save') }}
                        </Button>
                    </div>
                </form>
            </Card>

            <Card class="starter-activity">
                <h2 class="starter-heading">{{ t('settings.activity') }}</h2>
                <ul v-if="hasActivity" class="starter-list">
                    <li v-for="line in activity" :key="line.id">
                        <span class="starter-type">{{ line.type }}</span>
                        <span>{{ line.message }}</span>
                        <time :datetime="line.created_at" class="starter-help">{{ formatDate(line.created_at) }}</time>
                    </li>
                </ul>
                <p v-else class="starter-help">{{ t('settings.noActivity') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>

<style scoped>
/* The app's Tailwind build does not scan plugins: style with the design
   tokens (HSL triplets that follow the light and dark theme). */
.starter-page {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.starter-greeting {
    color: hsl(var(--text-secondary));
}
.starter-form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-width: 36rem;
}
.starter-field {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}
.starter-check {
    flex-direction: row;
    align-items: flex-start;
    gap: 0.75rem;
}
.starter-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: hsl(var(--text-primary));
}
.starter-input {
    border: 1px solid hsl(var(--border-subtle));
    background: hsl(var(--surface-canvas));
    color: hsl(var(--text-primary));
    border-radius: 0.375rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}
.starter-input:focus {
    outline: 2px solid hsl(var(--ring));
    outline-offset: 1px;
}
.starter-help {
    font-size: 0.75rem;
    color: hsl(var(--text-tertiary));
}
.starter-error {
    font-size: 0.75rem;
    color: hsl(var(--status-danger));
}
.starter-heading {
    font-size: 0.875rem;
    font-weight: 600;
    color: hsl(var(--text-primary));
    margin-bottom: 0.75rem;
}
.starter-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: hsl(var(--text-primary));
}
.starter-list li {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: baseline;
}
.starter-type {
    font-size: 0.75rem;
    padding: 0 0.375rem;
    border-radius: 0.25rem;
    background: hsl(var(--accent-soft));
    color: hsl(var(--text-secondary));
}
</style>
