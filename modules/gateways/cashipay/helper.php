<?php
/**
 * CashiPay Gateway Module for WHMCS
 *
 * Shared helper functions used by the gateway module, its hosted pay page,
 * QR endpoint, status endpoint, and callback file.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Base API url, no trailing slash.
 *
 * @param array $params Gateway configuration parameters
 *
 * @return string
 */
function cashipay_base_url($params)
{
    return rtrim($params['baseUrl'], '/');
}

/**
 * Perform an authenticated request against the CashiPay API.
 *
 * @param array  $params Gateway configuration parameters
 * @param string $method HTTP method
 * @param string $path   Path relative to the base url, no leading slash
 * @param array  $body   Request body, only used for POST/PUT
 *
 * @return array|null Decoded JSON response, null on failure
 */
function cashipay_request($params, $method, $path, $body = null)
{
    $url = cashipay_base_url($params) . '/' . ltrim($path, '/');

    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $params['apiKey'],
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        // CashiPay's edge (AWS ALB/WAF) returns a bare 403 for requests with no
        // User-Agent header - PHP's cURL sends none by default.
        CURLOPT_USERAGENT      => 'WHMCS-CashiPay-Gateway/1.0',
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logTransaction('CashiPay', ['error' => $error, 'url' => $url], 'API Request Failed');

        return null;
    }

    $decoded = json_decode($response, true);

    if ($status < 200 || $status >= 300) {
        logTransaction('CashiPay', ['status' => $status, 'body' => $response, 'url' => $url], 'API Request Failed');

        return null;
    }

    return $decoded;
}

/**
 * Create a payment request on the CashiPay side.
 *
 * @param array $params  Gateway configuration parameters
 * @param array $payload
 *
 * @return array|null
 */
function cashipay_create_payment_request($params, $payload)
{
    return cashipay_request($params, 'POST', 'payment-requests', $payload);
}

/**
 * Fetch the current status of a payment request.
 *
 * @param array  $params          Gateway configuration parameters
 * @param string $referenceNumber
 *
 * @return array|null
 */
function cashipay_get_payment_status($params, $referenceNumber)
{
    return cashipay_request($params, 'GET', 'payment-requests/' . rawurlencode($referenceNumber));
}

/**
 * Map a raw CashiPay status string to a boolean paid/not-paid state.
 *
 * @param string $status
 *
 * @return string|null 'paid', 'failed', 'expired' or null when still pending/unknown
 */
function cashipay_map_status($status)
{
    switch (strtoupper((string) $status)) {
        case 'COMPLETED':
        case 'PAID':
        case 'SUCCESS':
        case 'APPROVED':
            return 'paid';
        case 'FAILED':
        case 'REJECTED':
        case 'CANCELLED':
            return 'failed';
        case 'EXPIRED':
            return 'expired';
        default:
            return null;
    }
}

/**
 * Ensure the table used to persist CashiPay payment request data (namely the QR code, which
 * CashiPay's API only returns once, at creation time) exists.
 *
 * @return void
 */
function cashipay_ensure_storage_table()
{
    if (!Capsule::schema()->hasTable('mod_cashipay_requests')) {
        Capsule::schema()->create('mod_cashipay_requests', function ($table) {
            $table->increments('id');
            $table->integer('invoice_id');
            $table->string('reference_number', 50)->unique();
            $table->longText('qr_data_url')->nullable();
            $table->string('qr_content', 255)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 10)->default('SDG');
            $table->dateTime('expires_at')->nullable();
            $table->boolean('payment_recorded')->default(false);
            $table->dateTime('date_created');
        });
    }
}

/**
 * Persist a CashiPay payment request's QR code and metadata, keyed by reference number.
 *
 * @param array $data
 *
 * @return void
 */
