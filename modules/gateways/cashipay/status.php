<?php
/**
 * CashiPay Gateway Module for WHMCS
 *
 * JSON status-check endpoint polled by the pay page's JavaScript. Also records the payment
 * the moment "paid" is observed here, rather than relying solely on the webhook - the
 * customer's own browser polling this endpoint is often faster than CashiPay's async callback.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/helper.php';

use WHMCS\Database\Capsule;

header('Content-Type: application/json');

$gatewayModuleName = 'cashipay';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    echo json_encode(['status' => null, 'redirect' => null]);
    exit;
}

$invoiceId = isset($_GET['invoiceid']) ? (int) $_GET['invoiceid'] : 0;
$referenceNumber = isset($_GET['ref']) ? trim((string) $_GET['ref']) : '';

if ($invoiceId < 1 || $referenceNumber === '') {
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['status' => null, 'redirect' => null]);
    exit;
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
$stored = cashipay_get_stored_payment_request($referenceNumber);

if (!$invoice || !$stored || (int) $stored->invoice_id !== $invoiceId) {
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['status' => null, 'redirect' => null]);
    exit;
}

$invoiceUrl = $gatewayParams['systemurl'] . 'viewinvoice.php?id=' . $invoiceId;

$result = cashipay_get_payment_status($gatewayParams, $referenceNumber);
$rawStatus = $result['data']['status'] ?? ($result['status'] ?? '');
$status = $result ? cashipay_map_status($rawStatus) : null;

if ($status === 'paid' && !$stored->payment_recorded && cashipay_claim_payment_recording($referenceNumber)) {
    $amountDue = (float) $invoice->total - (float) $invoice->credit;
    $amountToRecord = number_format($amountDue, 2, '.', '');
    addInvoicePayment($invoiceId, $referenceNumber, $amountToRecord, 0, $gatewayModuleName);
    logTransaction($gatewayModuleName, ['invoiceid' => $invoiceId, 'reference' => $referenceNumber], 'Success');
}

echo json_encode([
    'status'   => $status,
    'redirect' => in_array($status, ['paid', 'failed', 'expired'], true) ? $invoiceUrl : null,
]);
