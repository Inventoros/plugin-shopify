# Setting up the Shopify connection

This guide connects one Shopify store to one Inventoros organization with a **custom app** and its Admin API access token. You need to be a Shopify store owner or a staff member allowed to develop apps, and an Inventoros user with **Manage Shopify sync** (or **Edit Settings** on cores without plugin permissions).

## Contents

1. [Create the custom app in Shopify](#1-create-the-custom-app-in-shopify)
2. [Connect it in Inventoros](#2-connect-it-in-inventoros)
3. [Map locations to warehouses](#3-map-locations-to-warehouses)
4. [Run the first sync and review mappings](#4-run-the-first-sync-and-review-mappings)
5. [Check that webhooks arrive](#5-check-that-webhooks-arrive)
6. [Reconnecting, rotating the token, removing the store](#6-reconnecting-rotating-the-token-removing-the-store)
7. [Future: a public app with OAuth](#7-future-a-public-app-with-oauth)

## 1. Create the custom app in Shopify

1. In the Shopify admin, open **Settings > Apps and sales channels > Develop apps**. If app development is not enabled yet, choose **Allow custom app development** (store owners only).
2. Choose **Create an app**, name it (for example *Inventoros*) and pick yourself as the developer.
3. Open **Configuration > Admin API integration > Configure** and select these scopes:

   | Scope | Why |
   |-------|-----|
   | `read_products` | Read products and variants to map them by SKU |
   | `write_products` | Push titles and prices, only when Inventoros owns products |
   | `read_inventory` | Read inventory levels |
   | `write_inventory` | Push stock levels, when Inventoros owns stock |
   | `read_locations` | Read your locations to map them to warehouses |
   | `read_orders` | Import orders (Shopify serves the last 60 days; add `read_all_orders` if you import older ones) |

4. Save, open **API credentials** and choose **Install app**.
5. Reveal and copy the **Admin API access token** (it starts with `shpat_` and is shown once).
6. On the same page, copy the **API secret key**. Shopify signs every webhook with it; Inventoros refuses any webhook it cannot verify.

If your store no longer offers custom app development in the admin, create the app in the Shopify Dev Dashboard instead, give it the same scopes, install it on the store, and use its Admin API access token and client secret in the same two fields.

## 2. Connect it in Inventoros

Open **Shopify** in the sidebar (`/p/shopify`) and fill in **Connect a store**:

| Field | Value |
|-------|-------|
| Name | Anything that tells your stores apart |
| Shop domain | `your-store.myshopify.com` (the handle alone, `your-store`, also works). Custom storefront domains are not Admin API hosts and are refused. |
| Admin API access token | The `shpat_...` token |
| API secret key | The secret from step 1.6 |
| Who owns stock levels | **Inventoros** pushes Inventoros quantities to Shopify; **Shopify** applies Shopify's quantities to Inventoros. Pick one owner. |
| Who owns product names and prices | **Shopify** copies Shopify edits into Inventoros; **Inventoros** pushes Inventoros edits to Shopify. |
| Import Shopify orders | On by default |
| Import orders placed on or after | Today by default, so old orders are not imported by accident |

**Save and test** stores the token and secret encrypted and queues a connection test. The page never waits on Shopify: refresh after a few seconds. When the test passes the store shows **Healthy**, the plugin registers its webhooks and reads your locations. If it fails, the card shows why (a wrong token, missing scopes, a token for another store).

The background work needs the Inventoros queue worker and scheduler running (on cPanel the scheduler also drains the queue; see the core's CPANEL.md).

## 3. Map locations to warehouses

Open **Mappings > Locations**. For each Shopify location choose:

- a **warehouse**: its quantity is the product's stock in that warehouse's bins, or
- **All stock**: the product's total across every warehouse (a store with one location that sells everything you hold).

Mark one location **Primary**. Variants are pushed there (core keeps variant stock without a per-location breakdown) and imported orders are placed in its warehouse. A store with one location and an organization with one warehouse is mapped automatically.

## 4. Run the first sync and review mappings

On the store card choose **Run full sync**. It reads locations, products, stock and orders, in that order, and records each step under **Activity**.

Then open **Mappings > Products**:

| Status | Meaning | What to do |
|--------|---------|------------|
| Suggested | Exactly one Inventoros product or variant has the same SKU | **Confirm**, or **Confirm all suggestions** |
| Unmatched | No SKU in Shopify, or no Inventoros record with it | Type an Inventoros SKU and **Link**, or **Ignore** |
| Conflict | Several Inventoros records share the SKU, or another Shopify variant already holds the match | Fix the SKUs, **Ignore** the wrong side, then **Link** the right one |
| Mapped | Confirmed: stock and orders sync | **Resync** pushes its stock now |
| Ignored | Never synced | **Match again** to reconsider it |

Nothing syncs for a product until its mapping is confirmed: suggestions are never acted on by themselves. Confirming queues a stock push for that product.

## 5. Check that webhooks arrive

Shopify can only deliver webhooks to a public `https` address. The store card shows the webhook address (`https://your-inventoros/webhooks/plugins/shopify/{connection}`) and whether webhooks were registered. On a local or plain-http install they are not registered, and the scheduled sync (every 15 minutes) picks up order and product changes instead.

Place a test order in Shopify (enable **Also import test orders** first, or use a real low-value order) and check **Activity**: a `webhook` run for `orders/create` should appear within seconds, and the order under **Orders** with source *shopify*.

## 6. Reconnecting, rotating the token, removing the store

- **Rotate the token or secret**: open **Settings** on the store card and paste the new values. Blank fields keep the saved ones. Changing a credential re-runs the connection test.
- **The app is uninstalled in Shopify**: the store turns **Disconnected**, its credentials are deleted and syncing stops. Mappings and history are kept. Install the app again and reconnect the store under a new connection, or delete the old one.
- **Disconnect** deletes the connection with its mappings, history and queued work. Orders it imported and stock changes it made are core Inventoros records and stay.

## 7. Future: a public app with OAuth

Version 1 connects with a custom app's access token, one store at a time. A public Shopify app installed through OAuth (an install link, offline access tokens, Shopify's mandatory privacy webhooks) is planned; the connection model already keeps credentials as an encrypted set so an OAuth token can replace the custom app token without a data migration.

### Optional shipment sync

To push shipped parcels and tracking, upgrade Inventoros with shipment notification ownership, add `read_merchant_managed_fulfillment_orders` and `write_merchant_managed_fulfillment_orders` to the Shopify custom app, and enable shipment push in the connection settings. Keep one Shopify location per ship-from warehouse. Shopify owns customer shipping emails while this option is enabled, including while sync is paused. Resume sync to process queued shipments. Orders imported before exact line mappings were stored require manual fulfilment in Shopify. See the README for uncertain-response recovery.
