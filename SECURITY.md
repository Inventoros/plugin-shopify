# Security policy

## Supported versions

The latest 1.x release of the Shopify plugin receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Email **security@inventoros.com** with a description, the steps to reproduce and the versions of the plugin and Inventoros you used. We acknowledge reports within three working days and tell you when a fix is released.

## What the plugin guards

- Shopify access tokens and API secrets are stored encrypted with the application key, hidden from serialization, scrubbed from every log and error message, and never sent to the browser.
- Webhooks are accepted only with a valid HMAC-SHA256 signature over the raw body, from the connection's own shop; anything else is refused before it is stored. Duplicate and replayed deliveries are dropped.
- Shop domains must be `*.myshopify.com`, so the plugin only ever calls Shopify's own hosts.
- Every page and action checks `shopify.view` or `shopify.manage` on the server, and every query is scoped to the user's organization; another organization's ids answer 404.
- Failed work can only be retried as one of this plugin's own jobs, for its own connection.
- Core records change only through core services (`OrderService`, `StockAdjustment::adjust()`), inside transactions.
