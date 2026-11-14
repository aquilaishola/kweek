<?php
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy — Kweek</title>
<meta name="description" content="How Kweek collects, uses, and protects your data.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://kweek.ng/privacy-policy.php">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --purple:#4F43D4;--purple-dark:#3D33B8;--purple-50:#F2F0FC;
  --ink:#15131F;--gray-700:#3F3C4A;--gray-500:#6B6879;--gray-300:#D6D3DE;--gray-200:#E4E2EA;--gray-100:#F0EFF5;
  --white:#fff;--font:'Space Grotesk',sans-serif;--r-md:10px;--r-lg:16px;--r-full:9999px;
}
body{font-family:var(--font);color:var(--ink);background:var(--white);-webkit-font-smoothing:antialiased}
a{color:var(--purple);text-decoration:none}
a:hover{text-decoration:underline}

.legal-nav{border-bottom:1px solid var(--gray-100);position:sticky;top:0;background:rgba(255,255,255,.92);backdrop-filter:blur(10px);z-index:10}
.legal-nav-inner{max-width:820px;margin:0 auto;padding:16px 24px;display:flex;align-items:center;justify-content:space-between}
.logo{display:flex;align-items:center;gap:9px;text-decoration:none}
.logo-mark{width:30px;height:30px;border-radius:8px;background:var(--purple);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.logo-mark svg{width:17px;height:17px}
.logo-word{font-weight:700;font-size:17px;color:var(--ink);letter-spacing:-.3px}
.legal-nav a.back{font-size:13.5px;font-weight:600;color:var(--gray-700);display:flex;align-items:center;gap:6px}
.legal-nav a.back:hover{color:var(--purple);text-decoration:none}

.legal-wrap{max-width:820px;margin:0 auto;padding:48px 24px 90px}
.legal-header{margin-bottom:36px;padding-bottom:28px;border-bottom:1px solid var(--gray-100)}
.legal-header h1{font-size:clamp(26px,4vw,36px);font-weight:700;letter-spacing:-.6px;margin-bottom:10px}
.legal-header .updated{font-size:13px;color:var(--gray-500)}
.legal-notice{background:var(--purple-50);border-radius:var(--r-md);padding:14px 16px;font-size:13px;color:var(--gray-700);line-height:1.6;margin-top:16px}

.legal-body h2{font-size:19px;font-weight:700;letter-spacing:-.3px;margin:34px 0 12px}
.legal-body h2:first-child{margin-top:0}
.legal-body h3{font-size:15px;font-weight:700;margin:20px 0 8px}
.legal-body p{font-size:14.5px;color:var(--gray-700);line-height:1.75;margin-bottom:12px}
.legal-body ul, .legal-body ol{margin:0 0 14px 22px;color:var(--gray-700);font-size:14.5px;line-height:1.75}
.legal-body li{margin-bottom:6px}
.legal-body strong{color:var(--ink)}
.legal-toc{background:var(--gray-100);border-radius:var(--r-lg);padding:18px 20px;margin-bottom:8px}
.legal-toc h2{margin:0 0 10px;font-size:14px;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-500)}
.legal-toc ol{margin:0 0 0 20px;font-size:13.5px}
.legal-toc a{color:var(--gray-700);font-weight:600}
.legal-toc a:hover{color:var(--purple)}

footer.legal-footer{border-top:1px solid var(--gray-100);padding:24px;text-align:center;font-size:12px;color:var(--gray-500)}
footer.legal-footer a{color:var(--gray-500)}
</style>
</head>
<body>

<div class="legal-nav">
  <div class="legal-nav-inner">
    <a href="/index.php" class="logo">
      <span class="logo-mark"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="#4F43D4"/><rect x="14" y="36" width="36" height="4" fill="#4F43D4"/></svg></span>
      <span class="logo-word">kweek</span>
    </a>
    <a href="/index.php" class="back"><i class="ti ti-arrow-left"></i> Back to home</a>
  </div>
</div>

