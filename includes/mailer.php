<?php
require_once __DIR__ . '/../src/Exception.php';
require_once __DIR__ . '/../src/PHPMailer.php';
require_once __DIR__ . '/../src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── SEND WRAPPER ──────────────────────────────────────────────
function sendEmail(
    string  $toEmail,
    string  $toName,
    string  $subject,
    string  $htmlBody,
    ?string $altBody = null
): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("sendEmail: invalid address '$toEmail'");
        return false;
    }

    // Guard: don't crash if mail constants aren't defined yet
    if (!defined('SMTP_HOST') || !defined('SMTP_USER')) {
        error_log("sendEmail: SMTP constants not configured. Skipping mail to $toEmail.");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = (strtolower(SMTP_SECURE) === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?? strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("sendEmail failed to $toEmail: " . $mail->ErrorInfo);
        return false;
    }
}

function emailIcon(string $name): string {
    $valid = ['gift','receipt','cash','bank','star','shield','key','bell','check','warning','lock'];
    if (!in_array($name, $valid, true)) $name = 'bell';

    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://kweek.ng';
    $src = rtrim($siteUrl, '/') . '/assets/email-icons/' . $name . '.png';

    return '
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto">
      <tr>
        <td align="center" valign="middle">
          <img src="' . htmlspecialchars($src) . '" width="60" height="60" alt=""
            style="display:block;border:0;outline:none;text-decoration:none;width:60px;height:60px">
        </td>
      </tr>
    </table>';
}

function emailTemplate(
    string  $title,
    string  $bodyHtml,
    ?string $ctaUrl   = null,
    ?string $ctaLabel = null,
    ?string $icon     = 'bell',
    ?string $kicker   = null
): string {

    $iconHtml = $icon ? '
      <tr>
        <td align="center" style="padding:28px 32px 0 32px">
          '.emailIcon($icon).'
        </td>
      </tr>' : '';

    $kickerHtml = $kicker ? '
      <tr>
        <td align="center" style="padding:14px 32px 0 32px">
          <span style="display:inline-block;background:#F0EFFE;color:#4F43D4;
            font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;
            letter-spacing:1.2px;text-transform:uppercase;padding:5px 14px;
            border-radius:999px">
            '.htmlspecialchars($kicker).'
          </span>
        </td>
      </tr>' : '';

    $ctaHtml = '';
    if ($ctaUrl && $ctaLabel) {
        $ctaHtml = '
      <tr>
        <td align="center" style="padding:28px 32px 8px 32px">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
            href="'.htmlspecialchars($ctaUrl).'"
            style="height:48px;v-text-anchor:middle;width:220px;" arcsize="25%" fillcolor="#4F43D4">
            <w:anchorlock/>
            <center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700">
              '.htmlspecialchars($ctaLabel).' &rarr;
            </center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-->
          <a href="'.htmlspecialchars($ctaUrl).'"
            style="display:inline-block;background-color:#4F43D4;color:#ffffff;
              text-decoration:none;font-family:Arial,Helvetica,sans-serif;
              font-weight:700;font-size:15px;padding:14px 32px;
              border-radius:12px;letter-spacing:.2px;mso-hide:all">
            '.htmlspecialchars($ctaLabel).' &rarr;
          </a>
          <!--<![endif]-->
        </td>
      </tr>';
    }

    $year    = date('Y');
    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://kweek.ng';

    return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
  <title>'.htmlspecialchars($title).'</title>
</head>
<body style="margin:0;padding:0;background-color:#F0EFF9;-webkit-font-smoothing:antialiased">

<!-- WRAPPER -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
  style="background-color:#F0EFF9;padding:40px 16px">
  <tr>
    <td align="center">

      <!-- CARD -->
      <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0"
        style="background:#ffffff;border-radius:20px;overflow:hidden;
          border:1px solid #E4E3EE;max-width:520px;width:100%">

        <!-- HEADER BAND -->
        <tr>
          <td style="background-color:#0F0E1C;padding:24px 32px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <span style="font-family:Arial,Helvetica,sans-serif;font-size:22px;
                    font-weight:800;color:#ffffff;letter-spacing:-0.5px">
                    Kw<span style="color:#9B93F3">ee</span>k
                  </span>
                  <br>
                  <span style="font-family:Arial,Helvetica,sans-serif;font-size:11px;
                    color:#6E6C88;letter-spacing:.3px">
                    The payment OS for Nigerian merchants
                  </span>
                </td>
                <td align="right" valign="middle">
                  <span style="font-family:Arial,Helvetica,sans-serif;font-size:11px;
                    font-weight:700;color:#9B93F3;letter-spacing:1px;
                    text-transform:uppercase">
                    Kweek.ng
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        '.$iconHtml.'
        '.$kickerHtml.'

        <!-- TITLE -->
        <tr>
          <td align="center" style="padding:18px 36px 0 36px">
            <h1 style="margin:0;font-family:Arial,Helvetica,sans-serif;
              font-size:22px;font-weight:800;color:#0F0E1C;letter-spacing:-.5px;
              line-height:1.3">
              '.htmlspecialchars($title).'
            </h1>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td style="padding:14px 36px 0 36px;font-family:Arial,Helvetica,sans-serif;
            font-size:14px;line-height:1.8;color:#4A4862;text-align:center">
            '.$bodyHtml.'
          </td>
        </tr>

        '.$ctaHtml.'

        <!-- DIVIDER -->
        <tr>
          <td style="padding:28px 32px 0 32px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr><td style="height:1px;background:#F0EFF6;font-size:0;line-height:0">&nbsp;</td></tr>
            </table>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td align="center" style="padding:18px 32px 28px 32px">
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;
              color:#9896B0;line-height:1.7">
              You received this because of activity on your Kweek account.<br>
              Questions?&nbsp;<a href="mailto:support@kweek.ng"
                style="color:#4F43D4;text-decoration:none;font-weight:700">support@kweek.ng</a>
            </p>
          </td>
        </tr>

      </table>
      <!-- /CARD -->

      <!-- BELOW CARD -->
      <p style="font-family:Arial,Helvetica,sans-serif;font-size:11px;
        color:#9896B0;margin:18px 0 0;text-align:center">
        &copy; '.$year.' Kweek Technologies Ltd &middot; Lagos, Nigeria
      </p>

    </td>
  </tr>
</table>
<!-- /WRAPPER -->

</body>
</html>';
}

