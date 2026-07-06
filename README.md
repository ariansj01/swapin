# Swapin (سواپین)

**Smart barter marketplace** — an online swap platform with an internal credit wallet, secure trades, identity verification, and an AI assistant.

> Swapin is a Persian (RTL) barter marketplace built with plain PHP and MySQL — no framework required.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Database Migrations](#database-migrations)
- [Demo Data](#demo-data)
- [Admin Account](#admin-account)
- [Project Structure](#project-structure)
- [Pages & Routes](#pages--routes)
- [API](#api)
- [AI Assistant](#ai-assistant)
- [Admin Panel](#admin-panel)
- [Production Security](#production-security)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)

---

## Overview

**Swapin (سواپین)** lets users **trade goods and services directly** — without relying on cash. The platform includes:

- Listing creation and browsing for barter deals
- Trade offers and a full trade lifecycle (contract, escrow, shipping)
- Internal credit wallet (unit: **Toman**)
- KYC verification and trust score (Swap Score)
- Bronze / Silver / Gold subscriptions and listing promotion (Bump / Feature)
- Store panel for professional sellers
- AI assistant for pricing and trade matching
- Support tickets, bug reports, and fraud prevention guides

---

## Features

### Users

| Area | Description |
|------|-------------|
| **Listings** | Create, edit, save (favorites), bump/feature, expert inspection |
| **Trades** | Offers, acceptance, digital contract, Escrow, BNPL, disputes |
| **Wallet** | Credit balance, demo deposit, transaction history |
| **KYC** | National ID, bank account (Sheba), ID card image |
| **Subscription** | Bronze / Silver / Gold plans with listing limits and bumps |
| **Messaging** | In-app chat |
| **Support** | Tickets, bug reports, floating support widget |
| **AI** | Assistant chat, smart matching, price estimation |

### Admin

- Approve / reject listings
- Review KYC submissions
- Expert inspection queue
- Resolve trade disputes
- Support tickets and bug reports
- User management

---

## Requirements

| Requirement | Recommended Version |
|-------------|---------------------|
| PHP | 8.1+ (with PDO MySQL) |
| MySQL / MariaDB | 8.0+ / 10.6+ |
| Apache | with `mod_rewrite` (optional) |
| Composer | **Required for email** — run `composer install` for PHPMailer |

Recommended PHP extensions:

- `pdo_mysql`
- `mbstring`
- `fileinfo` (image uploads)
- `json`
- `curl` (AI API)

---

## Quick Start

### 1. Clone the project

```bash
git clone https://github.com/YOUR_USERNAME/swaapin.git
cd swaapin
```

### 2. Database

Create a database in phpMyAdmin or MySQL CLI:

```sql
CREATE DATABASE kala_b_kala CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then import the **base schema**:

```bash
# Full dump file:
laasztzg_kala_b_kala.sql
```

### 3. Configuration

Edit `includes/config.php`:

```php
define('APP_URL', 'http://localhost/swaapin');  // your actual site URL
define('DB_HOST', 'localhost');
define('DB_NAME', 'kala_b_kala');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 4. Upload directory

```bash
mkdir uploads
chmod 755 uploads   # Linux/macOS
```

> The `uploads/` folder is in `.gitignore` — it must be writable on the server.

### 5. Run migrations

Execute in order:

```bash
# Windows (XAMPP) — all migrations in one command:
c:\xampp\php\php.exe run_all_migrations.php

# Or step by step:
c:\xampp\php\php.exe run_migration.php
c:\xampp\php\php.exe run_admin_migration.php
c:\xampp\php\php.exe run_support_migration.php
c:\xampp\php\php.exe run_wallet_migration.php

# Linux / macOS
php run_all_migrations.php
```

Persian category labels (optional):

```bash
php run_categories_fa.php
```

### 6. AI (optional)

```bash
cp includes/ai_secrets.example.php includes/ai_secrets.php
```

Add your [Groq API](https://console.groq.com/) key in `includes/ai_secrets.php`.

### 7. Email — PHPMailer + EmailJS (optional)

```bash
composer install
cp includes/mail_secrets.example.php includes/mail_secrets.php
```

| Provider | Use case | Config |
|----------|----------|--------|
| **PHPMailer (SMTP)** | Server emails: support alerts, ticket replies, contact form fallback | `MAIL_ENABLED = true` + SMTP settings |
| **EmailJS** | Client-side contact form (`contact.php`) | `EMAILJS_ENABLED = true` + public key, service ID, template ID |

EmailJS template variables: `from_name`, `from_email`, `subject`, `message`, `reply_to`, `to_name`.

When EmailJS is enabled, the contact form sends from the browser; a copy is logged via `api/contact.php`. When only PHPMailer is enabled, the form posts to PHP and sends via SMTP.

### 8. OTP SMS via IranPayamak (optional)

```bash
cp includes/sms_secrets.example.php includes/sms_secrets.php
```

Then set these values in `includes/sms_secrets.php`:

- `SMS_ENABLED = true`
- `SMS_IRANPAYAMAK_API_KEY`
- `SMS_IRANPAYAMAK_PATTERN_CODE`
- `SMS_IRANPAYAMAK_LINE_NUMBER`
- `SMS_OTP_ATTRIBUTE_MAP` مطابق متغیرهای پترن OTP شما

### 9. Open in browser

```
http://localhost/swaapin/
```

---

## Configuration

Main settings in `includes/config.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | سواپین | Persian app name |
| `APP_URL` | — | **Must** be updated in production |
| `CREDIT_UNIT` | تومان | Wallet unit |
| `WELCOME_BONUS` | 10,000,000 | Welcome credit bonus |
| `PLATFORM_FEE_RATE` | 0.02 | 2% fee on successful trades |
| `MAX_IMAGES` | 8 | Max images per listing |
| `LISTINGS_PER_PAGE` | 12 | Listings per page |
| `ADMIN_EMAIL` | admin@… | Admin account email |
| `ADMIN_DEFAULT_PASS` | 1234 | **Change in production** |

---

## Database Migrations

| File | Script | Contents |
|------|--------|----------|
| `laasztzg_kala_b_kala.sql` | manual import | Base tables: users, listings, trades, trade_offers, messages, … |
| `migration_v2.sql` | `run_migration.php` | KYC columns, escrow, BNPL, subscriptions, bump, **`disputes`**, **`inspection_requests`** |
| `migration_admin.sql` | `run_admin_migration.php` | **`users.role`**, **`listings.review_status`**, listing moderation (requires v2 first) |
| `migration_support.sql` | `run_support_migration.php` | **`support_tickets`**, **`support_messages`**, **`error_reports`** |
| `migration_wallet.sql` | `run_wallet_migration.php` | Wallet **`ref_type`**, **`listing_id`**, **`trade_id`**, **`currency_code`**, **`currency`** |
| `categories_fa.sql` | `run_categories_fa.php` | Persian category labels |

Migration scripts ignore "column/table already exists" errors and are safe to re-run.

**Admin panel pages and their tables**

| Admin page | Required tables / columns |
|------------|----------------------------|
| `admin/disputes.php` | `disputes` (from v2 migration) |
| `admin/tickets.php` | `support_tickets`, `support_messages`, `error_reports` (support migration) |
| `admin/inspections.php` | `inspection_requests` (v2 migration) |
| `admin/kyc.php` | `users.kyc_status`, `national_id`, `bank_account`, … (v2 migration) |
| `admin/listings.php` | `listings.review_status`, `review_note` (admin migration) |
| `admin/users.php` | `users.role` (admin migration) |

If `disputes.php` or `tickets.php` show SQL errors, run `php run_all_migrations.php`.

### Wallet transaction references

| Column | Meaning |
|--------|---------|
| `ref_type` | Which table `ref_id` belongs to (`trade`, `listing`, `subscription_order`, …) |
| `ref_id` | Primary key inside that table (not ambiguous anymore) |
| `trade_id` | Direct FK to `trades.id` for trade-related rows |
| `listing_id` | Direct FK to `listings.id` — query without joining through offers |
| `currency_code` | ISO code (`IRT` — ledger amounts are in Toman) |
| `currency` | Display label (`تومان`) |

`trade_offers` links (`listing_id`, `from_user_id`, `offer_listing_id`) exist in the **base** schema; wallet rows link to listings/trades explicitly after the wallet migration.

---

## Demo Data

```bash
php seed/seed_demo.php
# Remove previous demo data and re-seed:
php seed/seed_demo.php --reset
```

| Field | Value |
|-------|-------|
| Email | `demo.user1@swapin.local` |
| Password | `Demo1234!` |

---

## Admin Account

### Method 1 — Admin migration

After `run_admin_migration.php`, the user with `ADMIN_EMAIL` gets the admin role.

### Method 2 — Local install page

```
http://localhost/swaapin/admin/install.php
```

> This page works **only on localhost**. Remove or restrict it in production.

### Admin login

```
http://localhost/swaapin/admin/login.php
```

Defaults (from config):

- **Email:** `admin@kalabkala.com`
- **Password:** `1234`

---

## Project Structure

```
swaapin/
├── admin/              # Admin panel
├── ai/                 # AI chat UI
├── api/                # JSON endpoints
├── auth/               # Login, register, logout
├── includes/           # config, layout, business logic
│   ├── config.php      # Settings + DB + auth
│   ├── layout.php      # Shared HTML layout
│   ├── v2.php          # Subscriptions, escrow, swap score
│   ├── admin.php       # Admin helpers
│   ├── support.php     # Tickets & bug reports
│   ├── ai.php          # Groq API
│   └── listing_validator.php
├── listings/           # Listing CRUD, bump, saved
├── profile/            # Profile edit / KYC
├── seed/               # Demo data
├── src/
│   ├── css/            # main.css + admin.css
│   ├── js/             # app.js
│   └── img/            # Logo, favicon
├── store/              # Store seller panel
├── support/            # User support tickets
├── uploads/            # Uploaded images (gitignore)
├── index.php           # Home + listing browse
├── dashboard.php       # User dashboard
├── trades.php          # Trades
├── wallet.php          # Wallet
├── subscription.php    # Subscriptions
├── fraud-prevention.php
├── sitemap.php
├── robots.txt
└── migration_*.sql     # Migration files
```

---

## Pages & Routes

### Public

| Route | Description |
|-------|-------------|
| `/` | Home — listing browse |
| `/about.php` | About us |
| `/contact.php` | Contact |
| `/fraud-prevention.php` | Security guide |
| `/listings/view.php?id=` | Listing detail |
| `/profile.php?id=` | Public profile |

### Authenticated users

| Route | Description |
|-------|-------------|
| `/dashboard.php` | Dashboard |
| `/listings/create.php` | Create listing |
| `/listings/my.php` | My listings |
| `/listings/saved.php` | Saved listings |
| `/listings/bump.php` | Promote listing |
| `/trades.php` | Trades |
| `/wallet.php` | Wallet |
| `/subscription.php` | Subscription |
| `/messages.php` | Messages |
| `/support/index.php` | Support |
| `/support/report.php` | Bug report |
| `/ai/chat.php` | AI assistant |
| `/store/index.php` | Store panel |

### Admin

| Route | Description |
|-------|-------------|
| `/admin/` | Dashboard |
| `/admin/listings.php` | Listing approval |
| `/admin/kyc.php` | KYC review |
| `/admin/inspections.php` | Inspections |
| `/admin/disputes.php` | Disputes |
| `/admin/tickets.php` | Support + bug reports |
| `/admin/users.php` | Users |

---

## API

JSON endpoints in the `api/` folder:

| File | Method | Description |
|------|--------|-------------|
| `save_listing.php` | POST | Save / unsave listing |
| `notifications.php` | GET | Notifications |
| `review.php` | POST | Submit review |
| `ai_chat.php` | POST | AI chat |
| `ai_match.php` | POST | Smart matching |
| `ai_valuate.php` | POST | Price estimation |

Most endpoints require an active session (logged-in user).

---

## AI Assistant

- **Provider:** [Groq](https://groq.com/) (default model: `llama-3.3-70b-versatile`)
- **Config:** `includes/ai_secrets.php` (copy from `ai_secrets.example.php`)
- **System prompt:** `includes/ai_system_prompt.txt`

Without an API key, AI features are disabled or show an error message.

---

## Admin Panel

```
/admin/login.php
```

Capabilities:

- **Listings** — approval queue (`review_status`: pending → approved/rejected)
- **KYC** — review user documents
- **Inspections** — expert inspection requests
- **Disputes** — resolve trade disputes (including fraud reason)
- **Support** — reply to tickets + manage bug reports
- **Users** — activate / deactivate accounts

---

## Production Security

Before deploying:

1. Set **`APP_URL`** to your real domain
2. Change **`ADMIN_DEFAULT_PASS`** and run `admin_sync_credentials()`
3. Disable **`display_errors`** in `config.php`:
   ```php
   ini_set('display_errors', 0);
   error_reporting(E_ALL);
   ini_set('log_errors', 1);
   ```
4. Never commit **`includes/ai_secrets.php`** (listed in `.gitignore`)
5. Remove or IP-restrict **`admin/install.php`**
6. **`uploads/`** — writable only by the web server
7. Update **`robots.txt`** Sitemap URL to your real domain
8. Enable HTTPS and secure session cookies

Ignored files:

```
includes/ai_secrets.php
uploads/*
```

---

## Troubleshooting

### Database connection

```
http://localhost/swaapin/test_db.php
```

> Delete this file in production.

### Migration errors

- Ensure `laasztzg_kala_b_kala.sql` is imported first
- Run migrations **in order**: v2 → admin → support → wallet (or `php run_all_migrations.php`)

### Images not showing

- Ensure `uploads/` exists and is writable
- Verify `UPLOAD_DIR` and `UPLOAD_URL` in config

### AI not working

- Create `includes/ai_secrets.php`
- Use a valid Groq API key
- Enable `curl` in PHP

---

## Contributing

1. Fork the repository
2. Create a branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## Tech Stack

- **Backend:** PHP 8 (plain, no framework)
- **Database:** MySQL + PDO
- **Frontend:** HTML/CSS/JS — RTL, Vazirmatn font
- **Icons:** Bootstrap Icons 1.11
- **AI:** Groq API (Llama)

---

## License

No license specified yet. Define ownership and terms before commercial use.

---

## Contact

- **Web:** [swapin.ir](https://swapin.ir) *(if live)*
- **Email:** info@swapin.ir

---

<p align="center">
  Built with ❤️ for barter economy in Iran
</p>
