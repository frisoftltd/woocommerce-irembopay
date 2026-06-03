# WooCommerce IremboPay Gateway

A production-ready WordPress plugin that integrates **IremboPay** as a payment gateway for WooCommerce — including a **built-in subscription engine** for Tutor LMS courses and bundles. No WooCommerce Subscriptions plugin required.

---

## ✨ Features

- 💳 **IremboPay inline checkout modal** — no redirect to a third-party page
- 🔄 **Built-in recurring payments** — monthly, weekly, yearly billing without WooCommerce Subscriptions
- 📚 **Tutor LMS integration** — single courses, course bundles, enrollment & access control
- 📧 **Renewal emails** — automatic payment reminders sent to students
- ⏱️ **Grace period** — configurable days before access is suspended
- 🔔 **Webhook handler** — auto-completes orders and subscriptions on payment confirmation
- 🗂️ **Admin subscription list** — manage all subscriptions from WooCommerce menu
- 📝 **WooCommerce HPOS compatible** — works with High Performance Order Storage
- 🐞 **Native WC Logger** — all events logged under WooCommerce → Status → Logs

---

## 📋 Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8+ |
| WooCommerce | 6.0+ |
| PHP | 7.4+ |
| Tutor LMS *(optional)* | Any |

---

## 🚀 Installation

### Option A — Upload via WordPress Admin
1. Download the latest `.zip` from [Releases](../../releases)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and click **Activate**

### Option B — Install via Git
```bash
cd wp-content/plugins
git clone https://github.com/YOUR_USERNAME/woocommerce-irembopay.git
```
Then activate from **Plugins → Installed Plugins**.

---

## ⚙️ Configuration

Go to **WooCommerce → Settings → Payments → IremboPay**:

| Setting | Description |
|---|---|
| **Secret Key** | Your IremboPay private/secret key |
| **Public Key** | Your IremboPay public key (used in the JS modal) |
| **Payment Account Identifier** | e.g. `TANGNEST_RWF` |
| **Default Product Code** | IremboPay product code for line items |
| **Test Mode** | Toggle sandbox vs live |

### Webhook URL
Copy this URL into your IremboPay merchant dashboard:
```
https://yourstore.com/wp-json/irembopay/v1/webhook
```

---

## 🔄 Subscription Setup

1. Edit any WooCommerce product
2. Click the **IremboPay Subscription** tab
3. Check **Enable Subscription**
4. Set **Billing Cycle** (e.g. every 1 month)
5. Set **Grace Period** (days before access is suspended if unpaid)
6. Save — the product page will show *"RWF 10,000 / every month"*

### Subscription Lifecycle

```
Customer buys course product
       ↓
Webhook PAID → subscription created in DB
       ↓
WP-Cron fires daily → finds subscriptions due for renewal
       ↓
Creates IremboPay invoice + sends "Pay Now" email to student
       ↓
Student pays → Webhook PAID → subscription extended
       ↓ OR
Student misses grace period → access suspended automatically
```

### Admin Management
Go to **WooCommerce → Subscriptions (IremboPay)** to:
- View all subscriptions with status filters
- Pause / cancel / reactivate individual subscriptions
- Trigger a renewal invoice manually

---

## 📚 Tutor LMS Integration

When Tutor LMS is active the plugin automatically:

- Detects products linked to Tutor LMS courses and bundles
- Enrolls students on payment
- **Revokes course access** when a subscription expires or is cancelled
- **Restores course access** on reactivation or successful renewal
- Allows setting a per-course IremboPay product code on the course edit screen

---

## 📁 Plugin Structure

```
woocommerce-irembopay/
├── woocommerce-irembopay.php                      ← Main entry point
├── includes/
│   ├── class-irembopay-logger.php                 ← WC logger wrapper
│   ├── class-irembopay-api.php                    ← IremboPay HTTP client
│   ├── class-irembopay-webhook.php                ← REST webhook handler
│   ├── class-wc-gateway-irembopay.php             ← WC payment gateway
│   ├── class-irembopay-subscription-db.php        ← Custom DB table & CRUD
│   ├── class-irembopay-subscription-manager.php   ← Subscription business logic
│   ├── class-irembopay-subscription-cron.php      ← WP-Cron jobs
│   ├── class-irembopay-subscription-product.php   ← Product settings UI
│   ├── class-irembopay-tutor-integration.php      ← Tutor LMS bridge
│   └── admin/
│       └── class-irembopay-subscriptions-admin.php ← Admin list page
└── templates/
    └── payment-page.php                           ← Inline payment page
```

---

## 🔗 IremboPay API Reference