function sendWelcomeEmail(array $user): bool {
    $name      = htmlspecialchars($user['name']);
    $firstName = htmlspecialchars(explode(' ', trim($user['name']))[0]);
    $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://kweek.ng';

    $body = '
      <p style="margin:0 0 16px 0">Hi '.$firstName.', welcome aboard! Your Kweek account is ready.
        Create payment links, accept orders, and get paid — all without a CAC or bank approval.</p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="margin:20px 0;border-radius:14px;overflow:hidden;background:#F9F9FB;text-align:left">
        <tr>
          <td style="padding:14px 18px;border-bottom:1px solid #F0EFF6">
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#4F43D4;font-weight:800">1.</span>
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#302E45;font-weight:600;margin-left:8px">Create your first payment link</span>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 18px;border-bottom:1px solid #F0EFF6">
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#4F43D4;font-weight:800">2.</span>
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#302E45;font-weight:600;margin-left:8px">Share it on WhatsApp or Instagram bio</span>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 18px">
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#4F43D4;font-weight:800">3.</span>
            <span style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#302E45;font-weight:600;margin-left:8px">Get paid instantly &mdash; right to your wallet</span>
          </td>
        </tr>
      </table>

      <p style="margin:0;font-size:13px;color:#9896B0">
        Need help? Reply to this email or visit
        <a href="'.$siteUrl.'" style="color:#4F43D4;font-weight:700;text-decoration:none">kweek.ng</a>
      </p>';

    $html = emailTemplate(
        'Welcome to Kweek, '.$firstName.'!',
        $body,
        $siteUrl . '/create-link.php',
        'Create my first link',
        'gift',
        'Account Created'
    );

    $alt = "Hi $firstName, welcome to Kweek!\n\nYour account is ready.\n\n"
         . "1. Create a payment link\n2. Share on WhatsApp\n3. Get paid instantly\n\n"
         . "Get started: {$siteUrl}/create-link.php\n\n"
         . "Questions? support@kweek.ng";

    return sendEmail($user['email'], $user['name'], 'Welcome to Kweek', $html, $alt);
}


