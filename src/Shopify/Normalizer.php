<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Shopify;

/**
 * Turns Shopify records into one shape, whether they came from the GraphQL
 * Admin API (camelCase, gids, MoneyBag objects) or from a webhook (the
 * REST representation: snake_case, numeric ids, money strings).
 *
 * Product:  id, title, status, updated_at, variants[id, product_id, sku,
 *           title, price, barcode, inventory_item_id, options{name: value}]
 * Order:    id, name, created_at, updated_at, cancelled_at, currency,
 *           email, test, note, customer_name, address, financial_status,
 *           fulfillment_status, tax, shipping, discount,
 *           line_items[id, variant_id, product_id, sku, title, quantity,
 *           price, discount]
 */
final class Normalizer
{
    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function product(array $node): array
    {
        $variants = $node['variants']['nodes'] ?? $node['variants'] ?? [];
        $productId = Gid::id($node['id'] ?? null);

        return [
            'id' => $productId,
            'title' => (string) ($node['title'] ?? ''),
            'status' => strtolower((string) ($node['status'] ?? 'active')),
            'updated_at' => $node['updatedAt'] ?? $node['updated_at'] ?? null,
            'variants' => array_values(array_map(fn (array $variant) => self::variant($variant, $productId), is_array($variants) ? $variants : [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    public static function variant(array $variant, ?string $productId = null): array
    {
        $options = [];

        foreach ($variant['selectedOptions'] ?? [] as $option) {
            if (isset($option['name'], $option['value'])) {
                $options[(string) $option['name']] = (string) $option['value'];
            }
        }

        foreach (['option1', 'option2', 'option3'] as $index => $key) {
            if (isset($variant[$key]) && $variant[$key] !== null) {
                $options['Option '.($index + 1)] = (string) $variant[$key];
            }
        }

        $sku = trim((string) ($variant['sku'] ?? ''));

        return [
            'id' => Gid::id($variant['id'] ?? null),
            'product_id' => Gid::id($variant['product_id'] ?? $variant['product']['id'] ?? $productId),
            'sku' => $sku === '' ? null : $sku,
            'title' => (string) ($variant['title'] ?? ''),
            'price' => self::money($variant['price'] ?? null),
            'barcode' => isset($variant['barcode']) && $variant['barcode'] !== '' ? (string) $variant['barcode'] : null,
            'inventory_item_id' => Gid::id($variant['inventoryItem']['id'] ?? $variant['inventory_item_id'] ?? null),
            'options' => $options,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function order(array $node): array
    {
        $lines = $node['lineItems']['nodes'] ?? $node['line_items'] ?? [];

        $shipping = $node['totalShippingPriceSet'] ?? null;
        if ($shipping === null && isset($node['shipping_lines']) && is_array($node['shipping_lines'])) {
            $shipping = array_sum(array_map(fn ($line) => (float) ($line['price'] ?? 0), $node['shipping_lines']));
        }

        $customer = $node['customer'] ?? [];
        $name = trim(((string) ($customer['firstName'] ?? $customer['first_name'] ?? '')).' '.((string) ($customer['lastName'] ?? $customer['last_name'] ?? '')));

        return [
            'id' => Gid::id($node['id'] ?? null),
            'name' => (string) ($node['name'] ?? ''),
            'created_at' => $node['createdAt'] ?? $node['created_at'] ?? null,
            'updated_at' => $node['updatedAt'] ?? $node['updated_at'] ?? null,
            'cancelled_at' => $node['cancelledAt'] ?? $node['cancelled_at'] ?? null,
            'currency' => strtoupper((string) ($node['currencyCode'] ?? $node['currency'] ?? '')),
            'email' => $node['email'] ?? null,
            'test' => (bool) ($node['test'] ?? false),
            'note' => $node['note'] ?? null,
            'customer_name' => $name !== '' ? $name : null,
            'address' => self::address($node['shippingAddress'] ?? $node['shipping_address'] ?? null),
            'financial_status' => strtolower((string) ($node['displayFinancialStatus'] ?? $node['financial_status'] ?? '')),
            'fulfillment_status' => strtolower((string) ($node['displayFulfillmentStatus'] ?? $node['fulfillment_status'] ?? '')),
            'tax' => self::money($node['totalTaxSet'] ?? $node['total_tax'] ?? null),
            'shipping' => self::money($shipping),
            'discount' => self::money($node['totalDiscountsSet'] ?? $node['total_discounts'] ?? null),
            'line_items' => array_values(array_map([self::class, 'lineItem'], is_array($lines) ? $lines : [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function lineItem(array $line): array
    {
        $sku = trim((string) ($line['sku'] ?? ''));

        return [
            'id' => Gid::id($line['id'] ?? null),
            'variant_id' => Gid::id($line['variant']['id'] ?? $line['variant_id'] ?? null),
            'product_id' => Gid::id($line['product']['id'] ?? $line['product_id'] ?? null),
            'sku' => $sku === '' ? null : $sku,
            'title' => (string) ($line['name'] ?? $line['title'] ?? ''),
            'quantity' => (int) ($line['quantity'] ?? 0),
            'price' => self::money($line['originalUnitPriceSet'] ?? $line['price'] ?? null),
            'discount' => self::money($line['totalDiscountSet'] ?? $line['total_discount'] ?? null),
        ];
    }

    /**
     * A decimal string from a money string, number, Money object or MoneyBag.
     */
    public static function money(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['shopMoney']['amount'] ?? $value['amount'] ?? 0;
        }

        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private static function address(?array $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $parts = [
            $address['address1'] ?? null,
            $address['address2'] ?? null,
            $address['city'] ?? null,
            $address['provinceCode'] ?? $address['province_code'] ?? $address['province'] ?? null,
            $address['zip'] ?? null,
            $address['countryCodeV2'] ?? $address['country_code'] ?? $address['country'] ?? null,
        ];

        $text = implode(', ', array_filter(array_map(fn ($part) => is_string($part) ? trim($part) : null, $parts)));

        return $text === '' ? null : $text;
    }
}
