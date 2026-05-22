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
