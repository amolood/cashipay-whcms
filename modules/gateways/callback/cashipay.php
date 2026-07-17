<?php
/**
 * CashiPay Payment Callback File
 *
 * Handles the asynchronous webhook CashiPay calls once a payment request is resolved.
 * Structured following WHMCS's own sample gateway callback file.
 *
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../cashipay/helper.php';

use WHMCS\Database\Capsule;

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

// Retrieve data returned in the CashiPay callback. CashiPay posts (or may query-string,
// depending on its configuration) the reference number for the payment request that was
// resolved; the actual up-to-date status is always re-fetched from CashiPay's API rather
// than trusted from the callback body itself.
$referenceNumber = $_POST['referenceNumber'] ?? ($_GET['referenceNumber'] ?? null);
$invoiceId = isset($_GET['invoiceid']) ? (int) $_GET['invoiceid'] : 0;

if (!$referenceNumber || $invoiceId < 1) {
    logTransaction($gatewayModuleName, $_REQUEST, 'Missing Reference Number or Invoice ID');
    die('Missing Reference Number or Invoice ID');
}

/**
 * Validate Callback Invoice ID.
 *
 * Checks invoice ID is a valid invoice number. Note it will count an invoice in any status
 * as valid. Performs a die upon encountering an invalid Invoice ID. Returns a normalised
 * invoice ID.
 */
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

$stored = cashipay_get_stored_payment_request($referenceNumber);

if (!$stored || (int) $stored->invoice_id !== $invoiceId) {
    logTransaction($gatewayModuleName, $_REQUEST, 'Reference/Invoice Mismatch');
    die('Reference/Invoice Mismatch');
}

// Re-fetch the authoritative status directly from CashiPay rather than trusting whatever
// the callback body itself claims.
$result = cashipay_get_payment_status($gatewayParams, $referenceNumber);

if (!$result) {
    logTransaction($gatewayModuleName, $_REQUEST, 'Could Not Fetch Payment Status');
    die('Could Not Fetch Payment Status');
}

$rawStatus = $result['data']['status'] ?? ($result['status'] ?? '');
$status = cashipay_map_status($rawStatus);

if ($status !== 'paid') {
    logTransaction($gatewayModuleName, ['reference' => $referenceNumber, 'rawStatus' => $rawStatus], 'Not Completed');
    die('Not Completed');
}

if (!cashipay_claim_payment_recording($referenceNumber)) {
    logTransaction($gatewayModuleName, ['reference' => $referenceNumber], 'Already Recorded');
    die('Already Recorded');
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
$amountDue = $invoice ? ((float) $invoice->total - (float) $invoice->credit) : (float) $stored->amount;

/**
 * Check Callback Transaction ID.
 *
 * Performs a check for any existing transactions with the same given transaction number.
 * Performs a die upon encountering a duplicate.
 */
checkCbTransID($referenceNumber);

/**
 * Log Transaction.
 *
 * Add an entry to the Gateway Log for debugging purposes.
 */
logTransaction($gatewayParams['name'], $result, 'Success');

/**
 * Add Invoice Payment.
 *
 * Applies a payment transaction entry to the given invoice ID.
 */
addInvoicePayment(
    $invoiceId,
    $referenceNumber,
    number_format($amountDue, 2, '.', ''),
    0,
    $gatewayModuleName
);
