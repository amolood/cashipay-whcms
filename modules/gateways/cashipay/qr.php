<?php
/**
 * CashiPay Gateway Module for WHMCS
 *
 * Serves a QR code image (PNG) for the given reference number as a fallback, for payment
 * requests created before the stored qr_data_url column existed. Normally pay.php uses the
 * data URL CashiPay itself returned at creation time instead of generating this locally,
 * since CashiPay's status-check endpoint does not include the QR code again on later checks.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/helper.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = 'cashipay';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

$invoiceId = isset($_GET['invoiceid']) ? (int) $_GET['invoiceid'] : 0;
$referenceNumber = isset($_GET['ref']) ? trim((string) $_GET['ref']) : '';

if ($invoiceId < 1 || $referenceNumber === '') {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$stored = cashipay_get_stored_payment_request($referenceNumber);

if (!$stored || (int) $stored->invoice_id !== $invoiceId) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

// The QR encodes the plain payment reference number itself, not a link.
$content = $referenceNumber;

// TCPDF's QR encoder defaults to sampling a random subset of mask patterns for performance
// (QR_FIND_FROM_RANDOM), which makes the exact module layout it picks non-deterministic
// between requests for the *same* content - the reference number must render the exact same
// QR image every time the page is reloaded, so this forces it to deterministically evaluate
// all 8 mask patterns instead.
if (!defined('QR_FIND_FROM_RANDOM')) {
    define('QR_FIND_FROM_RANDOM', false);
}

require_once __DIR__ . '/../../../vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';

$barcode = new TCPDF2DBarcode($content, 'QRCODE,H');
header('Content-Type: image/png');
$barcode->getBarcodePNG(6, 6);
exit;
