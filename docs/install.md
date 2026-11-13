# Install

## Install

From a release: download `shopify-X.Y.Z.zip` from the [releases](../../../releases), upload it in **Plugins**, then activate it. Or install it from **Plugins > Marketplace** once it is listed.

From source:

```bash
cd ui && npm ci && npm run build && cd ..
cp -r . /path/to/inventoros/plugins/shopify     # the folder name is the slug
php artisan plugin:activate shopify              # from the Inventoros root
```

Activation creates the plugin's `plg_shopify_*` tables. Deactivation keeps them. Deleting the plugin drops them and removes its permissions from roles; orders it imported and stock changes it made are core records and stay.

The plugin needs the Inventoros **queue worker** and **scheduler** running (`shopify:sync` every 15 minutes, `shopify:prune` daily). On cPanel the scheduler drains the queue too.

## Set up a store

Install a Shopify app with the scopes `read_products`, `write_products`, `read_inventory`, `write_inventory`, `read_locations` and `read_orders`, then connect under **Shopify > Connect a store** using client credentials for an app and store in your own Shopify organization, or an existing Admin API access token and API secret key. Map locations to warehouses, run a full sync and confirm the SKU suggestions.

The step-by-step guide, with what each status means: **[docs/setup.md](setup.md)**. How orders, stock, products, webhooks and retries behave: **[docs/sync.md](sync.md)**.
