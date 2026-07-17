<?php
/**
 * CashiPay Gateway Module for WHMCS
 *
 * Inline translation strings for the hosted pay page. Kept as a small standalone dictionary
 * (English/Arabic, matching the original Perfex CashiPay module's language pair) rather than
 * going through WHMCS's own lang() system, since pay.php is a standalone page reached
 * directly by URL and is not rendered through WHMCS's client-area Smarty templating.
 *
 * @copyright Copyright (c) 2026
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * @return array<string, array<string, string>>
 */
function cashipay_translations()
{
    return [
        'en' => [
            'dir'                  => 'ltr',
            'pageTitle'             => 'Pay with CashiPay',
            'heading'               => 'Scan to pay with CashiPay',
            'invoicePrefix'         => 'Invoice #',
            'referenceNumber'       => 'Reference Number',
            'amount'                => 'Amount',
            'validUntil'            => 'Valid Until',
            'howToPay'              => 'How to pay',
            'step1'                 => 'Open the My Cashi app on your phone',
            'step2'                 => 'Tap "Scan & Pay" and scan the QR code above',
            'step3'                 => 'Confirm the amount and complete the payment in the app',
            'waiting'               => 'Waiting for payment confirmation&hellip;',
            'backToInvoice'         => 'Back to invoice',
            'checkNow'              => 'Check now',
            'helpQr'                => 'This QR code contains a one-time link to this specific payment request. Scan it with the My Cashi app to open and pay it - it cannot be reused for a different payment.',
            'helpReferenceNumber'   => 'A unique number CashiPay generated for this payment request. If you contact support about this payment, share this number so it can be found quickly.',
            'helpValidUntil'        => 'This payment request stops working after this time. If it expires before you pay, simply reopen this page (or click "Pay Now" on the invoice again) to get a new one.',
            'errorNoRate'           => 'CashiPay is not available for this invoice\'s currency. Please contact us or choose another payment method.',
            'errorConnection'       => 'We could not reach the CashiPay payment gateway. Please try again in a moment, or choose another payment method.',
            'errorFailed'           => 'The CashiPay payment was not completed. You can try again below.',
            'errorExpired'          => 'This CashiPay payment request has expired. Please try again below.',
            'errorGeneric'          => 'Something went wrong while starting your CashiPay payment. Please try again or choose another payment method.',
        ],
        'ar' => [
            'dir'                  => 'rtl',
            'pageTitle'             => 'الدفع عبر كاشي باي',
            'heading'               => 'امسح رمز الاستجابة السريعة للدفع عبر كاشي باي',
            'invoicePrefix'         => 'الفاتورة رقم ',
            'referenceNumber'       => 'الرقم المرجعي',
            'amount'                => 'المبلغ',
            'validUntil'            => 'صالح حتى',
            'howToPay'              => 'طريقة الدفع',
            'step1'                 => 'افتح تطبيق My Cashi على هاتفك',
            'step2'                 => 'اضغط على "مسح ودفع" وامسح رمز الاستجابة السريعة أعلاه',
            'step3'                 => 'أكّد المبلغ وأكمل عملية الدفع داخل التطبيق',
            'waiting'               => 'بانتظار تأكيد الدفع&hellip;',
            'backToInvoice'         => 'العودة إلى الفاتورة',
            'checkNow'              => 'تحقق الآن',
            'helpQr'                => 'يحتوي رمز الاستجابة السريعة هذا على رابط دفع خاص بهذا الطلب فقط. امسحه باستخدام تطبيق My Cashi لفتحه ودفعه - ولا يمكن استخدامه لعملية دفع أخرى.',
            'helpReferenceNumber'   => 'رقم فريد أنشأته كاشي باي لطلب الدفع هذا. إذا تواصلت مع الدعم بخصوص هذه العملية، شارك هذا الرقم لتسهيل الوصول إليها بسرعة.',
            'helpValidUntil'        => 'يتوقف طلب الدفع هذا عن العمل بعد هذا الوقت. إذا انتهت صلاحيته قبل أن تدفع، ما عليك سوى إعادة فتح هذه الصفحة (أو الضغط على "ادفع الآن" في الفاتورة مجددًا) للحصول على طلب جديد.',
            'errorNoRate'           => 'الدفع عبر كاشي باي غير متاح لعملة هذه الفاتورة. يرجى التواصل معنا أو اختيار وسيلة دفع أخرى.',
            'errorConnection'       => 'تعذر الاتصال ببوابة الدفع كاشي باي. يرجى المحاولة مرة أخرى بعد قليل أو اختيار وسيلة دفع أخرى.',
            'errorFailed'           => 'لم تكتمل عملية الدفع عبر كاشي باي. يمكنك المحاولة مرة أخرى أدناه.',
            'errorExpired'          => 'انتهت صلاحية طلب الدفع هذا. يرجى المحاولة مرة أخرى أدناه.',
            'errorGeneric'          => 'حدث خطأ أثناء بدء عملية الدفع عبر كاشي باي. يرجى المحاولة مرة أخرى أو اختيار وسيلة دفع أخرى.',
        ],
    ];
}

/**
 * Resolve the active language for the pay page from a "lang" query param, defaulting to
 * English and falling back to English for any unsupported value.
 *
 * @return string Language code, e.g. "en" or "ar"
 */
function cashipay_active_lang()
{
    $translations = cashipay_translations();
    $requested = isset($_GET['lang']) ? strtolower(trim((string) $_GET['lang'])) : 'en';

    return isset($translations[$requested]) ? $requested : 'en';
}

/**
 * @param string $lang
 *
 * @return array<string, string>
 */
function cashipay_strings($lang)
{
    $translations = cashipay_translations();

    return $translations[$lang] ?? $translations['en'];
}