<div class="legal-wrap">
  <div class="legal-header">
    <h1>Privacy Policy</h1>
    <div class="updated">Last updated: <strong>August 26, 2026</strong></div>
    <div class="legal-notice">
      <i class="ti ti-info-circle"></i> This policy is a working draft based on how Kweek's systems actually
      process data today. Before you publish this on a live product handling BVN and payment data, have it
      reviewed by a lawyer familiar with the Nigeria Data Protection Act (2023) and CBN consumer-protection
      requirements — this text is not a substitute for that review.
    </div>
  </div>

  <div class="legal-toc">
    <h2>Contents</h2>
    <ol>
      <li><a href="#who-we-are">Who we are</a></li>
      <li><a href="#info-we-collect">Information we collect</a></li>
      <li><a href="#kyc">BVN and identity verification (KYC)</a></li>
      <li><a href="#how-we-use">How we use your information</a></li>
      <li><a href="#sharing">Who we share information with</a></li>
      <li><a href="#cookies">Cookies and similar technologies</a></li>
      <li><a href="#push">Push notifications</a></li>
      <li><a href="#retention">Data retention</a></li>
      <li><a href="#security">How we protect your information</a></li>
      <li><a href="#rights">Your rights</a></li>
      <li><a href="#children">Children's privacy</a></li>
      <li><a href="#changes">Changes to this policy</a></li>
      <li><a href="#contact">Contact us</a></li>
    </ol>
  </div>

  <div class="legal-body">
    <h2 id="who-we-are">1. Who we are</h2>
    <p>Kweek is operated by Kweek Technologies Ltd ("Kweek", "we", "us", "our"), a company based in Lagos, Nigeria. Kweek provides a dashboard that lets merchants create payment links, track orders, and manage customers. This policy explains what personal data we collect from merchants who use our dashboard ("Merchants") and from the customers who pay through a Kweek payment link ("Customers"), and what we do with it.</p>

    <h2 id="info-we-collect">2. Information we collect</h2>
    <h3>From merchants (account holders)</h3>
    <ul>
      <li>Account details: full name, email address, phone number, password (stored as a one-way hash, never in plain text).</li>
      <li>Business details you provide during onboarding: business type and how you heard about us.</li>
      <li>Team member details, where you invite others to your account: name, email, phone, and role.</li>
      <li>Identity verification data, where you complete KYC: your Bank Verification Number (BVN) and the name/date of birth associated with it — see Section 3.</li>
      <li>Payment link and order content you create: item names, prices, descriptions, delivery zones, and any images you upload.</li>
      <li>Transaction and wallet data: balances, transaction history, withdrawal requests, and linked payout bank account details.</li>
      <li>Technical data: IP address, device/browser information, and approximate login activity, used for rate-limiting, fraud prevention, and account security (e.g. the "remember me" and session cookies described in Section 6).</li>
    </ul>
    <h3>From customers (people paying a merchant)</h3>
    <ul>
      <li>Name, phone number, and, optionally, email address, entered on a payment page to complete an order.</li>
      <li>Delivery address and delivery zone, where applicable.</li>
      <li>Payment details are collected and processed directly by our payment gateway partner (see Section 5) — Kweek does not receive or store your full card number or bank login details.</li>
      <li>Messages sent through the in-page chat widget on a payment link, and a device-generated chat token used to keep your conversation with the merchant private.</li>
    </ul>

    <h2 id="kyc">3. BVN and identity verification (KYC)</h2>
    <p>To comply with Central Bank of Nigeria (CBN) and anti-money-laundering (AML) requirements, some payouts and account activity require identity verification against your Bank Verification Number (BVN). When you submit a BVN for verification:</p>
    <ul>
      <li>We send it to our licensed verification partner to confirm the name, and date of birth on file match the details on your Kweek account and, where applicable, your payout bank account.</li>
      <li>We store the outcome of each verification attempt (pass/fail and a partial, masked reference to the BVN used) so we can enforce attempt limits and investigate disputes — we do not store your full BVN in plain text in application logs.</li>
      <li>Repeated failed verification attempts may result in your payout ability being temporarily locked, consistent with our fraud-prevention obligations.</li>
      <li>We do not use your BVN for any purpose other than identity verification, fraud prevention, and regulatory compliance.</li>
    </ul>

    <h2 id="how-we-use">4. How we use your information</h2>
    <ul>
      <li>To create and operate your merchant account, and the payment pages your customers use.</li>
      <li>To process payments, verify transactions, and settle funds to your wallet or payout account.</li>
      <li>To verify identity and meet our obligations under CBN, AML, and KYC regulations.</li>
      <li>To send you transactional notifications — new orders, payment confirmations, plan and downgrade notices, and chat messages — by email and, where you've enabled it, push notification.</li>
      <li>To detect and prevent fraud, abuse, and unauthorized account access (for example, rate-limiting login and password-reset attempts).</li>
      <li>To provide customer support when you contact us.</li>
      <li>To improve the reliability and usability of the dashboard.</li>
    </ul>

    <h2 id="sharing">5. Who we share information with</h2>
    <p>We do not sell personal data. We share information only where necessary to run the service:</p>
    <ul>
      <li><strong>Payment processing:</strong> transaction and checkout details are shared with our payment gateway partner to process and settle payments. Card and bank-transfer credentials are handled directly by the gateway under its own security standards (e.g. PCI-DSS) — Kweek does not see or store full card numbers.</li>
      <li><strong>Identity verification:</strong> BVN details are shared with our licensed KYC/verification partner as described in Section 3.</li>
      <li><strong>Email delivery:</strong> transactional emails (verification, password reset, receipts, notifications) are sent through our email delivery provider.</li>
      <li><strong>Push notifications:</strong> if you enable push notifications, your device's push subscription is registered with the browser/OS push service (e.g. Google's or Apple's push infrastructure) that delivers the notification to your device — see Section 7.</li>
      <li><strong>Legal and regulatory disclosure:</strong> where required to comply with a legal obligation, court order, or a lawful request from the CBN or another regulator.</li>
      <li><strong>Business transfers:</strong> if Kweek is involved in a merger, acquisition, or asset sale, customer and merchant data may be transferred as part of that transaction, subject to this policy.</li>
    </ul>

    <h2 id="cookies">6. Cookies and similar technologies</h2>
    <p>We use a small number of cookies required for the dashboard to function:</p>
    <ul>
      <li>A <strong>session cookie</strong> that keeps you signed in while you use the dashboard. It's marked HTTP-only and Secure, and is not accessible to page scripts.</li>
      <li>An optional <strong>"remember me" cookie</strong>, set only if you tick "Remember me" at login, that keeps you signed in for up to 30 days on that device.</li>
      <li>A <strong>CSRF token</strong> stored in your session, used to confirm that form submissions genuinely came from your browser.</li>
    </ul>
    <p>We do not currently use third-party advertising or analytics cookies.</p>

    <h2 id="push">7. Push notifications</h2>
    <p>If you install Kweek to your home screen and opt in to notifications, your browser generates a push subscription (a unique, revocable endpoint) which we store against your account so we can deliver time-sensitive alerts — new orders, payment confirmations, and chat messages — even when the dashboard isn't open. You can withdraw this permission at any time from your device or browser notification settings, or from within Kweek's notification preferences; doing so deletes the stored subscription.</p>

    <h2 id="retention">8. Data retention</h2>
    <p>We retain account and transaction data for as long as your account is active, and afterwards for as long as required to meet our legal, tax, and AML record-keeping obligations (which can extend for several years after account closure under Nigerian financial regulations). Chat messages and order details are retained for as long as the related order or account exists, unless you request earlier deletion and we are not otherwise required to keep it.</p>

    <h2 id="security">9. How we protect your information</h2>
    <ul>
      <li>Passwords are hashed (bcrypt) and never stored or logged in plain text.</li>
      <li>All traffic to and from Kweek is encrypted in transit (HTTPS/TLS).</li>
      <li>Access to production data is restricted to personnel who need it to operate the service.</li>
      <li>We apply rate-limiting on login, registration, and password-reset flows to reduce automated abuse.</li>
    </ul>
    <p>No system is 100% secure, and we can't guarantee absolute security — but we take reasonable, industry-standard steps to protect your data and will notify affected users as required by law in the event of a data breach affecting their personal information.</p>

    <h2 id="rights">10. Your rights</h2>
    <p>Subject to applicable law (including the Nigeria Data Protection Act 2023), you may have the right to:</p>
    <ul>
      <li>Request a copy of the personal data we hold about you.</li>
      <li>Ask us to correct inaccurate or incomplete data.</li>
      <li>Ask us to delete your data, subject to our legal and regulatory record-keeping obligations described in Section 8.</li>
      <li>Withdraw consent for optional processing (e.g. push notifications) at any time.</li>
      <li>Object to certain processing or ask us to restrict it.</li>
    </ul>
    <p>To exercise any of these rights, contact us using the details in Section 13. We may need to verify your identity before acting on a request.</p>

    <h2 id="children">11. Children's privacy</h2>
    <p>Kweek is intended for business use by adults. We do not knowingly collect personal data from anyone under the age of 18. If you believe a child has provided us with personal data, please contact us so we can delete it.</p>

    <h2 id="changes">12. Changes to this policy</h2>
    <p>We may update this policy from time to time to reflect changes in our practices or legal requirements. We'll update the "Last updated" date above, and where a change is material, we'll take reasonable steps to notify merchants (for example, by email or an in-dashboard notice).</p>

    <h2 id="contact">13. Contact us</h2>
    <p>Questions about this policy or how we handle your data can be sent to <a href="mailto:support@kweek.ng">support@kweek.ng</a>.</p>
  </div>
</div>

<footer class="legal-footer">
  <span>© 2026 Kweek Technologies Ltd · Lagos, Nigeria · </span>
  <a href="/terms.php">Terms of Service</a>
</footer>

</body>
</html>