function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): bool {
    $firstName = htmlspecialchars(explode(' ', trim($toName))[0]);

    $body = '
      <p style="margin:0 0 14px 0">Hi '.$firstName.', we received a request to reset your Kweek password.</p>
      <p style="margin:0 0 14px 0;font-size:13px;color:#6E6C88">
        Click the button below to set a new password. This link
        <strong style="color:#0F0E1C">expires in 1 hour</strong>.
      </p>
      <p style="margin:0;font-size:13px;color:#9896B0">
        If you did&nbsp;not request a password reset, you can safely ignore this email &mdash;
        your password will not change.
      </p>';

    $html = emailTemplate(
        'Reset your password',
        $body,
        $resetLink,
        'Reset my password',
        'key',
        'Security'
    );

    $alt = "Hi $firstName,\n\nReset your Kweek password:\n$resetLink\n\n"
         . "This link expires in 1 hour.\n"
         . "If you didn't request this, ignore this email.\n\n"
         . "Kweek · support@kweek.ng";

    return sendEmail($toEmail, $toName, 'Reset your Kweek password', $html, $alt);
}


function sendPaymentReceivedEmail(array $user, array $order, string $linkTitle): bool {
    $firstName = htmlspecialchars(explode(' ', trim($user['name']))[0]);
    $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://kweek.ng';

    $body = '
      <p style="margin:0 0 16px 0">
        Hi '.$firstName.', you just received a payment on <strong>'.htmlspecialchars($linkTitle).'</strong>.
      </p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background:#F9F9FB;border-radius:14px;overflow:hidden;text-align:left;margin:0 0 16px">
        <tr>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">
            From
          </td>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;color:#0F0E1C;text-align:right">
            '.htmlspecialchars($order['customer_name']).'
          </td>
        </tr>
        <tr>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">
            Order Ref
          </td>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Courier,monospace;font-size:12px;font-weight:700;
            color:#0F0E1C;text-align:right">
            '.htmlspecialchars($order['order_ref']).'
          </td>
        </tr>
        <tr>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">
            Amount
          </td>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;
            font-size:20px;font-weight:800;color:#4F43D4;text-align:right;letter-spacing:-.5px">
            '.formatNaira((float)$order['total_amount']).'
          </td>
        </tr>
      </table>

      <p style="margin:0;font-size:13px;color:#9896B0">
        Funds have been credited to your Kweek wallet. Withdraw anytime from your dashboard.
      </p>';

    $html = emailTemplate(
        'Payment Received!',
        $body,
        $siteUrl . '/orders.php',
        'View order details',
        'cash',
        'New Payment'
    );

    $alt = "Hi $firstName, you received a payment!\n\n"
         . "From: {$order['customer_name']}\n"
         . "Amount: " . formatNaira((float)$order['total_amount']) . "\n"
         . "Ref: {$order['order_ref']}\n\n"
         . "View in dashboard: {$siteUrl}/orders.php";

    return sendEmail($user['email'], $user['name'], 'Payment received! — ' . formatNaira((float)$order['total_amount']), $html, $alt);
}


