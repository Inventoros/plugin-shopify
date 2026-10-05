// Plugin strings, translated with the app's own vue-i18n instance when the
// running Inventoros offers it (SDK apiVersion 2: plugin.i18n), else with a
// small fallback that reads the same messages for <html lang>, then English.
//
// Keys are relative to the plugin: t('settings.title') resolves
// plugins.shopify.settings.title in the app's i18n.
import en from './locales/en.json';
import ar from './locales/ar.json';
import de from './locales/de.json';
import es from './locales/es.json';
import fr from './locales/fr.json';
import it from './locales/it.json';
import ja from './locales/ja.json';
import ko from './locales/ko.json';
import nl from './locales/nl.json';
import pl from './locales/pl.json';
import ptBR from './locales/pt-BR.json';
import ru from './locales/ru.json';
import tr from './locales/tr.json';
import zhCN from './locales/zh-CN.json';

const messages = { en, ar, de, es, fr, it, ja, ko, nl, pl, 'pt-BR': ptBR, ru, tr, 'zh-CN': zhCN };

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
