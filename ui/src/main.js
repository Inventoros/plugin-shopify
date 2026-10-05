// Runtime UI bundle of the Starter plugin.
//
// Inventoros imports dist/plugin.js while the plugin is active and calls this
// default export with an SDK scoped to the plugin (see the core guide,
// docs/PLUGIN_DEVELOPMENT.md, "The runtime bundle").
import Settings from './pages/Settings.vue';
import { installMessages } from './i18n.js';

export default function setup(plugin) {
    installMessages(plugin);

    // Supplies 'Plugin::starter/Settings' for register_page() in StarterPlugin.php.
    plugin.registerPage('Settings', Settings);
}