function sendOrderConfirmationEmail(array $order, string $merchantName, string $linkTitle): bool {
    if (empty($order['customer_email']) || !filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $siteUrl  = defined('SITE_URL') ? SITE_URL : 'https://kweek.ng';
    $awaiting = ($order['status'] ?? '') === 'pending_confirmation';

    $statusNote = $awaiting
        ? '<p style="margin:0 0 14px 0;background:#FEF3C7;border-radius:10px;padding:12px 16px;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#92400E;text-align:left">
            <strong>Awaiting confirmation</strong> &mdash;
            '.htmlspecialchars($merchantName).' will review your order and send
            you a payment link once confirmed. This usually takes a few minutes.
           </p>'
        : '<p style="margin:0 0 14px 0;font-family:Arial,Helvetica,sans-serif;
            font-size:13px;color:#4A4862">
            Your order has been received. Complete payment using the link you were
            given at checkout.
           </p>';

    $body = '
      <p style="margin:0 0 14px 0">
        Hi '.htmlspecialchars($order['customer_name']).', your order with
        <strong>'.htmlspecialchars($merchantName).'</strong> for
        <strong>'.htmlspecialchars($linkTitle).'</strong> has been placed.
      </p>

      '.$statusNote.'

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background:#F9F9FB;border-radius:14px;overflow:hidden;text-align:left">
        <tr>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">Order Ref</td>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Courier,monospace;font-size:12px;font-weight:700;
            color:#0F0E1C;text-align:right">
            '.htmlspecialchars($order['order_ref']).'
          </td>
        </tr>
        <tr>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">
            Total
          </td>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;
            font-size:18px;font-weight:800;color:#4F43D4;text-align:right;letter-spacing:-.5px">
            '.formatNaira((float)$order['total_amount']).'
          </td>
        </tr>
      </table>';

    $html = emailTemplate(
        'Order Placed',
        $body,
        $siteUrl . '/receipt.php?ref=' . urlencode($order['order_ref']),
        'View order status',
        'receipt',
        'Order Confirmed'
    );

    $alt = "Hi {$order['customer_name']},\n\nYour order with $merchantName has been placed.\n\n"
         . "Order Ref: {$order['order_ref']}\n"
         . "Total: " . formatNaira((float)$order['total_amount']) . "\n\n"
         . ($awaiting ? "Status: Awaiting merchant confirmation.\n\n" : '')
         . "Track your order: {$siteUrl}/receipt.php?ref={$order['order_ref']}";

    return sendEmail(
        $order['customer_email'],
        $order['customer_name'],
        'Your order with ' . $merchantName . ' — Kweek',
        $html,
        $alt
    );
}


function sendWithdrawalEmail(array $user, float $amount, float $net, string $bankName, string $accountNumber): bool {
    $firstName = htmlspecialchars(explode(' ', trim($user['name']))[0]);
    $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://kweek.ng';

    $body = '
      <p style="margin:0 0 16px 0">Hi '.$firstName.', your withdrawal request has been initiated.</p>

      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background:#F9F9FB;border-radius:14px;overflow:hidden;text-align:left">
        <tr>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">Amount Requested</td>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;
            color:#0F0E1C;text-align:right">'.formatNaira($amount).'</td>
        </tr>
        <tr>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">You Receive</td>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:800;
            color:#4F43D4;text-align:right;letter-spacing:-.5px">'.formatNaira($net).'</td>
        </tr>
        <tr>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">Destination</td>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;
            font-weight:700;color:#0F0E1C;text-align:right">
            '.htmlspecialchars($bankName).'<br>
            <span style="font-family:Courier,monospace;font-weight:400;font-size:12px;color:#6E6C88">
              '.htmlspecialchars($accountNumber).'
            </span>
          </td>
        </tr>
      </table>

      <p style="margin:16px 0 0;font-size:13px;color:#9896B0">
        Funds typically arrive within minutes. If you do not receive your funds within
        2 hours, contact <a href="mailto:support@kweek.ng"
          style="color:#4F43D4;font-weight:700;text-decoration:none">support@kweek.ng</a>.
      </p>';

    $html = emailTemplate(
        'Withdrawal Initiated',
        $body,
        $siteUrl . '/transactions.php',
        'View transaction history',
        'bank',
        'Withdrawal'
    );

    $alt = "Hi $firstName,\n\nYour withdrawal of " . formatNaira($amount)
         . " has been initiated.\nYou will receive " . formatNaira($net)
         . " in your $bankName account ($accountNumber).\n\n"
         . "Questions? support@kweek.ng";

    return sendEmail($user['email'], $user['name'], 'Withdrawal initiated — ' . formatNaira($net), $html, $alt);
}


function sendOrderApprovedEmail(array $order, string $checkoutUrl, string $merchantName, string $linkTitle): bool {
    if (empty($order['customer_email']) || !filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $body = '
      <p style="margin:0 0 14px 0">
        Good news, '.htmlspecialchars($order['customer_name']).'! <strong>'.htmlspecialchars($merchantName).'</strong>
        has approved your order for <strong>'.htmlspecialchars($linkTitle).'</strong>. Complete payment now to secure it.
      </p>
      <p style="margin:0 0 14px 0;background:#FEF3C7;border-radius:10px;padding:12px 16px;
        font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#92400E;text-align:left">
        <strong>This payment link expires in 20 minutes.</strong> Please complete your payment promptly to avoid needing a new link.
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background:#F9F9FB;border-radius:14px;overflow:hidden;text-align:left">
        <tr>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">Order Ref</td>
          <td style="padding:13px 18px;border-bottom:1px solid #F0EFF6;
            font-family:Courier,monospace;font-size:12px;font-weight:700;
            color:#0F0E1C;text-align:right">'.htmlspecialchars($order['order_ref']).'</td>
        </tr>
        <tr>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">Total</td>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;
            font-size:18px;font-weight:800;color:#4F43D4;text-align:right;letter-spacing:-.5px">
            '.formatNaira((float)$order['total_amount']).'</td>
        </tr>
      </table>';

    $html = emailTemplate(
        'Your Order Was Approved',
        $body,
        $checkoutUrl,
        'Pay now',
        'cash',
        'Action Required'
    );

    $alt = "Hi {$order['customer_name']},\n\n$merchantName approved your order for $linkTitle.\n\n"
         . "Pay now (expires in 20 minutes): $checkoutUrl\n\n"
         . "Order Ref: {$order['order_ref']}\nTotal: " . formatNaira((float)$order['total_amount']);

    return sendEmail(
        $order['customer_email'],
        $order['customer_name'],
        'Action required: complete your payment to ' . $merchantName,
        $html,
        $alt
    );
}

// ── ORDER MARKED COMPLETED BY MERCHANT ─────────────────────────
function sendOrderCompletedEmail(array $order, string $merchantName, string $linkTitle): bool {
    if (empty($order['customer_email']) || !filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $body = '
      <p style="margin:0 0 14px 0">
        Hi '.htmlspecialchars($order['customer_name']).', your order with
        <strong>'.htmlspecialchars($merchantName).'</strong> for
        <strong>'.htmlspecialchars($linkTitle).'</strong> has been marked as completed. Thank you for your purchase!
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background:#F9F9FB;border-radius:14px;overflow:hidden;text-align:left">
        <tr>
          <td style="padding:13px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6E6C88">Order Ref</td>
          <td style="padding:13px 18px;font-family:Courier,monospace;font-size:12px;font-weight:700;
            color:#0F0E1C;text-align:right">'.htmlspecialchars($order['order_ref']).'</td>
        </tr>
      </table>';

    $html = emailTemplate('Order Completed', $body, null, null, 'check', 'All Done');

    $alt = "Hi {$order['customer_name']},\n\nYour order with $merchantName ({$order['order_ref']}) has been marked completed. Thank you!";

    return sendEmail(
        $order['customer_email'],
        $order['customer_name'],
        'Your order with ' . $merchantName . ' is complete',
        $html,
        $alt
    );
}

function sendNotificationEmail(array $user, string $title, string $message, ?string $link = null): bool {
    $icon = match(true) {
        str_contains(strtolower($title), 'payment')    => 'cash',
        str_contains(strtolower($title), 'withdraw')   => 'bank',
        str_contains(strtolower($title), 'upgrad'),
        str_contains(strtolower($title), 'plan')       => 'star',
        str_contains(strtolower($title), 'verif'),
        str_contains(strtolower($title), 'kyc')        => 'shield',
        str_contains(strtolower($title), 'order')      => 'receipt',
        str_contains(strtolower($title), 'password'),
        str_contains(strtolower($title), 'security')   => 'key',
        default                                         => 'bell',
    };

    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://kweek.ng';
    $ctaUrl  = $link ? rtrim($siteUrl, '/') . $link : null;

    $body = '<p style="margin:0">'.nl2br(htmlspecialchars($message)).'</p>';

    $html = emailTemplate(
        $title,
        $body,
        $ctaUrl,
        $ctaUrl ? 'View in dashboard' : null,
        $icon
    );

    return sendEmail(
        $user['email'],
        $user['name'],
        $title . ' — Kweek',
        $html
    );
}