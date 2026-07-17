<?php
/**
 * CashiPay Payment Gateway Module for WHMCS
 *
 * Accepts invoice payments via CashiPay - a QR-based mobile payment provider
 * that settles in SDG (Sudanese Pound).
 *
 * Instead of redirecting the client straight to an external processor page,
 * this module renders its own hosted "pay" page (modules/gateways/cashipay/pay.php)
 * showing a QR code, reference number and live payment status, in the same style
 * as WHMCS's own PayPal/offline-style third-party gateways.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/cashipay/helper.php';

/**
 * Define module related meta data.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function cashipay_MetaData()
{
    return [
        'DisplayName' => 'CashiPay',
        'APIVersion'  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Define gateway configuration options.
 *
 * CashiPay always settles in SDG, so one "exchange rate to SDG" field is generated here for
 * every currency currently configured in WHMCS (except SDG itself, nothing to convert
 * there). Unlike some other platforms, WHMCS calls this function fresh - with a live
 * database connection - each time the gateway's settings are needed, so the field list can
 * safely be built from the current tblcurrencies contents rather than hardcoded.
 *
 * @return array
 */
function cashipay_config()
{
    $config = [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'CashiPay',
        ],
        'baseUrl' => [
            'FriendlyName' => 'API Base URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => 'https://prod-cashi-services.alsoug.com/cashipay/api/v1',
            'Description'  => 'CashiPay API base URL, no trailing slash',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Your CashiPay API bearer token',
        ],
    ];

    foreach (cashipay_get_convertible_currencies() as $currency) {
        $config[cashipay_rate_field_name($currency->code)] = [
            'FriendlyName' => 'Rate: 1 ' . $currency->code . ' = ? SDG',
            'Type'         => 'text',
            'Size'         => '15',
            'Default'      => '',
            'Description'  => 'Leave blank to disable CashiPay for invoices in ' . $currency->code,
        ];
    }

    $config['descriptionTemplate'] = [
        'FriendlyName' => 'Payment Description',
        'Type'         => 'text',
        'Size'         => '60',
        'Default'      => 'Payment for Invoice {invoice_number}',
        'Description'  => 'Sent to CashiPay as the payment description. {invoice_number} is replaced automatically.',
    ];

    $config['pollInterval'] = [
        'FriendlyName' => 'Status Poll Interval',
        'Type'         => 'text',
        'Size'         => '5',
        'Default'      => '5',
        'Description'  => 'How often, in seconds, the pay page checks CashiPay for payment status',
    ];

    return $config;
}

/**
 * Payment link.
 *
 * Rather than posting straight to an external processor, this renders a link to our own
 * hosted pay page, which creates (or reuses) the CashiPay payment request server-side and
 * displays the QR code/instructions - see modules/gateways/cashipay/pay.php.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function cashipay_link($params)
{
    $invoiceId = (int) $params['invoiceid'];

    $systemUrl = $params['systemurl'];
    $moduleName = $params['paymentmethod'];

    $payUrl = $systemUrl . 'modules/gateways/' . $moduleName . '/pay.php?invoiceid=' . $invoiceId;

    $langPayNow = $params['langpaynow'];

    return '<a href="' . htmlspecialchars($payUrl) . '" class="btn btn-primary">' . htmlspecialchars($langPayNow) . '</a>';
}
