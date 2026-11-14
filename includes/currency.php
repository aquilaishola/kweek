<?php

define('USD_LINKS_ENABLED', true);
define('GHS_LINKS_ENABLED', true);

/**
 * Payment methods offered per foreign currency (Bachs corridor names).
 * Restricting them keeps the charge in the quoted currency, so the amount the
 * gateway reports always matches the amount we quoted. Use an empty array to
 * leave the gateway's defaults untouched.
 */
define('USD_PAYMENT_METHODS', ['USD_CARD']);
define('GHS_PAYMENT_METHODS', ['MOMO_GHS']);

/**
 * Everything we know about each currency in one place.
 * 'min' is the smallest order we send to checkout. The USD and GHS figures are
 * starting points; please confirm them with your gateway.
 */
const KWEEK_CURRENCIES = [
    'NGN' => ['symbol' => '₦',   'icon' => 'ti-currency-naira',  'label' => 'Naira (₦)',        'noun' => 'naira',   'min' => 1000],
    'USD' => ['symbol' => '$',   'icon' => 'ti-currency-dollar', 'label' => 'US Dollar ($)',    'noun' => 'dollars', 'min' => 1],
    'GHS' => ['symbol' => 'GH₵', 'icon' => 'ti-coin',            'label' => 'Ghana Cedi (GH₵)', 'noun' => 'cedis',   'min' => 10],
];

function knownCurrencies(): array {
    return array_keys(KWEEK_CURRENCIES);
}

/**
 * Currency of an existing link or order. Deliberately ignores the switches
 * and rates, so a USD or GHS link always keeps displaying correctly.
 */
function linkCurrency($value): string {
    $c = strtoupper((string) $value);
    return isset(KWEEK_CURRENCIES[$c]) ? $c : 'NGN';
}

function currencySymbol(string $currency): string { return KWEEK_CURRENCIES[linkCurrency($currency)]['symbol']; }
function currencyIcon(string $currency): string   { return KWEEK_CURRENCIES[linkCurrency($currency)]['icon']; }
function currencyLabel(string $currency): string  { return KWEEK_CURRENCIES[linkCurrency($currency)]['label']; }
function currencyNoun(string $currency): string   { return KWEEK_CURRENCIES[linkCurrency($currency)]['noun']; }

/** Formats an amount with the right symbol, e.g. ₦1,500.00, $12.00 or GH₵12.00. */
function formatMoney($amount, string $currency = 'NGN'): string {
    return currencySymbol($currency) . number_format((float) $amount, 2);
}

/** The smallest amount we will send to checkout, per currency. */
function minAmountFor(string $currency): int {
    return KWEEK_CURRENCIES[linkCurrency($currency)]['min'];
}

/** Payment methods to request from the gateway for this currency (empty = gateway default). */
function paymentMethodsFor(string $currency): array {
    return match (linkCurrency($currency)) {
        'USD'   => USD_PAYMENT_METHODS,
        'GHS'   => GHS_PAYMENT_METHODS,
        default => [],
    };
}

// ── EXCHANGE RATES (naira per 1 unit of the currency) ────────────────────

/** Naira per 1 unit of $currency, as set by the admin. NGN is always 1; 0.0 means "not set". */
function ngnRate(string $currency, bool $refresh = false): float {
    $currency = linkCurrency($currency);
    if ($currency === 'NGN') return 1.0;

    static $cache = [];
    if (!$refresh && isset($cache[$currency])) return $cache[$currency];

    $cache[$currency] = 0.0;
    try {
        $stmt = db()->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([strtolower($currency) . '_ngn_rate']);
        $value = (float) $stmt->fetchColumn();
        if ($value > 0) $cache[$currency] = $value;
    } catch (Throwable $e) {
        // Most likely the platform_settings table hasn't been created yet.
        error_log('ngnRate(' . $currency . '): ' . $e->getMessage());
    }
    return $cache[$currency];
}

/** Saves the admin's rate. Returns false for values that look like a typo. */
function setNgnRate(string $currency, float $rate, ?int $adminId = null): bool {
    $currency = linkCurrency($currency);
    if ($currency === 'NGN' || $rate < 1 || $rate > 100000) return false;

    $stmt = db()->prepare("
        INSERT INTO platform_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ");
    return $stmt->execute([strtolower($currency) . '_ngn_rate', number_format($rate, 4, '.', ''), $adminId]);
}

// Kept so existing code (admin settings page, older pages) keeps working.
function usdNgnRate(bool $refresh = false): float          { return ngnRate('USD', $refresh); }
function setUsdNgnRate(float $rate, ?int $adminId = null): bool { return setNgnRate('USD', $rate, $adminId); }

/** Naira value of an amount in the given currency, at the current rate, rounded to kobo. */
function toNaira(float $amount, string $currency): float {
    return round($amount * ngnRate($currency), 2);
}
function usdToNgn(float $usd): float { return toNaira($usd, 'USD'); }

/** Currencies a merchant may choose when creating a new link. */
function supportedCurrencies(): array {
    $list = ['NGN'];
    if (USD_LINKS_ENABLED && ngnRate('USD') > 0) $list[] = 'USD';
    if (GHS_LINKS_ENABLED && ngnRate('GHS') > 0) $list[] = 'GHS';
    return $list;
}

/** Symbols, icons, minimums and live rates in one array, ready for json_encode() in a page's script. */
function currencyJsMap(): array {
    $map = ['symbols' => [], 'icons' => [], 'mins' => [], 'rates' => []];
    foreach (KWEEK_CURRENCIES as $code => $c) {
        $map['symbols'][$code] = $c['symbol'];
        $map['icons'][$code]   = $c['icon'];
        $map['mins'][$code]    = $c['min'];
        $map['rates'][$code]   = ngnRate($code);
    }
    return $map;
}

// ── MERCHANT-SIDE VIEW OF AN ORDER (always naira) ────────────────────────

/**
 * What an order is worth to the merchant, in naira: the naira amount locked
 * when a dollar/cedi order was placed, or simply the total for a naira order.
 * $order is a row from `orders`.
 */
function orderNgnAmount(array $order): float {
    $c = linkCurrency($order['currency'] ?? 'NGN');
    return $c === 'NGN'
        ? (float) ($order['total_amount'] ?? 0)
        : (float) ($order['ngn_amount'] ?? 0);
}

/**
 * What the buyer actually paid, e.g. "$10.00", for a dollar or cedi order.
 * Null for a naira order, so callers can simply skip it.
 */
function orderPaidInLabel(array $order): ?string {
    $c = linkCurrency($order['currency'] ?? 'NGN');
    return $c === 'NGN' ? null : formatMoney((float) ($order['total_amount'] ?? 0), $c);
}

/**
 * SQL expression for an order's naira value, for SUM() and ORDER BY.
 * Pass the table alias with a trailing dot (e.g. 'o.'), or nothing if the
 * query has no alias.
 */
function ngnAmountSql(string $alias = ''): string {
    return "CASE WHEN COALESCE({$alias}currency,'NGN') NOT IN ('NGN','') THEN COALESCE({$alias}ngn_amount,0) ELSE {$alias}total_amount END";
}