// Runtime UI bundle of the Shopify plugin.
//
// Inventoros imports dist/plugin.js while the plugin is active and calls this
// default export with an SDK scoped to the plugin (see the core guide,
// docs/PLUGIN_DEVELOPMENT.md, "The runtime bundle").
import Overview from './pages/Overview.vue';
import Mappings from './pages/Mappings.vue';
import Activity from './pages/Activity.vue';
import { installMessages } from './i18n.js';
import './styles.css';

export default function setup(plugin) {
    installMessages(plugin);

    // Supply the pages register_page() names in ShopifyPlugin.php.
    plugin.registerPage('Overview', Overview);
    plugin.registerPage('Mappings', Mappings);
    plugin.registerPage('Activity', Activity);
}
