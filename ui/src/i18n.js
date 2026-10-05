// Plugin strings, translated with the app's own vue-i18n instance when the
// running Inventoros offers it (SDK apiVersion 2: plugin.i18n), else with a
// small fallback that reads the same messages for <html lang>, then English.
//
// Keys are relative to the plugin: t('settings.title') resolves
// plugins.shopify.settings.title in the app's i18n.
import en from './locales/en.json';

const messages = { en };

const lookup = (tree, key) =>
    key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), tree);

const interpolate = (text, params = {}) =>
    String(text).replace(/\{(\w+)\}/g, (match, name) => (name in params ? String(params[name]) : match));

let translate = (key, params) => {
    const locale = document.documentElement.lang || 'en';
    const text = lookup(messages[locale], key) ?? lookup(messages.en, key) ?? key;

    return interpolate(text, params);
};

export function installMessages(plugin) {
    if (plugin.i18n && typeof plugin.i18n.addMessages === 'function') {
        Object.entries(messages).forEach(([locale, tree]) => plugin.i18n.addMessages(locale, tree));
        translate = (key, params) => plugin.i18n.t(key, params);
    }
}

export const t = (key, params) => translate(key, params);