function cashipay_store_payment_request($data)
{
    cashipay_ensure_storage_table();

    // insertOrIgnore relies on the reference_number unique key rather than a separate
    // SELECT-then-INSERT check, so two near-simultaneous calls for the same reference
    // (e.g. a double-clicked "Pay" button) can't race each other into a duplicate-key
    // database error - the second call is simply a no-op.
    Capsule::table('mod_cashipay_requests')->insertOrIgnore([
        'invoice_id'        => $data['invoice_id'],
        'reference_number'  => $data['reference_number'],
        'qr_data_url'       => $data['qr_data_url'],
        'qr_content'        => $data['qr_content'],
        'amount'            => $data['amount'],
        'currency'          => $data['currency'],
        'expires_at'        => !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null,
        'date_created'      => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Retrieve a stored CashiPay payment request by reference number.
 *
 * @param string $referenceNumber
 *
 * @return object|null
 */
function cashipay_get_stored_payment_request($referenceNumber)
{
    cashipay_ensure_storage_table();

    return Capsule::table('mod_cashipay_requests')
        ->where('reference_number', $referenceNumber)
        ->first();
}

/**
 * Atomically claim the right to record a "paid" payment for this reference number.
 *
 * The webhook, the client-side status poll, and the return-url landing page can all
 * independently observe a "paid" status for the same reference at nearly the same time.
 * A plain "does a payment record already exist?" check before inserting is a check-then-act
 * race - two callers can both see zero rows and both go on to record the payment,
 * double-crediting the invoice. This flips a payment_recorded flag with the guard in the
 * WHERE clause of the UPDATE itself, so only the caller whose UPDATE actually affects a row
 * is allowed to proceed with addInvoicePayment(); every other concurrent caller gets 0
 * affected rows and backs off.
 *
 * @param string $referenceNumber
 *
 * @return bool True if this call won the race and should proceed to record the payment
 */
function cashipay_claim_payment_recording($referenceNumber)
{
    cashipay_ensure_storage_table();

    $affected = Capsule::table('mod_cashipay_requests')
        ->where('reference_number', $referenceNumber)
        ->where('payment_recorded', 0)
        ->update(['payment_recorded' => 1]);

    if ($affected > 0) {
        return true;
    }

    // No stored row for this reference - fall back to an atomic insert-based claim keyed by
    // the same unique index.
    if (!cashipay_get_stored_payment_request($referenceNumber)) {
        try {
            Capsule::table('mod_cashipay_requests')->insert([
                'reference_number' => $referenceNumber,
                'invoice_id'       => 0,
                'payment_recorded' => 1,
                'date_created'     => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    return false;
}

/**
 * Resolve the currency code (e.g. "USD") for the client who owns the given invoice.
 *
 * WHMCS invoices do not carry their own currency column - currency is set per-client
 * (tblclients.currency, a foreign key into tblcurrencies) and every invoice inherits its
 * owning client's currency.
 *
 * @param int $invoiceId
 *
 * @return string|null Null if the invoice, client, or currency record cannot be found
 */
function cashipay_get_invoice_currency_code($invoiceId)
{
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

    if (!$invoice) {
        return null;
    }

    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();

    if (!$client) {
        return null;
    }

    $currency = Capsule::table('tblcurrencies')->where('id', $client->currency)->first();

    return $currency ? $currency->code : null;
}

/**
 * All currencies configured in WHMCS except SDG itself (nothing to convert there).
 *
 * @return \Illuminate\Support\Collection
 */
function cashipay_get_convertible_currencies()
{
    return Capsule::table('tblcurrencies')
        ->whereRaw('UPPER(code) != ?', ['SDG'])
        ->orderBy('code')
        ->get();
}

/**
 * The gateway config field name used to store a given currency's exchange rate to SDG.
 *
 * Keyed by currency code rather than any other identifier since WHMCS currency codes are
 * unique, fixed-format (3-letter ISO), and safe to use directly as a config field name.
 *
 * @param string $currencyCode
 *
 * @return string
 */
function cashipay_rate_field_name($currencyCode)
{
    return 'rateToSdg' . strtoupper($currencyCode);
}

/**
 * Exchange rate configured to convert the given currency code into SDG.
 *
 * Each currency gets its own dedicated config field (see cashipay_config()), generated at
 * config-build time from the currencies actually configured in WHMCS.
 *
 * @param array  $params       Gateway configuration parameters
 * @param string $currencyCode
 *
 * @return float|null Null when no rate is configured for this currency
 */
function cashipay_get_rate_to_sdg($params, $currencyCode)
{
    if (strtoupper($currencyCode) === 'SDG') {
        return 1.0;
    }

    $fieldName = cashipay_rate_field_name($currencyCode);
    $rate = isset($params[$fieldName]) ? (float) $params[$fieldName] : 0;

    // A rate of 0 (fat-fingered, left blank, or the currency has no field at all because it
    // was added to WHMCS after the gateway was last configured) would send CashiPay a
    // payment request for 0.00 while the invoice still gets marked paid for its real amount
    // once that request completes - treat it the same as "not configured".
    return $rate > 0 ? $rate : null;
}
