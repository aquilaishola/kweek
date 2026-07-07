# Kweek

**WhatsApp-native payment collection for informal Nigerian merchants.**

Built for the Nomba Hackathon 2026.

---

## 💡 The Problem

Millions of informal merchants in Nigeria — WhatsApp sellers, market traders, small service providers — have no website, no POS terminal, and no easy way to prove a payment actually happened. Fake "payment alert" screenshots are a widespread scam, and setting up a proper online store is too much overhead for someone just trying to sell rice or hair products over WhatsApp chats.

## ✅ The Solution

Kweek gives any merchant a shareable payment link in seconds. Customers pay directly through Nomba's checkout (card, transfer, USSD), funds land in the merchant's Kweek wallet, and every successful payment produces a **cryptographically-backed, independently verifiable receipt** — so "have I been paid?" is never a matter of trusting a screenshot again.

No website. No POS. No code. Just a link.

---

## ✨ Features

- **Multiple payment link types** — simple fixed-amount links, menu/catalog links with per-item pricing, group order links, and installment payment plans
- **Delivery zone pricing** — merchants can attach delivery fees by zone directly to a payment link
- **Order confirmation flow** — merchants can require manual confirmation before a customer is sent to checkout (useful for made-to-order goods)
- **Nomba-powered checkout** — card, bank transfer, and USSD support via Nomba's hosted checkout
- **Server-verified webhooks** — every payment is independently re-confirmed via a direct API call to Nomba before a merchant's wallet is credited, so a forged webhook POST can never fake a payment
- **Verifiable receipts** — a public, shareable receipt page anyone can check by order reference or receipt code, confirming a payment is genuine
- **Wallet & withdrawals** — merchants withdraw straight to any Nigerian bank account, with live account-name resolution before sending funds
- **BVN/KYC verification** — required before withdrawal, to keep the platform compliant and reduce fraud
- **Orders dashboard** — full order lifecycle (pending confirmation → confirmed → paid → completed), with search, filters, and CSV export on transactions
- **Tiered plans** — Free / Pro / Business, each with its own transaction fee and monthly volume cap

---

## 🏗️ Tech Stack

- **Backend:** Plain PHP 8.1 + MySQL (no framework, no Composer dependencies) — built to run on standard shared cPanel hosting
- **Frontend:** Server-rendered PHP views, vanilla JS, hand-written CSS (no build step)
- **Payments:** [Nomba API](https://developer.nomba.com) — checkout, transfers, bank account resolution, webhooks
- **Hosting:** cPanel shared hosting, deployed via FTP

---

## 📁 Project Structure

```
├── admin/                  # Admin-facing tooling
├── src/                    # Supporting source assets
├── config/
│   └── db.sample.php   # Template config — copy to config/db.php and fill in real credentials
├── includes/
│   ├── functions.php       # Core helpers: auth, CSRF, wallet ops, notifications, rate limiting
│   ├── nomba.php           # Nomba API client — auth, checkout, transfers, webhook verification
│   ├── header.php          # Shared page header / navigation
│   └── footer.php          # Shared page footer
│   └── mailer.php          # email handler
├── pay.php                 # Public customer-facing checkout page (/pay/{slug})
├── receipt.php             # Public payment verification / receipt page (/receipt/{ref})
├── webhook.php             # Nomba webhook handler (payment + payout events)
├── dashboard.php           # Merchant dashboard
├── payment-links.php       # Create/manage payment links
├── create-link.php         # New payment link form
├── edit-link.php           # Edit existing payment link
├── orders.php              # Order management (confirm/decline/complete)
├── transactions.php        # Wallet transaction history + CSV export
├── withdraw.php            # Bank withdrawal flow
├── kyc.php                 # BVN verification
├── settings.php            # Profile, security, billing, notification preferences
├── login.php / register.php / logout.php / forgot-password.php
├── index.php               # Landing page
├── database.sql            # Full database schema
├── .htaccess                # URL rewriting, security headers, HTTPS enforcement
└── .gitignore
```

---

## ⚙️ Setup

1. Clone the repo and upload to your PHP hosting (or serve locally with a PHP 8.1+ environment)
2. Import `database.sql` into a MySQL database
3. Copy the config template and fill in your real credentials:
   ```bash
   cp config/config.sample.php config/db.php
   ```
   Then edit `config/db.php` with your database credentials, Nomba API keys ([get them here](https://developer.nomba.com/docs/getting-started/get-api-keys)), and SMTP details.
4. On your Nomba dashboard, register your webhook URL as `https://yourdomain.com/webhook.php` and subscribe to `payment_success`, `payment_failed`, `payout_success`, and `payout_failed` events
5. Point your web server's document root at the project folder — `.htaccess` handles HTTPS redirection, clean URLs, and blocking direct access to `config/` and `includes/`

**Note:** `config/db.php` is git-ignored on purpose — never commit real credentials. Use `config/config.sample.php` as the reference structure.

---

## 🔒 Security Notes

- All payments are re-verified server-to-server against Nomba's API before any wallet is credited — a spoofed webhook request cannot trigger a payout
- Passwords are hashed with bcrypt; sessions use `httponly`, `SameSite=Strict` cookies
- All database queries use prepared statements
- CSRF tokens are required on all state-changing forms
- Sensitive files (`config/`, `includes/`, `.env`, `.sql`, `.log`) are blocked at the web-server level via `.htaccess`

---

## 🎯 Hackathon Submission

Built solo for the **Nomba Hackathon 2026**, from scratch, over 6 days.

**Live demo:** https://d.schedwave.com