- Dashboard: [https://dashboard.irembopay.com](https://dashboard.irembopay.com)
- API Docs: [https://api.irembopay.com/docs](https://api.irembopay.com/docs)

---

## 📝 Changelog

### v2.6.0
- New: Expiry email sent to student when subscription expires — includes a "Restore Access — Pay Now" button with a fresh invoice link
- New: Parent WhatsApp contact field on every subscription row in the admin table
- New: WhatsApp message link auto-generated on renewal and expiry — click to open WhatsApp with message pre-written, then send manually
- New: 5-minute cron interval registered for testing renewal and expiry flows quickly
- Fix: Subscription expiry was previously silent — no student or parent notification was sent
- DB: `parent_whatsapp` column added to `wp_irembopay_subscriptions` table with auto-migration for existing installs

### v2.5.1
- Fix: Regular price field no longer hidden for subscription products
- Fix: Subscription price now displays correctly on course listing

### v2.5.0
- Removed: Subscription plan system (plans, plan selector, cart injection)
- Cleaned: Plugin returns to simple one-time and recurring payment flow
- Kept: Webhook handler, subscription renewal, cron jobs, admin UI

### v2.4.7
- Fix: Plan selector now inside WooCommerce form — plan price correctly passed to checkout
- Fix: Removed duplicate Subscribe button — WooCommerce button used directly
- Fix: Subscribe redirects directly to checkout
- Fix: Checkout now shows correct plan price instead of 0 Rwf

### v2.4.6
- Fix: Radio buttons restored on plan selector
- Fix: Subscribe button now shows below plan options
- Fix: Redirects to checkout instead of showing View Cart

### v2.4.5
- Fix: Plan selector JavaScript no longer renders as visible text on the page
- Fix: Variable conflict error resolved by moving JS to wp_footer

### v2.4.4
- New: Plan selector with radio buttons shown on course card and product page
- New: Selected plan highlighted with blue border
- New: First plan pre-selected by default

### v2.4.3
- Fix: Subscription products now show "Subscribe" button instead of "Read more"
- Fix: All plans now displayed on course card and product page

### v2.4.2
- Fix: Database tables now auto-created on plugin load, not just on activation
- Fix: Prevents silent save failures when plugin is deployed by copying files

### v2.4.1
- Fix: Subscription plan data now saves correctly
- Fix: Regular price and Sale price fields hidden when subscription is enabled

### v2.4.0
- New: Subscription plan system — each product supports up to 3 configurable plans
- New: Each plan has its own price, billing interval, total duration and grace period
- New: Students pick a plan at checkout; auto-billing runs until course is owned
- New: Course ownership granted automatically after all payments complete
- Removed: Installment payment system replaced by subscription plans

### v2.3.3
- Fix: Installment checkboxes now display as pill buttons, fully visible and properly spaced

### v2.3.2
- Fix: Installment checkboxes are now much larger and clearly readable with proper spacing

### v2.3.1
- Fix: Added spacing between "Every" label and billing cycle dropdown
- Fix: Installment checkboxes are now larger and have proper spacing between them

### v2.3.0
- New: Installment payment support — split course payments into 1, 2, 3, 4, or 6 installments
- New: Per-product installment configuration (enable and choose allowed options)
- New: Product-page dropdown lets customers choose their payment plan at add-to-cart
- New: Auto-billing cron creates IremboPay invoices for each installment as they come due
- New: Grace period reminders, overdue notices, access suspension and restoration emails
- New: Tutor LMS course access is suspended on missed installment and restored with end-date extension on late payment
- New: `wp_irembopay_installments` database table to track all installment rows
- Subscription renewal amount is always stored at full price regardless of installment split

### v2.2.6
- Fix: Paid orders now correctly move to Completed when using HPOS (High Performance Order Storage). Webhook handler was querying wp_postmeta instead of wp_wc_orders_meta, causing all webhooks to return "Order not found".

### v2.0.0
- Full plugin rewrite — proper multi-file structure
- Built-in subscription engine (no WooCommerce Subscriptions needed)
- Tutor LMS integration with enrollment/access control
- WP-Cron renewal processing
- Grace period + expiry logic
- Admin subscriptions management page
- WooCommerce HPOS compatibility
- Uses WordPress HTTP API (no raw cURL)
- Native WooCommerce logger

### v1.0.0
- Initial single-file release

---

## 🤝 Contributing

Pull requests are welcome. For major changes please open an issue first.

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add my feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Open a Pull Request

---

## 📄 License

GPL-2.0-or-later — see [LICENSE](LICENSE) file.
