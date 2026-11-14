<?php
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terms of Service — Kweek</title>
<meta name="description" content="The terms that govern your use of Kweek.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://kweek.ng/terms.php">
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
    <h1>Terms of Service</h1>
    <div class="updated">Last updated: <strong>August 26, 2026</strong></div>
    <div class="legal-notice">
      <i class="ti ti-info-circle"></i> This is a working draft written to match how the Kweek product actually
      behaves (plans, fees, link pausing, KYC lockouts, and so on). Because Kweek moves money and performs
      KYC, have this reviewed by a lawyer before it governs real transactions — particularly the liability,
      indemnity, and dispute-resolution sections.
    </div>
  </div>

  <div class="legal-toc">
    <h2>Contents</h2>
    <ol>
      <li><a href="#acceptance">Acceptance of terms</a></li>
      <li><a href="#eligibility">Eligibility and account registration</a></li>
      <li><a href="#services">Description of services</a></li>
      <li><a href="#fees">Fees, plans, and billing</a></li>
      <li><a href="#kyc">Identity verification and compliance</a></li>
      <li><a href="#acceptable-use">Acceptable use</a></li>
      <li><a href="#customer-orders">Customer orders and disputes</a></li>
      <li><a href="#team">Team accounts</a></li>
      <li><a href="#suspension">Suspension and termination</a></li>
      <li><a href="#disclaimers">Disclaimers</a></li>
      <li><a href="#liability">Limitation of liability</a></li>
      <li><a href="#indemnity">Indemnification</a></li>
      <li><a href="#governing-law">Governing law and disputes</a></li>
      <li><a href="#changes">Changes to these terms</a></li>
      <li><a href="#contact">Contact us</a></li>
    </ol>
  </div>

  <div class="legal-body">
    <h2 id="acceptance">1. Acceptance of terms</h2>
    <p>These Terms of Service ("Terms") govern your access to and use of Kweek, provided by Kweek Technologies Ltd ("Kweek", "we", "us", "our"). By creating an account or using our payment links, you agree to these Terms and to our <a href="/privacy-policy.php">Privacy Policy</a>. If you don't agree, please don't use Kweek.</p>

    <h2 id="eligibility">2. Eligibility and account registration</h2>
    <ul>
      <li>You must be at least 18 years old and legally capable of entering into a binding contract to register a Kweek account.</li>
      <li>You agree to provide accurate, current information during registration and onboarding, and to keep it up to date.</li>
      <li>You're responsible for maintaining the confidentiality of your login credentials and for all activity that happens under your account, including activity by team members you invite.</li>
      <li>You must notify us promptly at <a href="mailto:support@kweek.ng">support@kweek.ng</a> if you suspect unauthorized access to your account.</li>
    </ul>

    <h2 id="services">3. Description of services</h2>
    <p>Kweek provides a dashboard that lets you create payment links, receive payments from customers, track orders, manage customer records, and withdraw collected funds to a linked bank account, subject to your plan's limits described in Section 4. Kweek is not a bank; funds shown as your wallet balance represent amounts collected on your behalf and held pending withdrawal, processed through our licensed payment gateway partner.</p>

    <h2 id="fees">4. Fees, plans, and billing</h2>
    <ul>
      <li>Kweek offers Starter, Pro, and Business plans, each with its own transaction fee percentage, monthly transaction volume cap, and withdrawal fee, as shown on our <a href="/index.php#pricing">pricing page</a> at the time of purchase.</li>
      <li>New paid-plan subscriptions include a 7-day trial from the start date; you will not be charged until the trial ends unless you cancel first.</li>
      <li>Transaction fees are deducted automatically from each payment before it's credited to your wallet.</li>
      <li>If your monthly transaction volume reaches your plan's cap, further payments may be declined until the cap resets or you upgrade.</li>
      <li><strong>If your plan expires or is downgraded</strong> (including to the Free plan), and the number of your active payment links exceeds your new plan's limit, we will automatically pause your excess links — starting with your lowest-performing links by revenue and order count — rather than delete them. You'll be notified when this happens and can reactivate paused links by upgrading again.</li>
      <li>You can change or cancel your plan at any time from your dashboard; downgrades take effect as described above.</li>
    </ul>

    <h2 id="kyc">5. Identity verification and compliance</h2>
    <ul>
      <li>To comply with CBN and anti-money-laundering regulations, we may require you to verify your identity (including via BVN) before you can withdraw funds or before certain account activity is permitted.</li>
      <li>The name on your payout bank account must reasonably match the identity information on file; we use automated name-matching to check this, and may decline a payout or ask for further verification where it doesn't.</li>
      <li>Repeated failed verification attempts (currently capped at five) will result in your account's payout ability being locked pending manual review. This is a fraud-prevention control, not a penalty, and you can contact support to resolve it.</li>
      <li>We may suspend or restrict your account if required to do so by a regulator or law enforcement request, or where we reasonably suspect fraudulent or illegal activity.</li>
    </ul>

    <h2 id="acceptable-use">6. Acceptable use</h2>
    <p>You agree not to use Kweek to:</p>
    <ul>
      <li>Sell or collect payment for illegal goods or services, or goods/services prohibited under Nigerian law or our payment gateway partner's acceptable-use policy (including firearms, controlled substances, counterfeit goods, and unlicensed financial services).</li>
      <li>Commit or facilitate fraud, money laundering, or any other financial crime.</li>
      <li>Impersonate another person or business, or misrepresent your affiliation with any person or entity.</li>
      <li>Attempt to interfere with, disrupt, or gain unauthorized access to Kweek's systems, or use the chat widget or any other feature to send spam, malware, or abusive content.</li>
      <li>Circumvent transaction limits or fees through structuring, splitting payments, or similar means.</li>
    </ul>
    <p>We may suspend or terminate accounts that violate this section, and may report suspected illegal activity to the relevant authorities.</p>

    <h2 id="customer-orders">7. Customer orders and disputes</h2>
    <p>Kweek provides the payment and order-tracking infrastructure; the contract for the goods or services sold is between you (the merchant) and your customer. You are responsible for:</p>
    <ul>
      <li>Accurately describing what you're selling on each payment link.</li>
      <li>Fulfilling orders customers have paid for, or issuing refunds through your own arrangement with the customer where appropriate.</li>
      <li>Responding to customer questions sent through the in-page chat widget in a reasonable time.</li>
    </ul>
    <p>Kweek is not a party to the underlying sale and is not responsible for the quality, safety, legality, or delivery of goods or services sold through a payment link, except to the extent required by applicable consumer-protection law.</p>

    <h2 id="team">8. Team accounts</h2>
    <p>Account owners may invite team members with defined roles (admin, manager, or viewer) and corresponding permissions. Actions restricted to the account owner — such as billing changes, withdrawals, and account closure — cannot be delegated to team members regardless of role. You are responsible for the actions of team members you invite.</p>

    <h2 id="suspension">9. Suspension and termination</h2>
    <ul>
      <li>You may close your account at any time by contacting support, subject to settlement of any pending transactions and our regulatory record-keeping obligations.</li>
      <li>We may suspend or terminate your account, with or without notice, if we reasonably believe you've violated these Terms, engaged in fraudulent or illegal activity, or where required by a regulator.</li>
      <li>Suspension may include withholding withdrawal of wallet funds pending investigation, where reasonably necessary for fraud or compliance review.</li>
    </ul>

    <h2 id="disclaimers">10. Disclaimers</h2>
    <p>Kweek is provided "as is" and "as available." To the fullest extent permitted by law, we disclaim all warranties, express or implied, including warranties of merchantability, fitness for a particular purpose, and non-infringement. We don't guarantee that the service will be uninterrupted, error-free, or completely secure.</p>

    <h2 id="liability">11. Limitation of liability</h2>
    <p>To the fullest extent permitted by applicable law, Kweek and its officers, employees, and partners will not be liable for any indirect, incidental, special, or consequential damages, or for loss of profits, revenue, or data, arising from your use of the service. Our total liability for any claim arising from these Terms or your use of Kweek will not exceed the total fees you paid to Kweek in the 3 months preceding the event giving rise to the claim. Nothing in these Terms limits liability that cannot be limited under applicable Nigerian law.</p>

    <h2 id="indemnity">12. Indemnification</h2>
    <p>You agree to indemnify and hold Kweek harmless from any claims, damages, losses, or expenses (including reasonable legal fees) arising from your use of the service, your violation of these Terms, or your violation of any law or the rights of a third party, including a customer dispute arising from goods or services you sold through a Kweek payment link.</p>

    <h2 id="governing-law">13. Governing law and disputes</h2>
    <p>These Terms are governed by the laws of the Federal Republic of Nigeria. Any dispute arising from these Terms or your use of Kweek will first be addressed through good-faith negotiation between the parties; if unresolved, it will be subject to the exclusive jurisdiction of the courts of Lagos State, Nigeria.</p>

    <h2 id="changes">14. Changes to these terms</h2>
    <p>We may update these Terms from time to time. We'll update the "Last updated" date above, and for material changes, we'll take reasonable steps to notify you (for example, by email or an in-dashboard notice) before they take effect. Continued use of Kweek after a change takes effect constitutes acceptance of the updated Terms.</p>

    <h2 id="contact">15. Contact us</h2>
    <p>Questions about these Terms can be sent to <a href="mailto:support@kweek.ng">support@kweek.ng</a>.</p>
  </div>
</div>

<footer class="legal-footer">
  <span>© 2026 Kweek Technologies Ltd · Lagos, Nigeria · </span>
  <a href="/privacy-policy.php">Privacy Policy</a>
</footer>

</body>
</html>
