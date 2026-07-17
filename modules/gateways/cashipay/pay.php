<?php
/**
 * CashiPay Gateway Module for WHMCS
 *
 * Hosted payment page - creates (or reuses) a CashiPay payment request for the given
 * invoice and displays the QR code, reference number and instructions, polling for
 * payment status client-side.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/lang.php';

use WHMCS\Database\Capsule;

/**
 * Render a small "?" icon with a hover tooltip explaining the preceding label.
 *
 * @param string $explanation Already-translated tooltip text
 *
 * @return string
 */
function cashipay_help_icon($explanation)
{
    return '<span class="cashipay-help" tabindex="0">?<span class="cashipay-help-tooltip">'
        . htmlspecialchars($explanation)
        . '</span></span>';
}

$gatewayModuleName = 'cashipay';
$gatewayParams = getGatewayVariables($gatewayModuleName);

$lang = cashipay_active_lang();
$strings = cashipay_strings($lang);

/**
 * Render a standalone error page instead of silently bouncing the customer back to the
 * invoice with no explanation - a bare redirect with a query string nobody reads looks
 * exactly like "nothing happened" when something actually went wrong.
 *
 * @param string $message   Already-translated, user-facing message
 * @param string $invoiceUrl
 * @param string $lang
 * @param array  $strings
 *
 * @return void Exits the script
 */
function cashipay_render_error($message, $invoiceUrl, $lang, $strings)
{
    $dir = $strings['dir'];

    ?><!doctype html>
<html lang="<?php echo htmlspecialchars($lang); ?>" dir="<?php echo htmlspecialchars($dir); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($strings['pageTitle']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            src: url('fonts/IBMPlexSansArabic-Regular.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            src: url('fonts/IBMPlexSansArabic-Bold.woff2') format('woff2');
            font-weight: 700;
            font-style: normal;
            font-display: swap;
        }
        body {
            font-family: 'IBM Plex Sans Arabic', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f6f8fa;
            margin: 0;
            padding: 30px 16px;
            color: #0f172a;
        }
        .cashipay-panel {
            max-width: 420px;
            margin: 0 auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            text-align: center;
        }
        .cashipay-body {
            padding: 28px 24px;
        }
        .cashipay-error-icon {
            font-size: 32px;
            margin-bottom: 12px;
        }
        .cashipay-error-message {
            color: #334155;
            font-size: 15px;
            line-height: 1.6;
        }
        .cashipay-footer {
            padding: 16px 20px;
            border-top: 1px solid #f1f5f9;
        }
        .cashipay-btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid #2563eb;
            background: #2563eb;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="cashipay-panel">
        <div class="cashipay-body">
            <div class="cashipay-error-icon">&#9888;&#65039;</div>
            <div class="cashipay-error-message"><?php echo htmlspecialchars($message); ?></div>
        </div>
        <div class="cashipay-footer">
            <a href="<?php echo htmlspecialchars($invoiceUrl); ?>" class="cashipay-btn"><?php echo htmlspecialchars($strings['backToInvoice']); ?></a>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

if (!$gatewayParams['type']) {
    http_response_code(503);
    die('Module Not Activated');
}

$invoiceId = isset($_GET['invoiceid']) ? (int) $_GET['invoiceid'] : 0;

