# Sync options

## License

AGPL-3.0-or-later. See [LICENSE](../LICENSE).

### GraphQL page sizes and rate limits

Catalog and order reads use small parent pages and finish nested variant, order
line and inventory-location connections before returning a record. Large
products or orders are not silently truncated. A missing/repeated cursor refuses
partial data. Read-only requests can wait on the same page up to three times,
with a total wait of at most 30 seconds; longer throttles return to the existing
queue retry policy. Mutations keep their existing idempotency and queue retries.

Query shapes are bounded below Shopify's default 1,000-point single-query limit.
Shopify can assign field costs, so response throttle metadata remains authoritative.

### Client credentials and missing scopes

Choose **App client credentials** for apps and stores in the same Shopify
organization. Enter the app client ID and API secret key (its client secret).
The server exchanges these for an expiring token, keeps it encrypted alongside
its expiry, and renews it two minutes before expiration before each API request, including later pages of an already-running client.
A connection-row lock prevents concurrent workers from minting duplicate tokens.
Manual tokens remain supported; they are never replaced automatically.

Connection tests name missing scopes for the enabled sync directions. Updating
app permissions may require reinstalling/reauthorizing the app before testing
again. Secrets and client IDs are write-only and excluded from validation-session
old input. Failed token exchanges report sanitized errors without provider bodies.
No OAuth redirect/authorization-code flow is provided by this option.

See [Shopify client credentials](https://shopify.dev/docs/apps/build/authentication-authorization/client-credentials-grant)
and [token lifetimes](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens).

### Historical order backfills

An optional **Import orders before this date as history** cutoff separates past
sales from new stock-moving orders. Set the import start date to the earliest
order you want, select the history cutoff, test the connection, then run a full
sync. Dates use UTC. Shopify access beyond 60 days requires approved
`read_all_orders` access as well as order access; connection tests name it when
an older start date is configured.

Orders before the cutoff use core's historical import path: no stock/bin/serial
allocation, reservations, approval requests or new-order announcements. Fulfilled
orders are recorded as shipped, cancelled orders as cancelled, and other orders
as pending; exact remote statuses remain in metadata. No shipments are created.
Subsequent updates and cancellations remain historical even if the cutoff changes,
and never restock the goods. Orders on/after the cutoff keep the normal stock path.

Variant mappings are still required. Orders skipped by the start-date filter in
this version can be revisited as history after changing the range. Manually ignored
records and ignored records with no recorded reason stay ignored. Existing live
orders are never converted into history by changing a setting.

### Payment and refund records

Enable **Record Shopify payments and refunds** after reconciling any manual
payment entries. This optional setting is off by default and requires core's
`OrderPaymentService::recordImported()` API (core PR Inventoros/Inventoros#305).
Older cores retain all existing sync features and show an upgrade hint instead
of enabling payment import. Enabling the setting retests the connection and
registers the refund webhook; scheduled order reconciliation also updates it.

Only successful sales, captures and refunds create ledger records. Pending or
failed transactions and uncaptured authorizations create no receipts. Amounts
and dates come from Shopify transactions in shop currency; a currency mismatch
is refused. There are no calls to capture, charge or refund money in Shopify.
Payment status and balances are derived by core from the imported ledger, never
from a Shopify status label. Actual overpayments remain visible as overpayments.

Provider transaction IDs map to core payment IDs, so repeated webhooks/resyncs
add nothing twice. Conflicting transaction changes and missing mapped payments
need review. Manual voids remain voided. Active manual/unrelated payment rows
block automatic import until reconciled or voided to prevent double counting.
Refunds cannot exceed recorded receipts. Historical imports use the same ledger
path silently, including cancelled orders; live imports announce core payment
hooks only after commit. Importing financial records does not restock returns.

The API reader checks the exact transaction count and refuses incomplete lists
or orders with more than 100 transactions for manual review. It does not
silently import a subset. Financial records and new-order creation are atomic;
an invalid transaction leaves no partial order/payment ledger.

## Shipment fulfilment and tracking

After upgrading the core with shipment notification ownership, grant the custom app `read_merchant_managed_fulfillment_orders` and `write_merchant_managed_fulfillment_orders`, plus `write_inventory` for destination activation, then enable **Push shipments and tracking to Shopify** in the connection settings. This is off by default. Map each ship-from warehouse to exactly one Shopify location.

Newly imported order lines retain their exact Shopify line ids. When an Inventoros shipment is marked shipped, queued work fulfils only those shipped quantities, includes tracking, and lets Shopify send the shipping email if the customer notification option is enabled. The core email is suppressed for those shipments. Older imported orders without exact line mappings must be fulfilled in Shopify. Shipping never deducts Inventoros stock a second time.

If routing differs, the plugin activates previously unstocked inventory items at the destination, without resetting existing quantities, before moving only the shipment’s fulfilment-order lines. Moves re-read Shopify because order and line ids may change. Fulfilment holds and unsupported service requests stop the push for review. Read pages and definite throttles use the existing delayed retries.

Shopify’s [fulfillmentCreate](https://shopify.dev/docs/api/admin-graphql/2026-07/mutations/fulfillmentCreate) does not document a creation idempotency key. The plugin commits a send claim before creation, persists the returned id, and refuses to blindly replay an uncertain response or a killed worker’s claim. Check the failed operation in Sync activity and verify or finish that shipment in Shopify; repeated retries cannot create another fulfilment. This conservative stop also applies to rejected creation responses. [Destination activation](https://shopify.dev/docs/api/admin-graphql/2026-07/mutations/inventoryActivate) uses its required idempotency key; [fulfilment-order moves](https://shopify.dev/docs/api/admin-graphql/2026-07/mutations/fulfillmentOrderMove) require the items to be stocked at the destination.

Historical orders and orders that never committed stock are excluded from shipment push. Fulfilment orders and their lines use bounded, complete read pages; missing/repeated cursors stop partial data before any shipment mutation.
