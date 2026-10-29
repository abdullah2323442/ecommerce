# Siam's Aqua (Aqua Bazar)

**A multi-sector e-commerce platform for Bangladesh, built around a real pharmacy business.**
One site, many separate businesses: a pharmacy with wholesale and paikari ordering, a public marketplace, made-to-order furniture and clothing, food delivery, game top-ups and more. Each business has its own people, prices and rules, and a visitor never learns that a section they cannot use exists.

![Siam's Aqua home page](docs/screenshots/01-home.jpg)

![Laravel 12](https://img.shields.io/badge/Laravel-12-red) ![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4) ![React 19](https://img.shields.io/badge/React-19-61dafb) ![Inertia 2](https://img.shields.io/badge/Inertia-2-9553e9) ![Tailwind 4](https://img.shields.io/badge/Tailwind-4-38bdf8) ![Tests](https://img.shields.io/badge/tests-2%2C360%20passing-brightgreen)

---

## Contents

- [What it is](#what-it-is)
- [Screenshots](#screenshots)
- [What it does](#what-it-does)
- [Who uses it](#who-uses-it)
- [How it is built](#how-it-is-built)
- [Project layout](#project-layout)
- [Getting started](#getting-started)
- [Running it for real](#running-it-for-real)
- [Testing and quality](#testing-and-quality)
- [Documentation](#documentation)
- [Status](#status)
- [Related project](#related-project)

---

## What it is

The platform exists because of one pharmacy. The owner sells medicine at a counter, to small shops (paikari) and to bulk buyers (wholesale), and wanted one system that does all of it, plus an open marketplace for other sellers, without any of these worlds leaking into the others.

Two questions decide everything in the code:

1. **Who can see what?** Sections, prices and even words are filtered per viewer. A guest is never sent a word that names a restricted section.
2. **Who sees which price?** One pricing engine with four fixed layers (a manual rate for one customer, then a product override, then a company rate, then the tier default; the highest that exists wins) is used by every screen, so a price never differs between the counter, the cart and the invoice.

The site is **Bangla first** with a full English switch, installs on a phone as an app, and keeps the counter working when the internet drops.

---

## Screenshots

All screens below are the real application running on its QA data, shown in English. The default language is Bangla.

### Storefront

| | |
|:--|:--|
| ![Market](docs/screenshots/02-market.jpg) | ![Product page](docs/screenshots/03-product.jpg) |
| **Market.** Every seller's goods in one place, with sector tabs and picks for the visitor. | **Product page.** One honest price, seller, delivery charge, reviews and questions, and an optional video. |
| ![Medicine search](docs/screenshots/04-medicine-search.jpg) | ![Food](docs/screenshots/05-food.jpg) |
| **Find a medicine.** Brand or generic name in English or Bangla, tolerant of small spelling mistakes, over about 26,000 brands. | **Food.** Restaurants deliver or serve, with their own menus and live order screens. |
| ![Made to order](docs/screenshots/06-made-to-order.jpg) | ![On a phone](docs/screenshots/30-on-a-phone.jpg) |
| **Made to order.** Wood and clothing built to the customer's own measurements, with a price offered by a craftsman. | **On a phone.** The same site, designed phone-first, installable as an app. |

### The owner and the counter

| | |
|:--|:--|
| ![Owner dashboard](docs/screenshots/10-owner-dashboard.jpg) | ![Counter sale](docs/screenshots/11-counter-sale.jpg) |
| **Owner dashboard.** What needs attention today: warnings, backups, suspicious logins, latest orders. | **Counter sale.** Search or scan a barcode (even with the phone camera), staff discount limits, held drafts. |
| ![Stock lots](docs/screenshots/13-stock-medicine.jpg) | ![Offers](docs/screenshots/14-offers.jpg) |
| **Stock in lots.** Every purchase is a lot with its own cost, source and expiry, so profit and losses are exact. | **Offers.** Time and stock-limited offers with a profit floor, and a warning before an offer runs out. |
| ![Monthly report](docs/screenshots/15-monthly-report.jpg) | ![Credit ledger](docs/screenshots/16-credit-ledger.jpg) |
| **Monthly report.** Sales against last month and last year, money lost to below-cost sales and expiry, what to order. | **Credit ledger.** Who owes the shop money, how old each debt is, and what the shop owes each company. |

### Buyers and sellers

| | |
|:--|:--|
| ![Paikari ordering](docs/screenshots/20-paikari-ordering.jpg) | ![Seller dashboard](docs/screenshots/21-seller-dashboard.jpg) |
| **Paikari ordering.** A small shop sees its own tier, its own offers and the medicines it often orders. | **Seller screen.** Third-party sellers list goods, set their own token and pickup point, and answer buyers. |
| ![MPO portal](docs/screenshots/22-mpo-portal.jpg) | ![Market complaints](docs/screenshots/17-market-cases.jpg) |
| **MPO portal.** Company representatives offer stock through a private channel; the owner sets the price. | **Complaints.** Buyers complain or return, sellers answer, and the owner steps in with a stepped penalty. |

### From the QA run

These come from the QA report in `docs/qa/`, where every feature was tried by hand in a browser.

**Stock, staff and control**

| | |
|:--|:--|
| ![Counter draft](docs/screenshots/40-counter-draft.jpg) | ![Expiry](docs/screenshots/41-expiry.jpg) |
| **Counter draft.** A sale to a shop held as a draft, with the line totals, discount and payment. | **Expiry.** Lots near their date, each with its lowest safe price and a suggested discount. |
| ![Staff](docs/screenshots/42-staff.jpg) | ![Activity log](docs/screenshots/43-activity-log.jpg) |
| **Staff.** One account per person, a discount limit each, and extra powers lent for a while. | **Activity log.** Who changed what and when, searchable by person or action. |

**Returns and money**

| | |
|:--|:--|
| ![Shop returns](docs/screenshots/44-shop-returns.jpg) | ![Owner returns](docs/screenshots/45-owner-returns.jpg) |
| **Returns for a shop.** Find the memo, pick what comes back, and choose credit, exchange or part cash. | **Returns for the owner.** Requests waiting to be approved, and returns made without a memo. |
| ![VAT report](docs/screenshots/46-vat-report.jpg) | ![Wallet](docs/screenshots/47-wallet.jpg) |
| **VAT report.** Memo by memo for a date range, ready for the accountant. | **Wallet.** Money that works everywhere, kept apart from money that works at one seller only. |

**The marketplace**

| | |
|:--|:--|
| ![Seller page](docs/screenshots/48-seller-page.jpg) | ![Review](docs/screenshots/49-review.jpg) |
| **A seller's own page.** Their goods, their record and their trusted mark. | **Reviews.** Only a buyer who received the order can review it, with a photo. |
| ![Cart by seller](docs/screenshots/50-cart-by-seller.jpg) | ![Size grid](docs/screenshots/51-size-grid.jpg) |
| **One cart, a package per seller.** Each seller sends their own package with its own delivery charge. | **Colours and sizes.** The seller enters stock for every colour and size in one grid. |
| ![Seller orders](docs/screenshots/52-seller-orders.jpg) | ![Seller record](docs/screenshots/53-seller-record.jpg) |
| **Orders for a seller.** Accept, add a tracking note, and mark it sent. | **How a seller is doing.** Rank, delivery speed, what sells most and what sells least. |

**Food, medicine and everything else**

| | |
|:--|:--|
| ![Restaurant](docs/screenshots/54-restaurant.jpg) | ![Prescription](docs/screenshots/55-prescription.jpg) |
| **A restaurant.** Opening hours, an open switch, the menu and orders as they arrive. | **Prescriptions.** A shop sends a prescription photo, and the owner sees each new one. |
| ![Delivery settings](docs/screenshots/56-delivery-settings.jpg) | ![Help board](docs/screenshots/57-help-board.jpg) |
| **Delivery settings.** Working days, night hours, and delivery charges by distance and weight. | **Help board.** Post what you need, such as a worker or a product you cannot find, after the owner approves it. |
| ![Bangla](docs/screenshots/58-bangla.jpg) | ![Two-step sign-in](docs/screenshots/59-two-step.jpg) |
| **In Bangla.** The whole site in Bangla, which is the default. | **Two-step sign-in.** A code from a phone app on top of the password, required for staff. |

---

## What it does

**Pharmacy**
- Medicine database of about 26,000 brands and 290 companies, with box, strip and piece prices.
- Stock in purchase lots with expiry tracking, stock counts and corrections, and profit and loss protection (nothing sells below cost without a visible reason).
- Counter sales with draft memos, barcode scanning (scanner or phone camera), end discounts with staff limits, returns and corrected memos.
- Paikari and wholesale ordering with tiers, manual rates, monthly totals, free-delivery thresholds and 10-step fulfilment.
- Offers, pre-orders, company orders and a secret MPO (company representative) supply channel.
- A credit ledger for customers and for companies, with due-day warnings.
- Two-way stock sync with the sibling desktop app, [PharmaTrack](#related-project).

**Marketplace and other sectors**
- Public marketplace with sellers, commission per product, reviews, questions, complaints, returns, pickup points, digital goods and a trust ladder for new sellers.
- Made-to-order wood and clothing with craftsmen, offers and stage payments; food ordering with restaurants; game top-ups; service booking; a help board; a prescription marketplace; affiliates.
- Wallet, tokens, rewards and price-drop alerts.
- New sectors are created by the owner without code.

**Running the business**
- Delivery charges by weight and district, couriers, delivery money and ratings.
- Staff with per-person powers, approvals that expire, an activity log, two-factor sign-in for staff, blocked IPs and suspicious-login alerts.
- Nightly backups that are verified, and a monthly test that really restores the newest backup into a scratch database and checks it came back whole.
- A counter that keeps selling without internet and sends its sales, with temporary memo numbers, when the connection returns.
- Messages by inbox, push and e-mail or SMS where the owner has switched them on.

---

## Who uses it

| Account | What they do |
|---|---|
| **Public** | Browse the market, buy medicine, order food and made-to-order goods. |
| **Paikari** | Small shops buying medicine from the pharmacy at tier prices. |
| **Wholesaler** | Approved bulk buyers and third-party sellers on the marketplace. |
| **MPO** | Company representatives offering stock through the private channel. |
| **Food vendor** | Restaurants taking and preparing orders. |
| **Craftsman** | Woodworkers and tailors who price and build made-to-order jobs. |
| **Staff** | Counter and back-office staff with powers the owner lends. |
| **Admin** | The owner. Sees everything and decides almost everything. |

---

## How it is built

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.2+, MySQL |
| Frontend | React 19 and TypeScript, served through Inertia 2 (no separate API to keep in step) |
| Styling | Tailwind CSS 4 on a shared design-token layer (one theme, light and dark) |
| Build | Vite 6 |
| Queue, cache, sessions | Database drivers by default |
| Tests | PHPUnit, about 2,360 tests |
| Languages | Bangla (default) and English from one dictionary in `lang/bn.json` |

A few design rules shape the code:

- **Business rules live in `app/Services`**, one folder per area (Sales, Stock, Market, Pricing, Backup, and so on). Controllers stay thin.
- **Money and quantities are exact.** Stock moves in pieces, prices are decimals, and every stock change is recorded.
- **Visibility is enforced on the server.** Section gates, policy checks and a per-viewer dictionary; the browser is never trusted.
- **Every screen is built from the same design tokens**, with the interaction states and accessibility rules written down in `CLAUDE.md`.
- **A feature is not done until it has run in a browser.** The QA report lists, for each of 613 features, what was done and what was seen.

---

## Project layout

```
app/
  Http/Controllers/     Thin controllers, grouped by area (Admin, Market, Sell, Food, ...)
  Services/             The business rules: Sales, Stock, Market, Pricing, Backup, Ledger, Sync, ...
  Models/  Enums/       Eloquent models and the account types
  Console/Commands/     Backups, restore test, offers alert, medicine import, demo data
config/                 App, backup, messages, shop settings
database/               Migrations and the local-only DevSeeder
lang/bn.json            The Bangla dictionary (English text is the key)
resources/js/
  pages/                One folder per area, rendered by Inertia
  components/  lib/     Shared UI, the offline counter store and sync, i18n
routes/                 web.php (about 730 routes), auth.php, console.php (the schedule)
tests/                  Feature and unit tests
docs/
  screenshots/          The images in this README
  overview.md           Developer reference book (what is built, rule by rule)
  FEATURE-CHECKLIST.md  All 613 features and their status
  chapters/             The owner's specification, chapter by chapter
  qa/                   QA report (English and Bangla), results and screenshots
tokens/ components/ taste/ ...   The design system the screens are built from
```

---

## Getting started

**You need** PHP 8.2 or newer with the usual Laravel extensions (and `gd` for image compression), Composer, Node.js with npm, and MySQL.

```bash
git clone https://github.com/abdullah2323442/ecommerce.git
cd ecommerce

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create an empty MySQL database, put its name and credentials in `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), then:

```bash
php artisan migrate
php artisan db:seed --class=DevSeeder      # local demo accounts, refuses to run outside APP_ENV=local
php artisan demo:catalog                   # optional: sample products (more sample data: the other demo:* commands)
npm run build                              # or run the dev server below
```

Start everything for development (web server, queue worker, log tail and Vite) with one command:

```bash
composer dev
```

The seeded demo accounts (admin, staff, paikari, wholesaler, shopper) are listed in `database/seeders/DevSeeder.php`. They exist only for local development.

**The medicine catalogue** is not in this repository. If you have the legacy `pharmacy` MySQL database, set `LEGACY_DB_DATABASE` in `.env` and run:

```bash
php artisan medicines:import --dry-run     # preview
php artisan medicines:import
```

The importer is idempotent, so it can be run again safely.

---

## Running it for real

Before going live: set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL`, `SESSION_SECURE_COOKIE=true`, run `npm run build`, make sure `public/hot` does not exist, and point `BACKUP_DISK` at storage on a different machine (the dashboard warns while backups sit on the same server).

The schedule in `routes/console.php` needs one cron entry:

```bash
* * * * * cd /path/to/ecommerce && php artisan schedule:run >> /dev/null 2>&1
```

It runs the nightly backup and its verification, the monthly restore test, the offer low-stock warning (every five minutes), expiry of staff approvals, and the queue.

| Command | What it does |
|---|---|
| `php artisan backup:run --verify` | Make a backup now and check it is readable. |
| `php artisan backup:restore-test` | Load the newest backup into a scratch database, compare it with the live one, then remove it. Set `MYSQL_PATH` if `mysql` is not on the PATH. The dashboard shows the result. |
| `php artisan offers:alert` | Warn the owner once when an offer is nearly sold out. |
| `php artisan medicines:import` | Import the legacy medicine database. |

Payments, SMS and e-mail, WhatsApp, maps, couriers and off-server backup all need accounts and keys that belong to the owner. They are built to be switched on from the admin Connections screen once those exist.

---

## Testing and quality

```bash
php artisan test              # the whole suite (about 2,360 tests)
npm run lint                  # ESLint
npm run format:check          # Prettier
npx tsc --noEmit              # types
./vendor/bin/pint --test      # PHP style
```

Tests run on in-memory SQLite in UTC. Some tests check the page path in the Vite dev tags, so keep `public/hot` in place while running them (and move it aside when you want to look at a production build in a browser).

Beyond the automated tests, `docs/qa/` holds a report that tries every feature by hand in a browser, with a screenshot for each: **613 features tracked, 594 shown working, 0 failing, 19 only partly shown** (all of those wait on payments, SMS, maps or a real phone connection).

---

## Documentation

| Read | For |
|---|---|
| [`docs/overview.md`](docs/overview.md) | The developer reference book: the business, the golden rules, every area rule by rule, and the build status. |
| [`docs/FEATURE-CHECKLIST.md`](docs/FEATURE-CHECKLIST.md) | The status of every one of the 613 features. |
| [`docs/chapters/`](docs/chapters) | The owner's specification. If it disagrees with the overview, the chapter wins. |
| [`docs/qa/`](docs/qa) | The QA report in English and Bangla. |
| [`docs/pharmatrack-sync.md`](docs/pharmatrack-sync.md) | How stock stays the same between this site and PharmaTrack. |
| [`CLAUDE.md`](CLAUDE.md) | The design rules every screen follows (tokens, states, accessibility). |

---

## Status

Everything that does not need an outside account is built and tried by hand. What is left waits on things only the owner can supply: a payment company (bKash, Nagad, Rocket, cards), an SMS and e-mail service, WhatsApp Business, a Google Maps key, courier keys and an off-server backup disk. Real-phone speed and festival-peak load also need the real server to prove.

---

## Related project

**[PharmaTrack](https://github.com/Ahasan39/pharmacy-stock-management-main)** is the pharmacy's desktop-style ordering and stock tool: company orders, shortlists, counter sales and a stock ledger. This site and PharmaTrack share one stock and keep it in step in both directions (see `docs/pharmatrack-sync.md`).

---

## Licence

The Laravel starter kit this project grew from is MIT-licensed. The project's own licence has not been set yet; add a `LICENSE` file before accepting outside contributions.