if ($invoiceId < 1) {
    header('HTTP/1.1 404 Not Found');
    die('Invoice not found');
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

if (!$invoice) {
    header('HTTP/1.1 404 Not Found');
    die('Invoice not found');
}

$invoiceUrl = $gatewayParams['systemurl'] . 'viewinvoice.php?id=' . $invoiceId;

// Already paid - nothing to do here.
if ($invoice->status === 'Paid') {
    header('Location: ' . $invoiceUrl);
    exit;
}

$amountDue = (float) $invoice->total - (float) $invoice->credit;
$currencyCode = cashipay_get_invoice_currency_code($invoiceId);
$rate = $currencyCode !== null ? cashipay_get_rate_to_sdg($gatewayParams, $currencyCode) : null;

if ($rate === null) {
    logTransaction($gatewayModuleName, [
        'invoiceid' => $invoiceId,
        'currency'  => $currencyCode,
    ], 'No Exchange Rate Configured');

    cashipay_render_error($strings['errorNoRate'], $invoiceUrl, $lang, $strings);
}

$amountInSdg = round($amountDue * $rate, 2);

$referenceNumber = isset($_GET['ref']) ? trim((string) $_GET['ref']) : '';
$existingRequest = null;

if ($referenceNumber) {
    $existingRequest = cashipay_get_stored_payment_request($referenceNumber);

    // The stored request must actually belong to this invoice - otherwise a client could
    // pass an arbitrary reference number in the query string and view someone else's QR.
    if (!$existingRequest || (int) $existingRequest->invoice_id !== $invoiceId) {
        $existingRequest = null;
        $referenceNumber = null;
    }
}

// If there is no valid reference number yet, or the previously requested amount no longer
// matches what is currently due (e.g. a partial payment was applied since), create a fresh
// CashiPay payment request. The reference number/QR code otherwise stays stable across
// repeated page loads.
$needsNewRequest = true;
$statusResult = null;

if ($referenceNumber && $existingRequest) {
    $existingAmountNormalized = number_format((float) $existingRequest->amount, 2, '.', '');
    $newAmountNormalized = number_format($amountInSdg, 2, '.', '');
    $stillValid = $existingRequest->expires_at ? (strtotime($existingRequest->expires_at) > time()) : false;

    if ($stillValid && $existingAmountNormalized === $newAmountNormalized && !$existingRequest->payment_recorded) {
        $statusResult = cashipay_get_payment_status($gatewayParams, $referenceNumber);
        $rawStatus = $statusResult['data']['status'] ?? ($statusResult['status'] ?? '');
        $status = $statusResult ? cashipay_map_status($rawStatus) : null;

        if ($status === null) {
            $needsNewRequest = false;
        }
    }
}

if ($needsNewRequest) {
    $invoiceNumber = $invoice->invoicenum ?: $invoiceId;
    $description = str_replace('{invoice_number}', $invoiceNumber, $gatewayParams['descriptionTemplate']);
    $webhookUrl = $gatewayParams['systemurl'] . 'modules/gateways/callback/' . $gatewayModuleName . '.php?invoiceid=' . $invoiceId;
    $returnUrl = $gatewayParams['systemurl'] . 'modules/gateways/' . $gatewayModuleName . '/pay.php?invoiceid=' . $invoiceId . '&lang=' . $lang;

    $result = cashipay_create_payment_request($gatewayParams, [
        'merchantOrderId' => 'INVOICE-' . $invoiceId . '-' . time(),
        'amount' => [
            'value'    => number_format($amountInSdg, 2, '.', ''),
            'currency' => 'SDG',
        ],
        'description' => $description,
        'callbackUrl' => $webhookUrl,
        'returnUrl'   => $returnUrl,
    ]);

    if (!$result) {
        cashipay_render_error($strings['errorConnection'], $invoiceUrl, $lang, $strings);
    }

    $referenceNumber = $result['data']['referenceNumber'] ?? ($result['referenceNumber'] ?? null);

    if (!$referenceNumber) {
        logTransaction($gatewayModuleName, $result, 'Missing referenceNumber');
        cashipay_render_error($strings['errorGeneric'], $invoiceUrl, $lang, $strings);
    }

    // CashiPay only returns the QR code (image + scan link) in this creation response - it
    // is not included again on later status checks - so it is saved now for this page to
    // reuse on every subsequent load instead of having to regenerate it ourselves.
    cashipay_store_payment_request([
        'invoice_id'       => $invoiceId,
        'reference_number' => $referenceNumber,
        'qr_data_url'      => $result['data']['qrCode']['dataUrl'] ?? ($result['qrCode']['dataUrl'] ?? null),
        'qr_content'       => $result['data']['qrCode']['content'] ?? ($result['qrCode']['content'] ?? null),
        'amount'           => $amountInSdg,
        'currency'         => 'SDG',
        'expires_at'       => $result['data']['expiresAt'] ?? ($result['expiresAt'] ?? null),
    ]);

    $statusResult = $result;
} elseif (empty($statusResult)) {
    $statusResult = cashipay_get_payment_status($gatewayParams, $referenceNumber);
}

$rawStatus = $statusResult['data']['status'] ?? ($statusResult['status'] ?? '');
$status = $statusResult ? cashipay_map_status($rawStatus) : null;

// Already resolved via a previous poll/webhook - record the payment if needed, then bounce
// back to the invoice instead of showing the pay screen again.
if ($status === 'paid') {
    if (cashipay_claim_payment_recording($referenceNumber)) {
        $amountToRecord = number_format($amountDue, 2, '.', '');
        addInvoicePayment($invoiceId, $referenceNumber, $amountToRecord, 0, $gatewayModuleName);
        logTransaction($gatewayModuleName, ['invoiceid' => $invoiceId, 'reference' => $referenceNumber], 'Success');
    }

    header('Location: ' . $invoiceUrl);
    exit;
}

if ($status === 'failed') {
    cashipay_render_error($strings['errorFailed'], $invoiceUrl, $lang, $strings);
}

if ($status === 'expired') {
    cashipay_render_error($strings['errorExpired'], $invoiceUrl, $lang, $strings);
}

$stored = cashipay_get_stored_payment_request($referenceNumber);

$amount = $statusResult['data']['amount']['value'] ?? ($statusResult['amount']['value'] ?? $amountInSdg);
$currency = $statusResult['data']['amount']['currency'] ?? ($statusResult['amount']['currency'] ?? 'SDG');
$expiresAt = $statusResult['data']['expiresAt'] ?? ($statusResult['expiresAt'] ?? null);
$qrImageUrl = ($stored && !empty($stored->qr_data_url))
    ? $stored->qr_data_url
    : $gatewayParams['systemurl'] . 'modules/gateways/' . $gatewayModuleName . '/qr.php?ref=' . rawurlencode($referenceNumber) . '&invoiceid=' . $invoiceId;
$statusUrl = $gatewayParams['systemurl'] . 'modules/gateways/' . $gatewayModuleName . '/status.php?ref=' . rawurlencode($referenceNumber) . '&invoiceid=' . $invoiceId;
$pollInterval = max(1, (int) $gatewayParams['pollInterval']);

$invoiceNumber = $invoice->invoicenum ?: $invoiceId;

$availableLangs = ['en' => 'English', 'ar' => 'العربية'];
$currentUrlWithoutLang = $gatewayParams['systemurl'] . 'modules/gateways/' . $gatewayModuleName . '/pay.php?invoiceid=' . $invoiceId . '&ref=' . rawurlencode($referenceNumber);

?><!doctype html>
<html lang="<?php echo htmlspecialchars($lang); ?>" dir="<?php echo htmlspecialchars($strings['dir']); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($strings['pageTitle']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            src: url('fonts/IBMPlexSansArabic-Regular.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            src: url('fonts/IBMPlexSansArabic-Bold.woff2') format('woff2');
            font-weight: 700;
            font-style: normal;
            font-display: swap;
        }
        body {
            font-family: 'IBM Plex Sans Arabic', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f6f8fa;
            margin: 0;
            padding: 30px 16px;
            color: #0f172a;
        }
        .cashipay-lang-switcher {
            max-width: 420px;
            margin: 0 auto 12px;
            text-align: end;
        }
        .cashipay-lang-switcher a {
            display: inline-block;
            margin-inline-start: 8px;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 13px;
            text-decoration: none;
            color: #475569;
            border: 1px solid #cbd5e1;
            background: #fff;
        }
        .cashipay-lang-switcher a.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
        .cashipay-panel {
            max-width: 420px;
            margin: 0 auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .cashipay-heading {
            text-align: center;
            padding: 24px 20px 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .cashipay-heading h1 {
            font-size: 18px;
            margin: 0 0 6px;
        }
        .cashipay-heading a {
            color: #64748b;
            text-decoration: none;
            font-size: 13px;
        }
        .cashipay-body {
            padding: 20px;
            text-align: center;
        }
        .cashipay-qr-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .cashipay-qr-image {
            width: 220px;
            height: 220px;
            padding: 12px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        .cashipay-details {
            margin: 0 auto 20px;
            max-width: 320px;
        }
        .cashipay-details-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .cashipay-details-row:last-child {
            border-bottom: 0;
        }
        .cashipay-details-label {
            font-weight: 600;
            color: #475569;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .cashipay-help {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #cbd5e1;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            cursor: help;
        }
        .cashipay-help-tooltip {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            z-index: 10;
            bottom: calc(100% + 8px);
            inset-inline-start: 50%;
            transform: translateX(-50%);
            width: 220px;
            padding: 10px 12px;
            border-radius: 6px;
            background: #0f172a;
            color: #fff;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.5;
            text-align: start;
            transition: opacity 0.15s ease;
            pointer-events: none;
        }
        html[dir="rtl"] .cashipay-help-tooltip {
            transform: translateX(50%);
        }
        .cashipay-help:hover .cashipay-help-tooltip,
        .cashipay-help:focus .cashipay-help-tooltip {
            visibility: visible;
            opacity: 1;
        }
        .cashipay-details-value {
            color: #0f172a;
            font-size: 14px;
        }
        .cashipay-amount {
            font-weight: 700;
            font-size: 16px;
        }
        .cashipay-instructions {
            max-width: 320px;
            margin: 0 auto;
            text-align: start;
        }
        .cashipay-instructions-title {
            display: block;
            margin-bottom: 14px;
        }
        .cashipay-step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
            font-size: 14px;
        }
        .cashipay-step:last-child {
            margin-bottom: 0;
        }
        .cashipay-step-number {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #2563eb;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
        }
        .cashipay-status {
            margin-top: 16px;
            padding: 12px;
            border-radius: 6px;
            background: #eff6ff;
            color: #1e40af;
            font-size: 14px;
        }
        .cashipay-footer {
            padding: 16px 20px;
            border-top: 1px solid #f1f5f9;
            text-align: center;
        }
        .cashipay-btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .cashipay-btn-default {
            background: #fff;
            border-color: #cbd5e1;
            color: #334155;
        }
        .cashipay-btn-primary {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
            margin-inline-start: 8px;
        }
    </style>
</head>
<body>
    <div class="cashipay-lang-switcher">
        <?php foreach ($availableLangs as $code => $label) { ?>
            <a href="<?php echo htmlspecialchars($currentUrlWithoutLang . '&lang=' . $code); ?>"<?php echo $code === $lang ? ' class="active"' : ''; ?>><?php echo htmlspecialchars($label); ?></a>
        <?php } ?>
    </div>
    <div class="cashipay-panel">
        <div class="cashipay-heading">
            <h1><?php echo htmlspecialchars($strings['heading']); ?></h1>
            <a href="<?php echo htmlspecialchars($invoiceUrl); ?>"><?php echo htmlspecialchars($strings['invoicePrefix']) . htmlspecialchars($invoiceNumber); ?></a>
        </div>
        <div class="cashipay-body">
            <div class="cashipay-qr-wrapper">
                <img src="<?php echo htmlspecialchars($qrImageUrl); ?>" alt="CashiPay QR" class="cashipay-qr-image">
            </div>
            <div style="margin-bottom: 16px;">
                <?php echo cashipay_help_icon($strings['helpQr']); ?>
            </div>

            <div class="cashipay-details">
                <div class="cashipay-details-row">
                    <span class="cashipay-details-label"><?php echo htmlspecialchars($strings['referenceNumber']); ?><?php echo cashipay_help_icon($strings['helpReferenceNumber']); ?></span>
                    <span class="cashipay-details-value"><?php echo htmlspecialchars($referenceNumber); ?></span>
                </div>
                <div class="cashipay-details-row">
                    <span class="cashipay-details-label"><?php echo htmlspecialchars($strings['amount']); ?></span>
                    <span class="cashipay-details-value cashipay-amount"><?php echo htmlspecialchars(number_format((float) $amount, 2) . ' ' . $currency); ?></span>
                </div>
                <?php if (!empty($expiresAt)) { ?>
                <div class="cashipay-details-row">
                    <span class="cashipay-details-label"><?php echo htmlspecialchars($strings['validUntil']); ?><?php echo cashipay_help_icon($strings['helpValidUntil']); ?></span>
                    <span class="cashipay-details-value"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($expiresAt))); ?></span>
                </div>
                <?php } ?>
            </div>

            <hr style="border: none; border-top: 1px solid #f1f5f9; margin: 20px 0;">

            <div class="cashipay-instructions">
                <strong class="cashipay-instructions-title"><?php echo htmlspecialchars($strings['howToPay']); ?></strong>
                <div class="cashipay-step">
                    <span class="cashipay-step-number">1</span>
                    <span><?php echo htmlspecialchars($strings['step1']); ?></span>
                </div>
                <div class="cashipay-step">
                    <span class="cashipay-step-number">2</span>
                    <span><?php echo htmlspecialchars($strings['step2']); ?></span>
                </div>
                <div class="cashipay-step">
                    <span class="cashipay-step-number">3</span>
                    <span><?php echo htmlspecialchars($strings['step3']); ?></span>
                </div>
            </div>

            <div id="cashipay-status" class="cashipay-status"><?php echo $strings['waiting']; ?></div>
        </div>
        <div class="cashipay-footer">
            <a href="<?php echo htmlspecialchars($invoiceUrl); ?>" class="cashipay-btn cashipay-btn-default"><?php echo htmlspecialchars($strings['backToInvoice']); ?></a>
            <button type="button" id="cashipay-check-now" class="cashipay-btn cashipay-btn-primary"><?php echo htmlspecialchars($strings['checkNow']); ?></button>
        </div>
    </div>

    <script>
    (function () {
        var statusUrl = <?php echo json_encode($statusUrl); ?>;
        var pollIntervalMs = <?php echo (int) $pollInterval * 1000; ?>;
        var expiresAtMs = <?php echo json_encode(!empty($expiresAt) ? strtotime($expiresAt) * 1000 : null); ?>;
        var timer = null;

        function checkStatus() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', statusUrl, true);
            xhr.onload = function () {
                if (xhr.status !== 200) {
                    return;
                }
                var data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    return;
                }
                if (data.redirect) {
                    clearInterval(timer);
                    window.location.href = data.redirect;
                }
            };
            xhr.send();
        }

        timer = setInterval(function () {
            // Once the payment request has expired there is nothing left to poll for -
            // CashiPay's own status will eventually flip to EXPIRED and redirect, but there
            // is no reason for an abandoned tab to keep hitting the server forever before that.
            if (expiresAtMs && Date.now() > expiresAtMs) {
                clearInterval(timer);
                return;
            }
            checkStatus();
        }, pollIntervalMs);

        document.getElementById('cashipay-check-now').addEventListener('click', checkStatus);
    })();
    </script>
</body>
</html>
