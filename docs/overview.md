# Siam's Aqua — Developer Reference Book

> **Owner / spec author:** Siam (client and business owner)
> **Audience:** the development team
> **Source of truth:** `docs/chapters/` (34 chapter files, 5,983 lines, also in `docs/siams-aqua-ecommerce-spec`). This book is a *digest*: every rule below names the chapter it came from (`ch 06.2` = chapter 06, section 6.2). **If this book and a chapter disagree, the chapter wins — then ask Siam.**
> **Standing order from Siam: do not guess and build. If anything is unclear, ask.**

---

## How to use this book

| You want to… | Go to |
|---|---|
| Understand the business in 5 minutes | Part 1 |
| Know what to build first and when a step is "done" | Part 2 |
| Build accounts, permissions, pricing, admin, security | Part 3 |
| Build anything pharmacy (stock, paikari, counter, offers, returns, MPO) | Part 4 |
| Build a non-pharmacy sector | Part 5 |
| Build delivery, pre-order, memos, messages, wallet, payments | Part 6 |
| Look up a formula, status name, setting, or a list of unresolved questions | Part 7 |

**Notation.** Every requirement has an ID (e.g. `STK-12`). **MUST** = mandatory rule. Phase tags: **P1** = needed at launch, **P2** = later, **F** = future (reserve DB structure only). "Siam" = the admin/owner. The word *draft* is **never** used alone in code — see §4.2.

**Contents**

1. Part 1 — The Business
2. Part 2 — Build Order and Milestones
3. Part 3 — Foundation (accounts, visibility, pricing, admin, security)
4. Part 4 — Pharmacy (medicine DB, stock, paikari, counter, offers, returns, MPO)
5. Part 5 — Other Sectors (public, wholesale, multi-sector, custom orders, food, gaming, help)
6. Part 6 — Cross-Cutting Systems (delivery, pre-order, memos, messages, wallet, payments, technical)
7. Part 7 — Reference Tables (worked formulas, state dictionary, settings catalogue, open questions, chapter map)

---

# PART 1 — THE BUSINESS

## 1.1 What is being built (ch 02)

A **multi-sector e-commerce platform for Bangladesh**: one platform, many separate businesses, each with its own users, prices and rules. Think of a mall — one entrance, strict walls between shops.

**Siam's pharmacy is the reason the platform exists.** It is the largest and most complex part and is built first. Every other sector (food, clothing, gaming…) is comparatively simple and comes later.

The whole platform stands on two questions. If either is wrong, every other feature is meaningless:

1. **Who can see what?** → §3.4 (visibility)
2. **Who sees which price?** → §3.5 (pricing)

## 1.2 Siam's current shops (ch 02, ch 06.5)

| Shop | Plan |
|---|---|
| **Small shop** (~1–1.5 lakh taka of goods) | Goes into the system **first** |
| **Large pharmacy** | Added later, when computers are set up. The two shops then merge into one. |

**PharmaTrack** is Siam's existing desktop pharmacy software. It and this platform work on **the same stock**: e-commerce = online orders; PharmaTrack = walk-in customers. Unavailable paikari items are auto-added to PharmaTrack's short list. Full two-way PharmaTrack sync is **F**.

## 1.3 Golden rules (read before writing any code)

| # | Rule | Where |
|---|---|---|
| G1 | **Paikari buyers must never know a wholesale section exists.** No lock icon, greyed menu, "access denied", or hint. | §3.4 |
| G2 | **All permission checks happen on the server**, on every request, including ownership of orders/memos/chats. Frontend hiding is decoration. | §3.4 |
| G3 | **A loss is blocked when a rate is *set*,** not when a sale happens. | §4.4 |
| G4 | **Staff never see profit or sales totals** — only loss warnings. | §3.7 |
| G5 | **An invoice stores that day's price.** Later rate changes never touch old invoices. | §3.5 |
| G6 | **Sellers' real names are hidden by default;** buyers see Siam's platform name. | §3.4 |
| G7 | **The pharmacy stock system is for Siam's pharmacy only.** Third-party wholesalers get a much simpler version; other sectors get basic stock. | §4.3, §5.3 |
| G8 | **"Draft" means five different things** — use five separate code names. | §4.2 |
| G9 | **Never show stock to paikari buyers** — permanent business decision, even after Master Stock Sync is on. | §4.6 |
| G10 | **Steps 0, 1, 2 are the live business.** Build them as if the business depends on them — it does. | Part 2 |
| G11 | **Manual customer rates are never changed by tier or offer changes.** | §3.5 |
| G12 | **Medicine names/companies/ingredients/products are always picked from a central list, never typed.** | §4.1, §6.8 |

## 1.4 Sectors at a glance (ch 02, 29)

| Sector | Phase | Who it is for | Built in |
|---|---|---|---|
| Medicine Database | P1, start Day 1 | Catalog everything pharmacy runs on | Step 0-A |
| Stock System | P1 | Siam's pharmacy only | Step 1 |
| **Paikari** | P1 | Small pharmacies/shops buying from Siam | Step 2 |
| **Counter Sale** | P1 | Walk-in retail / paikari / wholesale at Siam's shop | Step 2 |
| Offers | P1 | Medicine offers with stock tracking | Step 3 |
| Returns | P1 | Who can return what, for how much | Step 3 |
| Wholesale (buy side) | P1 | Approved bulk buyers | Step 4 |
| MPO | P1 | Secret pharma-rep supply channel | Step 4 |
| Pre-order | P1 | Order items not in stock | Step 4 |
| Public Market | P1 | Open retail | Step 5 |
| Delivery & Courier, Memos, Admin/Reports | P1 | Cross-sector | Throughout |
| Wholesale (sell side) + Commission, Supplier app, Wallet/Tokens | **P2** | Third-party sellers | Step 6 |
| Multi-sector builder (wood, clothing, electronics…) | P2 | New sectors created by Siam | Step 7 |
| Custom Orders | P2 | Made-to-order wood & clothing | Step 8 |
| Food | P2 | Fast-food restaurants | Step 9 |
| Gaming, Help | P2 | Top-ups; community board | Step 10 |
| Extras | P2 | Recommendations, rewards, etc. | Step 11 |
| Future | F | Prescription marketplace, ledger, etc. | Step 12 |

## 1.5 Glossary (ch 00)

| Term | Meaning |
|---|---|
| **Paikari** | Small shops/pharmacies buying medium quantities from Siam; between retail and wholesale. **Siam's sector only.** |
| **Holseller / Wholesaler** | Approved bulk buyer — and (P2) a third-party seller on the platform. |
| **MPO** | Medical Promotion Officer — a pharma company's sales rep. |
| **PharmaTrack** | Siam's existing pharmacy software. |
| **Khucra** | Retail (walk-in) customer. |
| **MRP** | Maximum Retail Price printed on the box. |
| **Box / Strip (pata) / Piece** | Medicine units: box → strips → pieces. |
| **COD** | Cash on delivery. |
| **bKash / Nagad / Rocket** | Mobile-money services. |
| **Baki khata** | Credit ledger (F). |
| **Tier** | A customer class (A, B, C…) with its own discount rate. |
| **Aqua Token** | The platform's loyalty token (P2). |

## 1.6 Scope (ch 30)

613 features: **Phase 1 = 420 · Phase 2 = 185 · Future = 8**. The numbered checklist (items 1–613) is in `chapters/30-feature-checklist.md`; tick items there.

---

# PART 2 — BUILD ORDER AND MILESTONES (ch 29)

Finish one step completely, launch it, let Siam use it, fix what he finds, then move on. **Do not start the next step with the current one half-done.**

| Step | What | Done when… |
|---|---|---|
| **0** Foundation | Accounts, login, server-side visibility, 4-layer pricing engine, admin panel/roles, security, backup | Every route is permission-checked; pricing priority passes all test cases in §7.1 |
| **0-A** Medicine data | Load all Bangladeshi medicines with generics, companies, units, flags. **Starts Day 1 in parallel**; a separate data-collection job. Decide the data source *before* starting. | Medicine DB searchable; stock entry can only pick from it |
| **1** Stock system | Sources, 3-layer purchase rate, cascades, bonus, lots, loss protection, expiry, draft invoice, corrections, stock count, barcode, reports, Master Stock Sync (built, **off**) | Every stock rule in §4.3 works |
| **2** Paikari + Counter | Paikari ordering and full fulfillment, short list, counter sale (3 modes), offline counter, delivery, messages | **First live milestone — Siam starts using it for the real business** |
| **3** Offers + Returns | Offer products, tier offers, all return types | |
| **4** Wholesale (buy) + MPO + Pre-order | Wholesale accounts/licence/category access, MPO incl. bidding, pre-order | |
| **5** Public market | Public accounts, guest checkout, cart/checkout, many-sellers-one-product, reviews, budget/premium sectors | |
| **6** Commission + Supplier app | Wholesalers-sell, per-product commission, monthly collection, white-label, separate Supplier app, wallet/tokens | |
| **7** Multi-sector | Sector builder, colour/size grid, two business accounts per person | |
| **8** Custom orders | Measurements, craftsmen, wages, chat | |
| **9** Food | Restaurants, menus, cooking timer, custom food | |
| **10** Gaming + Help | Simple, independent | |
| **11** Extras | Recommendations, rewards, referral, bundles, flash sale, abandoned cart, tickets | |
| **12** Future | Prescription marketplace, analytics, affiliate, bulk SMS, baki khata, full PharmaTrack sync, security deposit, service booking | |

**Rule for the developer:** structure Phase 2/Future so they can be added later without breaking what exists (ch 28 note 12). Store user behaviour data from Day 1 (ch 28 note 13) and keep VAT/Mushak fields in the DB from Day 1.

---

### Build status (as of 2026-10-12)

| Area | Status | Where |
|---|---|---|
| Medicine DB + company logos | Done (imported, 25,933 medicines) | §4.1.1 |
| Medicine search (misspellings, Bengali, alternatives) + public pages | Done | `MedicineSearch`, `/medicines` |
| Account types, section matrix, silent redirect (VIS-3/4) | Done | `app/Enums`, `EnsureSectionAccess` |
| Unknown URLs redirect home for everyone except the admin (who gets real 404s) | Done | `bootstrap/app.php` |
| Pricing engine, 4 layers, tier changes keep manual rates | Done (open rules are defaults in `config/pricing.php`) | `app/Services/Pricing` |
| Tier change log, activity log (who/what/when) | Done (append-only by convention; no DB trigger yet) | `TierAssigner`, `ActivityLogger` |
| IP block, login lockout, suspicious-login flags, device limit | Done (flags only; does not block yet) | `BlockIpAddress`, `LoginMonitor` |
| Nightly backup, 30-day retention, verify, restore proven | Done. **Disk is local until Siam picks an off-server destination** | `BackupService`, `config/backup.php` |
| Admin: customer list, customer profile (tier, manual rates, suspend, history), tier management | Done (admin only; staff get role permissions later) | `/admin/customers`, `/admin/tiers` |
| Brand, palette and font applied to the whole app; real dashboard (needs-attention list, live stats, recent activity) | Done | `app.css`, `DashboardController` |
| Public home page redesigned from the spec's own idea (ch 02: one entrance, strict walls between shops): a street of shopfronts, one per **public** sector. The pharmacy is open; Food, Gaming, Custom, The Market and Help have shutters down (honest Phase 2 status). Inside the pharmacy: instant search, chalkboard price board, boxes-on-shelves browse by form, brand plaques. Restricted sections are never drawn | Done | `Street`, `PriceCompare`, `FormTiles`, `HomeController` |
| **"I was looking for this"** (spec ch 13, checklist #303): one-click request from any empty search and from the counter; one voice per visitor session, rate-limited, grouped and counted for the admin at `/admin/requests`, surfaced on the dashboard | Done | `ProductRequestController`, `RequestButton` |
| Hero photography (light + dark), AI-generated with ChatGPT by Siam's account | Done. Sources in `resources/art/hero/`, web sizes in `public/images/hero/` | §4.1.1 note below |
| Dev seed data (local only) | Done | `DevSeeder` |
| Phone sign-up, OTP, 2FA | **Deferred by Siam (SMS)** | none |
| Staff roles and per-role permissions, discount limits | Not started | spec ch 26 |
| **Step 1 stock system: sources and 3-layer purchase rate** (product, then company, then source default), cascading discounts, bonus as a true %, locked taka cost, duplicate-invoice warning, no save without a %, live cost preview | Done (admin only, Bangla) | `PurchasePricing`, `StockService::receive`, `/admin/stock`, `/admin/stock/receive`, `/admin/stock/sources` |
| Step 1: lots (not FIFO; the dearest lot that still leaves a profit is used), Master Stock Sync (global switch, **off**, confirm to turn on), corrections (admin only, with a reason) | Done | `StockService::sell/correct`, `/admin/stock/medicines/{id}` |
| Step 1: **loss protection** on manual customer rates (blocked when set; admin can force, flagged and logged); loss sales flagged in reports | Done for manual rates and for `StockService::sell`. Staff approval flow (one tap, auto-cancel) comes with Step 2 | `StockService::rateWouldLose`, `CustomerController::saveRate` |
| Step 1: expiry management (stagnant, months of stock, near-expiry with lowest safe price, expired section by company with returned / not taken back) | Done (PDF for the expired list not yet) | `ExpiryService`, `/admin/stock/expiry` |
| Step 1: stock count mode (whole shop, company or shelf; pause; sales during the count adjusted for; admin approves) and barcodes (several per medicine, link once, scan adds one) | Done | `StockCountService`, `/admin/stock/counts` |
| Step 1: reports (stock value, low stock, finished today), shop expenses (recurring repeat monthly), gross to net profit, 12-month trend | Done | `ReportService`, `/admin/stock/reports` |
| Step 1 still to build (all need sales from Step 2): draft invoices, monthly ranking and reorder suggestions, per-customer reports, end-of-day cash reconciliation, simple staff screen, wholesalers' stock | Not started | spec 4.3.5, 4.3.9, 4.3.10 |
| Step 2 slice A: invoice domain (drafts, stock deducted at add and restored on remove/cancel, merge, move, rate priority, memo numbers) | Done | `InvoiceService`, `InvoiceServiceTest` |
| Step 2 slice B2: payment method and paid/due on every counter sale; End of day cash reconciliation (admin only) | Done | `DayClosingController`, `EndOfDayTest` |
| Step 2 slice B: **counter screens** for staff and admin (open drafts with timers, search with unit prices, quantity edit, end discount / invoice %, merge, finish, cancel, printable memo) | Done; gate `use-counter` | `CounterController`, `admin/counter/*` |
| Step 2 slice C1: shop portal: own-rate search, cart, placed order, staff check per line (full/partial/not available), live status page, cancel, finish at the counter. Stock is never shown to the buyer and is only taken at the check | Done | `PaikariController`, `Admin/OrderController`, `OnlineOrderTest` |
| Step 2 slice C2: short list (order in one tap), delivery charge typed by staff, free-delivery progress and over-threshold warning, "asked for but not available" buying list | Done | `ShortListAndDeliveryTest` |
| Step 2 slice C3: "you often order" suggestions, night/holiday notices (orders are never refused), monthly total per customer with an admin switch | Done | `OrderNotice`, `ShopHabitsTest` |
| Step 2 slice C4: staff edit a line's price before the order goes back (below cost needs the admin, message hides the cost), problem-customer flagging (late cancellations over 90 days, setting `flags.cancel_limit`), staff-only words kept out of the shop dictionary | Done | `PriceEditAndFlagTest` |
| Step 2 slice C5: phone-order quick note turned into a draft at the shop's prices; shop adds more after the memo (only the new line is re-checked); prescription upload to a private file, staff-only, unseen count and late highlight (setting `prescriptions.alert_minutes`) | Done | `QuickNoteMoreAndPrescriptionTest` |
| Step 2 slice C6a: cancellation per spec: shop cancels alone before picking, only requests after, staff approve or keep, packed-but-not-collected cancels and returns stock; flag now counts cancellations after picking started | Done | `CancellationRulesTest` |
| Step 2 slice C6b: month-end tier task (target per tier, gap shown only in the last days of the month), candidates list for the admin, upgrade by hand sends a congratulations notice (new `notices` inbox) | Done | `TierUpgradeTaskTest` |
| Step 3a: tier offers (spec 9.2): admin starts an offer at a % from a list of lots bought at a high discount or by hand, minimum-margin guard, every paikari buyer gets it at once (never worse than their tier, manual rates stay above), ends with the month, pauses when stock is gone and returns with new stock, month-end question | Done | `TierOffers`, `TierOfferTest` |
| Step 3b: offer products (spec 9.1): offer lot only (higher discount), boxes with a minimum and extra % per box, price never worse than the shop's own, cap at the offer boxes (message hides the number), stock shown as "In stock" or exact, search pop-up under the normal item, staff see every offer at the counter (not retail), low-stock alert, sold-out greyed 90 min then hidden, back with new qualifying stock inside the month, deducts even with Master Sync off | Done | `OfferProducts`, `OfferProductTest` |
| Step 4a: returns (spec ch 12): find a sale by memo number, date or medicine; partial returns at the price paid; exchange or credit is full, cash is half (also for offers); admin approves; goods go back into their own lot (reopens a finished lot), sale shrinks so profit follows; credit comes off the next memo as "Return product" and is spent once; return without memo (admin); reason always; shop's own Returnable list inside the window; retail has no account; mistake warnings and an automatic repeat warning; return analysis; unused-credit warning; policy and Reprint on the memo | Done | `ReturnService`, `ReturnsTest` |
| Step 4b: memos and printing (spec ch 22): number carries the year (`M2026-000001`) and counter and online share one sequence; printable memo at 58 mm, 80 mm or A4 (default is a setting the admin sets), offer items first with their own subtotal then regular items, discount / delivery / "Return product" minus / total / paid or due on their own lines, staff name, return policy, Reprint from the second print, WhatsApp link, shops open their own memo; admin correction cancels the old memo (kept in history), gives back its credit and opens a new draft for the new number | Done | `MemoController`, `MemoTest` |
| Step 5a: "Need admin approval" (spec 6.2): staff past their discount limit or below cost ask, the admin gets a notice and a badge and answers with one tap, a yes applies it as the admin and is logged with who approved it, a no or silence leaves the normal rate, unanswered requests cancel after `approvals.expire_minutes` (default 10, also a scheduled command), the admin hears about every blocked try, a per-staff limit overrides the role limit | Done | `ApprovalService`, `ApprovalFlowTest` |
| Step 5b: counter extras: quick shop profile at the counter (name, phone, area; default tier; cannot be signed into), area and phone beside the name in search, barcode scan adds a box and scanning again adds another, keyboard shortcuts (`/`, arrows, Enter, Esc), most-sold medicines one click away, similar-name warning with strength in bold, offers given at the counter show in the shop's "Offers taken" | Done | `CounterExtrasTest` |
| Step 6a: admin reports (spec 6.9, ch 25): monthly report with this month against last month and the same month last year, sales by kind and channel, what to order (boxes per day, level it should have, what is on hand, how many to take, buy more or take less), earns-the-most, companies that move most, sitting stock, best customers with what Siam made from each, shops gone quiet, day by day, tonight's summary on screen, Excel-ready CSV of the month's sale lines; admin only because it carries profit | Done | `SalesReports`, `SalesReportTest` |
| Step 6b: staff and security (spec ch 26): roles built from ten permissions you switch on and off (starting set: Counter staff, Sub-admin, Order manager, Wholesale manager, Product manager; new roles can be made), every gate now follows the role and the super admin passes all, staff screen to open a separate account per person, change role, set a personal discount limit, close an account (records stay) and reopen it, suspension for a set number of days that lifts itself, activity log screen with a record of who opened whose data, the password asked again for staff/role changes and stock corrections | Done | `StaffRolesTest`, `config/permissions.php` |
| Step 7a: wholesale buying side (spec ch 14, 03, 25): a neutral "Business account" application any signed-in plain account can send (business, categories, licence uploads kept private), the admin review screen (open the licence, every look logged; open the account as a shop buyer or a bulk buyer with tier, categories, minimum order quantity and ways to pay, or decline with a reason the applicant reads), the same order portal under its own address for bulk buyers (their prices, category access, minimum per line with a clear message, cash on delivery or advance chosen only from what their account allows), a bulk buyer account page (grade, finished months of buying, history), terms editable on the customer page; fixed server messages showing raw `{n}` braces | Done | `WholesaleTest` |
| Step 7c: controlled-medicine sale register (spec ch 27): a sale with a controlled medicine cannot be finished until the buyer, phone or address, prescription number and doctor are written (counter and online orders), the entry carries to a corrected memo, the register screen lists every controlled sale by date and medicine with quantity, date and staff taken from the sale, prints on its own page and downloads as CSV, a finished entry changes only for someone who sees reports and the old words are logged | Done | `ControlledRegisterTest` |
| Step 7d: Settings screen (spec ch 25): closed weekdays, holiday dates, night hours, free-delivery level, discount limit, approval expiry, cancellation flag limit, return window and cash share, offer rules, month-end and reorder numbers and the shop-wide minimum for bulk buyers, each with its limits, every change logged with the old value | Done | `SettingsTest`, `config/shop_settings.php` |
| Step 7e: device limit and shop pages (spec ch 26, 27): a staff account signed in on more devices than the limit signs the oldest out (the limit is a setting, customers and the owner are not limited); Terms of service, Privacy policy, Return policy and Questions pages in Bangla and English that name no restricted section, linked from the footer; a chat button for WhatsApp or Messenger and the drug licence line that appear only when the owner sets them in .env (BRAND_WHATSAPP, BRAND_MESSENGER, BRAND_DRUG_LICENSE); config/brand.php was missing and is now in place. The terms and privacy text is plain-language and should be read by a lawyer before launch | Done | `SessionLimitTest`, `InfoPagesTest` |
| Step 8a: public ordering (spec ch 13, 20): a cart for visitors and plain accounts that lives in the session and prices at the printed price, guest checkout with name, a real Bangladeshi mobile number, district (64, in Bangla), area and address (how to get there, ordering for someone else), pick-up at the shop when the owner has set an address, delivery charge by area (inside or outside the shop's district, free over a level, all settings), the order sent to staff as an online retail order that is checked line by line like a shop's, a private link to follow it, cancel by the buyer for a set number of minutes until staff start, orders nobody answered cancelled after a set number of hours with a notice, memo opened from the same link, address book and order list for signed-in buyers, a cart button with the count; every throttle now has its own bucket (they used to share one, so cart adds could block checkout); Bangla validation messages | Done | `PublicOrderTest` |
| Step 8b: craftsmen and their jobs (spec ch 16.4, 16.6, 16.7): only the owner opens craftsman accounts; a craftsman has their own screen (`/craftsman`) with the jobs they are on, measurements and description, photos, the time and their own wages, and never the buyer's price, our profit, the buyer's name or phone, other craftsmen's jobs or stock (tested on the page data); we may ask one craftsman what the wage should be (they see the job, send a figure, we finalize it), and a job does not go to any craftsman until the wage is set; we assign each job (the active-job count beside each name), work needs a craftsman, the one mainly responsible can shift it (the new one continues, the old keeps what he earned) or take others on as a share, and every assign, shift and share stays in history; wages and progress are the same percentage: the main craftsman enters who did how much, each entry with a photo, the total never passes 100, wages split by those percentages (1,000 taka: 20/50/30 gives 200/500/300), we can see and fix a split, below 100% the job cannot be marked ready (we see '70% done, 30% remaining'); the buyer sees only 'Your job is N% done', never names; 'Materials needed' shows the buyer 'Waiting' until we say they arrived; a job past its time tells us and the buyer once and counts the delay on the craftsman, we can give a new time; no update for three days reminds the craftsman and tells us; the advance goes to the craftsman (never more than we received); owed and paid are kept apart with the month's earnings and everything owed at a glance; a cancelled job still pays for work done; profit is price − material − wages. Not yet: chat (step 8c), wood deducted from stock. Tests in CraftsmenTest, checked in the browser (a craftsman's job page in Bangla). | Done |
| Step 8c: chat in made-to-order jobs (spec ch 23, 16.5): one chat per job on the buyer's job page, the main craftsman's page and ours; text, photo and voice (recorded in the browser or chosen as a file); a per-kind switch in settings (wood, clothing); the buyer talks to the maker under our name and never learns who the maker is, the maker sees 'the buyer' and 'the office' and never the buyer's name, phone or our staff's names, and a helper on the job sees no chat; phone numbers (in any dress: spaces, dashes, +880, Bangla digits) and links, domains and @handles are caught and not sent, each attempt kept with who tried, and a person who tries three times is flagged to us; 'Send measurements' opens the measurement form in the chat (while nothing is agreed; it replaces the measurements, sends a priced job back to us to price again, and cards show apart from the talk with a 'View measurements' shortcut); 'Send offer' is ours only (we know what the work costs; the maker never sees the price): one total, the days and the advance, a newer offer voids the older, the buyer ticks that they checked the measurements and taps Accept, which confirms the job; we read every chat (list, search of old chats, blocked attempts, answer and offer from there); the other side is told once until they have looked, a poll does not count as reading; a buyer message nobody answers in the set hours (12) comes to us once, and the message is marked on the chat. Tests in CustomChatTest, checked in the browser (chat in Bangla, a number blocked with the reason shown). | Done |
| Step 9a: food — restaurants, menu and orders (spec ch 17): only the owner opens a restaurant account (a food account with its own screen) and sets that restaurant's commission by hand (a percent of the food, or taka per order; counted only on delivered orders, none on a cancelled one), can suspend it (new orders stop, old ones must be finished); the buyer gives their area first and sees only restaurants that deliver there, open ones first, then the menu of what can be ordered right now (no account needed, a guest follows the order by a private link); the restaurant runs the menu (dishes, photo, price, cooking minutes, ready-made or needs cooking, switched on or off any time, 'sold out today' that ends by itself at midnight Dhaka time), opening hours that close it by themselves (also past midnight, closed weekdays) and a manual switch; delivery charge by area (one inside, one outside, never below the least) or written on each order, own riders or the platform courier (a flag), an advance for orders over an amount (a percent; the order cannot be confirmed until it is received); order steps pending → confirmed with the minutes the owner writes → a timer on the buyer's screen → packing → out for delivery → delivered, or for 'I'll eat there' (with optional time choices) ready → served; a restaurant that does not confirm in 10 minutes (setting) makes the buyer's page and inbox ask 'Do you want to cancel?'; cancelling before confirmation returns the advance, after cooking started it does not, if the restaurant cancels it always does, nothing after it is out; complaint with a mandatory photo goes to the restaurant and to us and the restaurant answers; the restaurant sees today's and this month's sales, our commission and what it receives, orders going to the same area shown together for one rider, a kitchen ticket with the details large and a memo in the restaurant's name with ours small at the bottom; every order and the commission accumulated per restaurant on our side. Charging by road distance, a maximum distance and the map need a map service that is not connected (step 434 partly). Tests in FoodTest, checked in the browser (list and menu in Bangla). Custom food (build-your-own with the central ingredient list) is step 9b. | Done |
| Step 9b: custom food (spec ch 17): the buyer builds their own on a base (burger, pizza, sandwich, roll, each restaurant's own with its own price and cooking time) from ingredients; one central ingredient list kept by the owner (a starter list of 56 in English and Bangla in IngredientSeeder, grouped meat, cheese, vegetables, sauces, extras, spices, no repeats however spelt, searchable, switchable off) that a restaurant can only tick from, each with the restaurant's own price, never typing a new name; a restaurant that has something we do not list sends a request, we read it and add it (then every restaurant can tick it) or turn it down; an ingredient can be switched off or 'sold out today' (back by itself tomorrow); the restaurant sets the most ingredients in one dish (10) and the extra minutes custom food takes (10), and pairs that do not go together (either order, one rule); the build screen greys out what is sold out, what clashes with what is picked and what would pass the limit, shows the running total, tells the buyer custom food takes longer and has search; the server reads the base, each ingredient, the limit and the rules again when the order is placed and keeps the base and every ingredient with its price as ordered, so later price changes do not touch it; the memo shows each ingredient on its own line with its price and the kitchen ticket shows the ingredients and notes large; the owner is offered the custom time when confirming; a signed-in buyer saves a combination under a name (the same name changes it), brings it back in one tap and is told when something in it has run out today; the owner sees which ingredient sells most on delivered orders. Tests in CustomFoodTest, checked in the browser (cheddar ticked in Bangla greys out the sauce it does not go with, total right). | Done |
| Step 10a: game top-up (spec ch 18): games and diamond packages kept by the owner; the buyer gives the player ID, we show the player name when a provider is connected (otherwise they tick that they checked the ID), picks a package and pays online by sending money to our bKash/Nagad/Rocket number (a setting) with the transaction id (unique per method); the money is checked by hand, then topped up by hand; a failed top-up is refunded and listed apart; a top-up later than 15 minutes (setting) tells the buyer once and lets them cancel for a refund. Provider and card payment are deferred behind interfaces (PlayerVerifier, TopUpProvider). Four new message kinds, a gaming settings group, a `gaming:late` check every five minutes, 28 tests. 1212 tests pass | ✅ |
| Step 10b: wallet and Aqua Token (spec ch 24, 04, 12): the wallet page /wallet in two parts, what works everywhere (tokens) and what works in one sector only (return credit); platform tokens only the owner gives (to a person, an amount, an optional last day, taken back while unused); each seller has one token of their own for all buyers, on their own products only, paid for by the seller (smallest order, times per buyer, last day, on/off). A buyer asks for the token at checkout or on a marketplace order; the larger of the two applies, never both; it never goes with an offer price or a special discount; a platform token on the marketplace comes out of our commission and is never more than it. On a shop memo the token comes off first and then the return credit, as two lines (ch 04's order is followed where ch 24 says the reverse). A cancelled or corrected order gives the token back. One account per phone (OTP) waits for the SMS provider; referral credit is with reward points (603). 26 tests. 1238 tests pass | ✅ |
| Step 10c: questions under a seller's product (spec ch 23): a signed-in buyer asks under a marketplace listing (no links or phone numbers, ten a day, not on your own product); the seller is told and answers in /wholesale/sell/questions; an answered question shows to everyone without naming the seller; one the seller leaves longer than the set hours (24) comes to us every 15 minutes check, the seller is told once, staff answer it in the Questions page ("A seller left these too long"), and each one counts on the seller's profile (60 days); enough of them put the seller on our watch list (setting). Three new message kinds, two settings, 12 tests. 1250 tests pass | ✅ |
| Step 10d: "Why didn't you buy" and price offers on the market (spec ch 13): a signed-in buyer on a listing picks one of five reasons (price, cheaper elsewhere, not what I wanted, delivery charge, don't need it now); every reason is counted for the seller ("why buyers leave without buying"). On "price" the buyer names their own price for each; the seller (who never sees who) sees what they would be paid, our commission staying the same in taka, and agrees, names a price in between, or says no. The buyer orders at the agreed price from /market-offers within the set hours (24); an offer or agreed price nobody uses is cancelled by the 15-minute check and the buyer is told. One answer a day per buyer per product. Five new message kinds, one setting, 12 tests. 1262 tests pass | ✅ |
| Step 10e: colours and sizes, and wood sizes (spec ch 15): a seller turns one product into a grid (colours down the side, sizes across the top, how many in each cell, an optional price per cell, one photo per colour) without a new review; the buyer picks a colour and a size, the price of that cell shows, choosing a colour changes the photo, and only a colour with nothing left turns grey while the product stays on sale; a plain product is unchanged; orders take one cell's stock and give it back when cancelled; the order shows the colour and size to the seller and the buyer; a new seller's grid prices keep the trust limit; the buyer never sees stock numbers. Wood and furniture can carry length, width and height. Pre-ordering a sold-out colour and price offers on a grid product are not built yet. The prohibited-word list already flags a listing for the reviewer (376). 19 tests. 1281 tests pass | ✅ |
| Step 10f: one product in many categories, and basic stock for sellers (spec ch 15): a seller can show one product in other categories they sell in (still one product, one stock, listed once in the market and under each category; a change of categories is read again like a new listing; only their own categories, never the main one twice). Sellers also enter their own cost (private, never in any buyer page) and a low-stock mark; the reports page shows what sells the most and the least, what is running low, and what they really made after their own cost; they are told once when stock falls to their mark. Returns for wood and clothing already use the same buyer return flow, but the days are the owner's one setting, not each seller's own (377 stays partial). 12 tests. 1293 tests pass | ✅ |
| Step 10g: sector management by fields (spec ch 15, 25): the owner opens a sector and chooses the fields it needs (colour, size, measurements, weight, expiry date, warranty) and whether it takes made-to-order jobs, as well as the unit, fractions, pre-order, chat, return rule and premium line that were already there. A seller's form shows only those fields and anything else is dropped; where expiry is asked it is a must and cannot be in the past; a product past its date is no longer shown or sold; the colour-and-size grid is only for sectors that use colour or size; the product page shows weight, expiry and warranty. A sector opened before this keeps colour, size and measurements. The made-to-order switch is stored; made-to-order still opens for wood and clothing. Item 44 (a craftsman never sees the price or profit) was already built and tested in the craftsmen work, so it is ticked. 13 tests. 1306 tests pass | ✅ |
| Step 10h: a shop's own page and Follow (spec ch 13, 05): /shop/{seller} shows the shop's name, area, how long it has been here, its rating (the same rank that sorts sellers; "New" until proven), how many follow, and its products as this viewer may see them; only a trade seller whose own name the owner switched on has a page (the rest stay under our name, with no link, no page and no follow, and a visit goes home). The shop name on a product page and the cards link to it. A signed-in buyer (not the shop itself, not staff) follows and unfollows; followers are told once when a new product of the shop is first shown (not on later edits), and nobody is told if the shop's name is off. One new message kind, 13 tests. 1319 tests pass | ✅ |
| Step 10i: central product catalog and one product with many sellers (spec ch 13): the owner keeps a catalog (one name per product, with an optional Bangla spelling only to find it; product names are never translated), adds to it and answers the products sellers ask for (the seller is told); a sector can require sellers to pick from the catalog, otherwise it is optional; a changed catalog product is read again like a new listing. A product page with two or more sellers of the same catalog product lists every visible seller's price and delivery charge, best first; the order is price plus delivery charge made up to a quarter dearer for a poor record (returns, not received, slow to send, from the existing seller rank), so a low price with a high delivery charge does not win; names show only for shops with their own page and the rest are numbered; the market list shows one card per product with how many sellers. 18 tests. 1337 tests pass | ✅ |
| Step 10j: the product page of the market, completed (spec ch 13): reviews and ratings with photos only from a buyer whose order arrived (one review for one order, stars 1-5, words with no links or phone numbers, up to three photos; shown as "Verified buyer" with no name; average and count on the page and on the market cards; we can hide one); several photos on a product (the first plus up to five, the seller adds, removes and picks the main one without a new review; the buyer taps a photo to open it large, thumbnails, and a colour's photo comes first); a Share button (the phone's own share sheet, or copy the address). The colour-and-size variants (295) were built earlier. These are for the market; medicine product pages are unchanged, and a review video is not built. 19 tests. 1356 tests pass | ✅ |
| Step 10k: our own delivery people (spec ch 20): a new "Delivery person" role (permission `deliveries`) sees only its own list; the office sends a checked home-delivery order with one of them from the order page (that sends the order, and the buyer gets a six-digit code by text — the text service is still the log one, so the code is also on the buyer's private order page). The list is theirs only, grouped by area, with where to go, how to find it, the phone and the money to collect; "I have arrived" tells the buyer once; an order is delivered with the buyer's code (five wrong tries lock it and tell the office) or, when the buyer cannot give it, a photo of the parcel, and either one is the proof: a checked order not yet made into a memo becomes a complete sale then. The money each person took is kept until the office counts it and presses Received (Delivery money page). After delivery the buyer rates the delivery and the service (1-5, a comment); we see averages per delivery person and per staff member (buyers never see names) and a low rating (1-2) notifies the office at once. The code never goes to the list, the office page or after delivery. Not built: the text sending itself, courier tracking. 25 tests. 1381 tests pass | ✅ |
| Step 10l: the VAT (Mushak) report for the accountant (spec ch 25): /admin/reports/vat shows, for a date range (this month by default, never backwards, at most a year) and an optional kind of sale (retail, shops, trade), the finished memos grouped by VAT rate: sales with the VAT inside, the VAT, what was returned and its VAT taken back out (from approved returns, by the rate on the returned line), net sales, net VAT and net sales without VAT, then every memo; the end discount lowers a memo's sale and its VAT in the same share. A file download (opens in Excel, Bangla names kept) and a print view for the accountant. For people who see sales only. A note appears when VAT is switched off in Settings. The market has no VAT yet. 10 tests. 1391 tests pass | ✅ |
| Step 10m: the pickup point (spec ch 20): a seller whose own name the owner shows writes where buyers may collect (their shop address) and allows it product by product, without a new review; only then does the product page offer "I will collect it, no delivery charge" (for every other seller nothing appears and the server refuses it). A collected order needs only a name and phone, has no delivery charge, and the place is given to the buyer only after the seller accepts (with a message); the seller presses Handed over when they take it, which completes the sale and our commission; it cannot be marked as sent. If nobody comes, the buyer is reminded a few days after it was ready (3, a setting) and once only, and a few days later (3 more, a setting) the order is cancelled by the system, the goods go back on the shelf and the buyer is told. The buyer pays the seller on collecting, so no money is held and the 50% rule has nothing to apply to. Two new message kinds, two settings, 18 tests. 1409 tests pass | ✅ |
| Step 10n: temporary powers for a sub-admin (spec ch 25), and two items found already built: on the Staff page the owner (password asked again) lends a staff member extra permissions for a set number of hours (at least half an hour, at most 30 days), with a reason; the staff member is told and the activity log records it; the powers count in every gate while they last and stop by themselves; the owner takes them back at any moment; managing staff and roles is never lent, only an open staff account can be lent to, a closed account keeps nothing, and the role itself is never changed. Also ticked: 535 central list management (the three lists are medicines, food ingredients and the product catalog, each kept by the owner with sellers asking to add) and 589 main photo selection (the seller makes any photo the main one). 13 tests. 1422 tests pass | ✅ |
| Step 10o: the seasonal forecast (spec 6.9): on the monthly report page, "Last year this sold a lot at this time" looks at the next 30 days (a setting) of the same days last year and sets them against the last 30 days now; a medicine that sold at least five units then, and is selling clearly less now (under two thirds of last year's pace, or not at all), is listed with last year's pace, this month's pace, what is on hand and how many to order (last year's pace for the lead days plus the cushion, less the stock). Medicines that sell the same all year are not shown. For people who see sales only. 8 tests. 1430 tests pass | ✅ |
| Step 10p: the counter's draft screens finished (spec 4.3.5, DRF-5, 7, 9): the open-drafts heading shows how many are open and, when more than one person has drafts, how many each has; every draft shows who opened it; a customer with two or more open drafts is pointed out on each ("This customer has 2 drafts open"); each line on a draft has a Move… menu that sends it to another open draft of the same kind (the rate becomes that draft's customer's, the stock does not move; another kind, itself or a finished sale are refused); adding the same medicine in the same pack again adds to its line and says so. 10 tests. 1440 tests pass | ✅ |
| Step 10q: two small gaps closed: the shop-tier offer forms (the suggestion rows and the by-hand form) now have a "Runs until (optional)" date, so an offer can be given its own last day instead of the end of the month (spec ch 09, item 214; the date still counts on its last day, a past date is refused, and without one it runs to month end); and the shop's medicine search has a "Show prices as: Taka / Percent off" switch that changes which figure is big and remembers the choice on the device (spec ch 04, item 29; the admin already entered rates either way). 3 tests. 1443 tests pass | ✅ |
| Step 10r: generic alternatives and stock-first search (spec ch 11, 6.10): under a medicine a shop finds, "The same medicine, other brands" lists up to three other brands of the same generic that are in stock and either on offer or discounted for that shop, offers first and then the biggest discount, each with its price, percent off and an Add button (no stock numbers; a medicine with no generic, no other brand, a switched-off brand or a brand out of stock has none); and in both the shop search and the counter search the medicines that are in stock come first, then the rest, each by name. 8 tests. 1451 tests pass | ✅ |
| Step 10s: "10 people wanted it, 6 came" (spec ch 21): in the buying list each medicine has a "Boxes that came" box and a Share out button; the boxes are shared among the accepted, not-yet-received pre-orders in the order they were asked for (a strip or piece counts as its part of a box, whole units only), so the first get all they asked for, the one it runs out on gets what is left, and the rest get none; what each is given is written on its line and becomes the amount to receive in the Receive form (nothing is received until the staff press Receive); everyone who got less than they asked for is told ("Only 2 of the 4 you asked for came", or "none this time, you are first in line for the next lot"), those who got all are not; sharing again starts afresh. Item 202 (a product's own mandatory delivery charge, the highest layer, in-zone and out-zone) was already built and tested with the delivery charges, so it is ticked. 10 tests. 1461 tests pass | ✅ |
| Step 10t: separate confirmation for big changes (spec ch 26): besides staff, roles and stock corrections (already asked), the owner is now asked for the password again, valid for three hours, before closing or opening a customer's account, before entering or removing a customer's own price, and before changing a company's or a medicine's rate; without it nothing changes and the person is sent to the password page. This is what stops a stolen session alone from making the big changes. Two-factor sign-in (item 12) is separate and still waits for the text service. 7 tests (13 older tests now confirm the password first). 1468 tests pass | ✅ |
| Step 10u: the abnormal-price warning (spec ch 04, item 33) and a check on 161: a new price far below the one before, or zero, is not refused but asks "This is 90% lower than the previous price. Are you sure?" and goes through only with "Yes, the price is right"; this is on the printed price of a medicine (box, strip, piece, each checked on its own) and on a seller's price on the market; a first price, a rise or a small drop never asks; how far counts as far is a setting (70% by default). Item 161 (packed but not collected: the order is cancelled, the goods go back, late cancellations are counted over 90 days and the shop is flagged at a limit set in Settings) was already built and tested with the cancellation rules, so it is ticked. 10 tests. 1478 tests pass | ✅ |
| Step 10v: two gaps closed in the counter (spec 6.2, 6.10): the retail end discount now gets the loss check every other rate has (item 69): staff cannot set an end discount that takes any line without its own price below the cost of the cheapest lot in stock, the admin is told that staff tried, and only the admin may go on; and a barcode the counter does not know is asked about instead of refused (item 106): the person picks the medicine, the code is linked and remembered, the box goes on the draft, and the next scan just works (a code already linked is never moved to another medicine). Scanning by USB or Bluetooth scanner works at the counter, in stock entry and in the count; scanning by the phone's camera (107) is still not built. 11 tests. 1489 tests pass | ✅ |
| Step 10w: category-based access finished (spec ch 05, 14; items 37, 322): the categories the owner can give a trader were a fixed list of five, so a sector opened later in the sector builder could not be given to anyone; now the list is the starting five plus every sector the owner has opened and kept on (one helper, App\Support\Categories, used by the customer page, the applications page, the business application form, the trader's own account page and the market page); a switched-off sector or an invented one is refused. A trader lists and sees only the categories they were given, and a category they do not have is invisible to them. 7 tests. 1496 tests pass | ✅ |
| Step 10x: delivery zones (spec ch 20, item 493) and a check on item 32: a new setting "Deliver outside that district" (on by default) can be switched off; then an order to another district is refused with "We do not deliver to this district. You can collect it from the shop", the checkout says so before the buyer tries (the quote comes back closed with no courier choices), changing an address to such a district is refused, and our own district and collecting from the shop keep working. Item 32 (the order of discounts: the price, then the quantity step, then the token, then the return credit, and an offer or special price never combined with a token) is ticked: each step was built and tested in its own part (quantity steps, tokens, return credit). 5 tests. 1503 tests pass | ✅ |
| Step 10y: SEO (spec ch 27, item 584): /robots.txt (now made by the app, not a file that let everything in) keeps the staff, trader, shop, MPO, craftsman and restaurant sides, orders, carts, checkout and sign-in pages out and points to /sitemap.xml; the sitemap (an index, a pages file and a file for each 5,000 medicines) lists only what any visitor can already open: the shop's medicines that are on, companies, the market products a stranger may see today (published, shown, sector open, not past its date, with a public price), shops that have a page, open restaurants and games, and the open information pages; a hidden, paused, trade-only or expired product is not listed; every private page and every page that only the holder of its link should see carries a no-index header; the medicine page, the market product page and the shop page now have a description, a canonical address, link-preview tags and schema.org product data (with the star rating when there is one). The pages are drawn in the browser (no server rendering), which Google reads but simpler crawlers may not. 11 tests. 1512 tests pass | ✅ |
| Step 10z: the sector's chat and made-to-order choices now do something (spec ch 15, item 369): once the owner has opened a sector called wood or clothing, its own "Chat" tick decides whether that kind of made-to-order job has chat, and its "Made to order" tick decides whether the job can be started at all (a refused one says "we are not taking made-to-order jobs of this kind now"); until such a sector is opened the old Settings switches and the open door behave as before. The "Pre-order" tick of a sector still only changes the availability word on a sold-out market product (a market pre-order is not built), so 369 stays partial. 4 tests. 1516 tests pass | ✅ |
| Step 11a: a receipt in every sector (spec ch 22, item 510): market, food, game top-up and made-to-order orders each have a clean printable receipt at /receipt/{kind}/{private link} (our name, place and phone; number and date; buyer; each line; every step on its own line - goods, delivery charge, token taken off, price, paid ahead - and the total; how it is paid and its state; the return rule for that kind, "Returns do not apply" on a job made to the buyer's measurements and "a top-up cannot be taken back"); food is in the restaurant's name with ours small at the bottom; a market seller's name appears only where the owner shows it; no cost, commission, craftsman or wage ever appears; it prints on A4 or on a 58 mm or 80 mm receipt printer from the same page; each buyer's order page has a "Print the receipt" link; the page is no-index and out of the sitemap. 8 tests. 1524 tests pass | ✅ |
| Step 11b: draft sale and complete sale kept apart (spec 6.0, items 48 and 50): every invoice now has a `completed_at` (migration backfills the old ones): a counter sale and an online order the shop collects are complete when finished; an order we send or deliver is only a draft sale after the counter finishes it and becomes complete when the buyer says it arrived, the delivery person proves it, or it confirms itself after the set days (a buyer who says it did not arrive leaves it a draft sale). The sales report, the monthly figures, the days table, the best-customers list, the export, the VAT report and a shop's tier month all count only complete sales and by the day they became complete, so an order sent on the 30th and delivered on the 2nd is next month's sale; cash in the drawer still follows the counter. With the stock entry draft, the open counter bill, the delivery-charge draft and the pre-order draft memo that were already separate, all five meanings of "draft" now have their own mechanism. 8 tests. 1532 tests pass | ✅ |
| Step 11c: the buyer edits a placed order (spec ch 13, item 315): for the same short time they can cancel in, and only while staff have not started picking, a buyer with the private link can change the amount of an item (whole numbers only) or take an item off; the last item cannot go (that is cancelling). Step prices and offer minimums are worked out again, and the delivery charge follows what is left (free-over line, area charge). Too late, wrong link or wrong line is refused with a plain message. 9 tests. 1541 tests pass | ✅ |
| Step 11d: wishlist (spec ch 13, part of item 305): a signed-in buyer saves a market product with one button on its page and sees the list at `/wishlist` (linked from the account page) with the price they would pay and whether it is available; a product that is switched off or that the buyer may not see leaves the list without a word and cannot be saved; each buyer has only their own list (max 200). The mixed cart across sectors stays partial because market products are still ordered one at a time. 5 tests. 1546 tests pass | 🟡 |
| Step 11e: MPO profile counts (spec ch 10, part of item 220): the owner's MPO page now shows how many products the MPO submitted, how many were approved and how many sold (fulfilled pre-orders), next to the failed-delivery count, territory, companies, products and the owner's notes. The profile photo is still missing (no photo field on accounts yet). 1 test. 1547 tests pass | 🟡 |
| Step 11f: market filters and sorting (spec ch 13, item 283): the market list can now be filtered to products rated 4 or 3 stars and up (only shown reviews count; unreviewed products drop out) and sorted by most ordered (cancelled orders do not count) besides nearest, newest and price; category, price range, search and premium stall were already there. 3 tests. 1550 tests pass | ✅ |
| Step 11g: an offer product's own rate per group (spec ch 9, item 197): besides who sees it (public / shops / wholesale) and the common rate, the owner can give each group its own offer rate; a group without one gets the common rate. A shop is priced at its group's rate on the order, the step price still adds on top, a shop's own better rate still wins, and the deepest rate any group gets must still keep the minimum profit margin. 3 tests. 1553 tests pass | ✅ |
| Step 11h: SEO info on a product (spec ch 25, part of item 529): a seller can write a search title (70 characters) and description (160) for each market product; the product page uses them for the page title, description and link preview, and falls back to the product's own title and text when empty. Still missing for 529: a pay-on-delivery toggle per product (waits for online payment), product video, and the advance delivery charge. 1 test. 1554 tests pass | 🟡 |
| Step 11i: trust signals (spec ch 27, item 578): a "Trusted seller" badge on market cards and product pages for sellers we marked or who have enough clean deliveries (no complaint, no return); it says nothing about who the seller is. With the return-policy page, the FAQ, reviews with photos marked "Verified buyer", and the real address and licence in the footer that were already built, all five trust signals of the chapter are in. 1 test. 1555 tests pass | ✅ |
| Step 11j: staff screens stay live (spec ch 7 and 28, item 142): the order-checking screen now picks up what another staff member changed every 5 seconds while the order is open (and not while the browser tab is hidden), so both screens agree without a refresh; "who is working on this order" and "first click wins" were already in, and the counter drafts and the buyer's page already refreshed themselves. Front-end only; no new server code. 1555 tests pass | ✅ |
| Step 11k: pre-order a sold-out colour (spec ch 15, item 371): where a sector allows pre-order, a product that is out of stock, or only its sold-out colour and size, can still be ordered; the page says it is not in stock and the button reads "Send the pre-order"; nothing is taken off the seller's shelf and a cancelled pre-order puts nothing back; the seller's order list and the buyer's order page both mark it as a pre-order. Where the sector does not allow it, a sold-out colour stays greyed out and refused as before. 1 test. 1556 tests pass | ✅ |
| Step 11l: wood, clothing and other sectors return through the same flow, with the seller's own rules (spec ch 15, item 377): the return and complaint screens were already shared by every market sector; now a seller can set, per product, how many days the buyer may send it back (1 to 30, else our usual 7) and a plain condition such as "unused, in its wrapping". The product page, the buyer's order page, the receipt and the return check all use the seller's days and note. Made-to-measure orders still have no returns. 1 test. 1557 tests pass | ✅ |
| Step 11m: four more todo items closed (items 246, 414, 498, 499): (a) other names for a product (spec ch 29C) — a seller lists the names people call it by, and the market search finds the product by any of them; (b) wood and fabric stock (spec ch 16) — the owner can count each wood or fabric, the memo records how much a job uses, and that amount comes off the stock when work starts (not enough stock stops the start with a clear message; a material with no count is left alone); (c) size and colour in other sectors and (d) limited options on pre-orders (spec ch 21) — the colour and size grid and the pre-order of only the cells that exist were already built in steps 11k and earlier, now ticked. 3 tests. 1560 tests pass | ✅ |
| Step 11n: advance delivery charge (spec ch 20, item 479): a setting "delivery charge paid first on orders of (taka)" (0 = never). Above it, an order that is sent or delivered must have its delivery charge received before it goes: the order page for staff shows "Delivery charge first" with a "received" button, sending and giving it to a delivery person are refused until then, the buyer's order page and the checkout say why in plain words. A small order is not affected. Not yet done: a note on the product page ahead of ordering, and online payment of the charge (staff mark it received by hand until a gateway exists). 2 tests. 1562 tests pass | ✅ |
| Step 11o: money when goods are not taken (spec ch 12, items 274 and 275) and delivery by arrangement (item 494): (a) a delivery charge that was paid first and then the order is cancelled before it is sent gives back half (the other half is the handling cost), shown to staff and to the buyer; an order that was sent keeps the whole charge; an order that never paid it refunds nothing; (b) a pre-order advance for goods that were not taken can be returned in half with one button, and the amount returned is recorded; (c) made-to-order work is "delivery by arrangement" and never goes by courier — already stated to the buyer on the order form and the job page, now ticked. 2 tests. 1564 tests pass | ✅ |
| Step 11p: courier charge in the wholesale market (spec ch 20, items 477 and 478): when staff send an order for a shop or wholesale buyer they can choose "as quoted", "shared 50-50" (they write what the courier charged and the buyer carries half) or "a fixed amount", and the number of cartons; what is entered becomes the buyer's delivery charge, shown as an extra line only when it is more than was quoted, and the terms and cartons show on the order. Retail orders cannot use it. The weight side was already in the delivery quote. 1 test. 1565 tests pass | ✅ |
| Step 11q: price-drop alert (spec ch 13 and 27, Phase 2, item 606): the wishlist now remembers the price each buyer last saw; every day at 11:00 Bangladesh time the system looks at each saved product at the price that buyer would pay today and, when it fell, tells them in their inbox ("cheaper now: X instead of Y") with a link to the product. It is told once per fall; a rise only moves the remembered price up, so a later fall is told again. A product the buyer can no longer see is never mentioned. 2 tests. 1567 tests pass | ✅ |
| Step 11r: abandoned-cart reminder (spec ch 27, Phase 2, item 605): the cart stays in the session as before (a visitor's cart is never stored), but for a signed-in buyer the system now notes only that a cart exists and since when. Every hour it tells, once, a buyer whose cart has sat untouched for a day ("you left items in your cart", link to the cart); touching the cart again starts the wait over, and an emptied or ordered cart is forgotten. 3 tests. 1570 tests pass | ✅ |
| Step 11s: referral credit (spec ch 24, half of item 603): every account has a code and a link (`/r/CODE`) shown on its wallet page; a friend who opens an account through the link, and whose first order is then completed (a finished sale, or a delivered market order), earns the sharer one Aqua Token, once per friend. The amount is the owner's setting ("Token for a friend who joins by a referral and orders", 0 = off, the default); nobody can earn from their own code or an unknown code. The wallet shows the link and how many joined and earned. Reward points are not built (no rules in the spec), and the "one account per phone" check that guards against fake accounts waits for the SMS code. 5 tests. 1575 tests pass | 🟡 |
| Step 11t: one order history, and a consistency pass (items 313 and 369): (a) a signed-in buyer opens "All your orders" (`/history`, linked from the account page) and sees every order from every part of the site together, newest first — medicine, market, food, game top-up and made-to-order work — each with a plain status (in progress, done, cancelled), its total, and a link to its own page; only their own orders, and no business-side words; (b) the per-sector switches (fractions, pre-order, chat, made-to-order, return rule, premium line, fields, unit) are now all real — pre-order became real in step 11k and chat and made-to-order already followed the sector — so item 369 is ticked. 3 tests. 1578 tests pass | ✅ |
| Step 11u: announce updates in advance (spec ch 28 note 11, item 602): the owner can write a notice (and the same in Bangla) in Settings under "Orders and closed days"; it shows as a banner at the top of every page, public and admin, in the language the visitor reads (Bangla falls back to the main text), and goes away when the text is cleared. The server update itself still happens at night by hand. 3 tests. 1581 tests pass | ✅ |
| Step 11v: festival peaks and old data (spec ch 28 note 11, items 601 and 598) — audited, nothing built, left open on purpose: the hot paths already carry what a peak needs (every stock change takes a row lock inside a transaction; the order lists, private-link lookups, lots by medicine, and buyer histories all have indexes; carts live in the session; messages and reminders run from the scheduler), so no code change would add anything without real load numbers. What is left is operations: a real queue worker and cache (the sample settings still use the database for both), a load test on the live server, and an archive for years-old activity logs once there is enough data to need it. Neither is ticked. | ☐ |
| Step 11w: the market cart, split by seller (spec ch 13, items 305, 310 and 311): "Add to the market cart" sits beside "Send the order" on every market product; the cart (`/market-cart`) holds products from any sellers and sections, shown as Package 1, Package 2 by seller, each with its own delivery charge (the dearest of that seller's products), and re-priced for the viewer each time. One checkout (name, phone, address once) makes one order per product, all or nothing — if one line cannot be filled nothing is placed and the cart is kept — and the orders share a private group link (`/market/packages/…`) that lists the packages with their sellers, status and tracking. A token goes on the first package only. The seller's own name stays hidden unless the owner shows it. Each seller still sees and ships only their own order. 7 tests. 1588 tests pass | ✅ |
| Step 11x: one person, two businesses (spec ch 3 and 15, item 21): a seller who sells in more than one section (for example wood and clothing) sees a switcher at the top of "How you are doing" — each business on its own, or "all together" — and for the chosen business the products, the best and slowest sellers, what is running low, the month-by-month sales and the commission owed come only from that business, never mixed with the other. It is one login with a switcher, as the chapter's note allows; the seller's rank (speed, returns, missing parcels) stays one score per person because buyers meet the person, not the section. 1 test. 1588 tests pass | ✅ |
| Step 11y: pre-order is one feature that goes to whoever turned it on (spec ch 21, item 496): an MPO's pre-order goes to that MPO, ours to us, and now a market seller who turns pre-order on for their own product (through the section's switch) names the time — 2, 3, 4 or 5 days, or leaves it to us — which the buyer reads on the product page and on their order; the order goes to that seller alone and takes nothing from their shelf. 1 test. 1590 tests pass | ✅ |
| Step 11z: white-label seller screens and a tick for custom quotation (spec ch 14, items 343 and 328): (a) a selling account's own business name now heads its listings, orders, reports, statements and offers screens, so the dashboard feels like its own; the buyer's name was already hidden after the sale, the order notice comes from our site, and the name is sent only to that account; (b) custom quotation (a bulk buyer describes what they want, we answer with a total and a last day, they accept or decline, staff place the order at that price) was fully built and tested in step 7b-7 and is ticked now. 1 test. 1591 tests pass | ✅ |
| Step 12a: account types complete (spec ch 3, items 7, 8 and 20): a food vendor now applies like the other businesses — on the same neutral business page there is a third choice, "a restaurant or food business" (no trade categories asked) — and approving it opens their restaurant on their own account (the owner names the area and our commission) instead of a buying account; the craftsman account is opened by the owner (their own people) and the MPO account only by the owner, both already built; every role has its own screens and sees nothing of the others. The supplier mobile app itself (item 17) is separate and waits for the app work. 2 tests. 1593 tests pass | ✅ |
| Step 9: pre-order (spec ch 21): switch it on for a whole company or one medicine with a promised 2 to 5 days (a medicine setting beats its company), a shop, bulk buyer or plain account asks from a button next to the medicine, an optional advance (a share set in Settings), the seller accepts with a time or declines with a reason and the buyer is told, the draft memo stays editable, "Receive" writes in how much of each arrived and makes the real order for that (taking the stock then, so the goods must be in stock first) and tells the buyer for the first time, past its time turns red with a count on the menu, a new time can be given, goods that never came end with a sorry note and the advance goes back, a buyer cancelling late gets the advance back and early it is the seller's call, a buying list by company in the order asked | Done | `PreorderTest` |
| Step 10: public returns and address change (spec ch 12, 13): a plain account sees what it bought and asks for a return (reason, voice note, credit/exchange/cash), staff approve and call, credit comes off the next order; a buyer without an account is told to call; the delivery address can be changed until staff start on the order and the charge follows the new district | Done | `PublicReturnsAndAddressTest` |
| Step 11: order management and customer transactions (spec ch 25): an All orders screen for every memo and order, counter and online, filtered by kind, where, state, date, memo number, name or phone (a guest is found by the name or phone they gave), with what is still owed listed and summed and a payment taken on a memo left due (capped at what is owed, logged); the customer page lists orders, returns, pre-orders and credit together | Done | `AllOrdersTest` |
| Step 12: VAT fields (spec ch 25 dev note): a VAT rate on companies and medicines, the rate and the VAT inside the price on every memo line, worked out only when VAT is switched on in Settings (off at launch); the rate stays on a line even if the medicine's rate changes later. The two-way reorder advice (buy more / buy less) was already in the monthly report and is now ticked | Done | `VatFieldsTest` |
| Step 13: reading size and behaviour data (spec ch 27): a text-size choice (normal, large, largest) on the public pages that grows everything and is remembered on the device, in the header and in the footer on phones; searches, medicine looks, cart adds, orders sent and pre-orders are recorded from now on without any name (a visitor is a hash of a random token kept in the session, staff and the owner are left out, a failure to record never breaks a page), the privacy page says so, and a report shows what people search for and did not find, which medicines are looked at but never put in a cart, and the visitor steps from search to order | Done | `BehaviorTest` |
| Step 14: messages (spec ch 23, 25, 26): automatic status messages for an order (received, being picked, checked and ready, some products not available with the list, complete, cancelled with the reason; plus the pre-order, tier and business-account messages) to every account holder (a guest follows the order page), an inbox with urgent messages first and an unread count on a bell in the header, each message can also go by email (only to a real address) and by SMS (written to the log until an SMS service is connected) as the owner chooses on a Messages screen with the inbox always on and push not yet available, a message that fails to send never stops the order, outgoing words are in Bangla whoever clicked | Done | `MessagesTest` |
| Step 15a: delivery charges (spec ch 20): three layers worked out on the server, highest first (a mandatory charge a product carries, inside or outside the shop's district; couriers by weight when every product has one and a courier goes to that zone; the area-based flat charge otherwise; free over a level for the last two, nothing for collecting from the shop); an unlimited courier list typed by hand with a charge for the first kilo and each extra kilo in three zones (own area, inside the district, outside) and a cash-on-delivery share; the weight is rounded up by a rule in Settings; the buyer picks among couriers shown side by side at checkout and the charge is never taken from the browser; a changed address works the charge out again; a bulk weights screen, a dashboard count of medicines without a weight, and a reminder every three months to confirm the courier rates | Done | `DeliveryTest` |
| Step 15b: sending and arrival (spec ch 20): a checked order is marked as sent with a tracking note and any extra delivery charge the real cost came to (added to the charge and shown on its own line on the memo, the buyer told "on the way"), the buyer (a guest by the private link, a shop or plain account from their own page) sees "on the way" and taps "I received it" or "I did not receive it", a not-received report stops the automatic confirmation and shows red to the owner who settles it as arrived or lost, an order with no answer confirms itself after a set number of days (a daily job) | Done | `DispatchTest` |
| Step 16: working together on orders (spec ch 7): an open online order tells the server every half minute that someone has it open and shows others "Rina is working on this order" (nothing is locked), the first click on a line wins and a second click from an out-of-date screen is turned away with the name of who did it, each checked line shows who checked it; a shop can open a preliminary memo at the printed prices while its order is open (offer items stay at the offer rate, never for a finished memo, never for anyone else's order) | Done | `ConcurrencyAndMrpMemoTest` |
| Step 17a: the MPO sector, first half (spec ch 10): only the owner opens an MPO account (no sign-up) and gives it a territory, companies and his own notes, accounts are listed grouped by territory automatically, an MPO sees only their own companies, picks the products they work with (only from those companies; taking a company away takes its products too) and sends products with stock status (in stock, or pre-order in 2 to 5 days) and an optional bonus ratio such as 10+2, the owner reviews each one and chooses the markets (bulk, shop, everyone), the price in each, whether to show the bonus and the exact number, and sees the same medicine from several MPOs side by side with their real names, buyers see only "Anonymous N (store name)" at the price of their own market and order from it as an accepted pre-order, the MPO sees the orders as placed from our account with no buyer in them, the goods pass through the owner ("Goods reached me" puts them into stock from the source Through MPO at the discount paid) and the real order is made at the agreed price with the bonus as its own free line on the memo, closing an account takes its products off while orders made stand; nothing a buyer can see names the MPO | Done | `MpoTest` |
| Step 17b: the MPO sector, bidding (spec ch 10): a bulk buyer offers their own price as a percentage off the printed price (several can, each sees only their own, a new offer replaces their old one), the MPO sees the offers as Anonymous 1, 2, 3 with no names and accepts one, which makes the order for that buyer at their price (bonus in proportion) and declines the rest with a message to each, the owner sees who bid; a product on the market for bulk buyers that nobody has taken for a set number of days asks the MPO to raise the percentage themselves (up only, bulk price only); an order from an MPO product that has not arrived a set number of days after its time is cancelled, the buyer told and a failure counted on the MPO's profile; both run daily | Done | `MpoBidTest` |
| **Step 7b: wholesale selling side (ch 14: commission, masked contacts, month-end statement), then the other sectors; 2FA and device limit; offline counter** | **Next** | spec ch 14, 03, 08 |
| Step 17c: counter odds (spec ch 8, 12): a finished memo made at the counter for a shop is on that shop's own page and it can open it (open drafts and other shops' sales never); the return days count from when the goods were received (delivered), not from when the memo was made; a counter draft can change customer (same kind only, drafts only, retail has none) and the lines are priced again at the new customer's rate, lines with their own price keep it | Done | `CounterOddsTest` |
| Step 17d: stock safeguards (spec 6.7, 6.8, 9): staff get a Report mismatch button at the counter ("found 50", they never see the system number), the admin is told and reads the list, a medicine with two disagreeing reports is flagged, and fixing sets the number as an entry fix (not a loss); the stock list and a medicine's page warn about many lots and about small old lots (limits in Settings); a half-typed purchase is kept as a draft (one per person, adds nothing to stock) and comes back; a medicine's pack size and printed prices can be changed by the admin, a pack-size change asks twice and is recorded with who and when, a new printed price leaves a mark for a month; the purchase price of a lot can be corrected by the admin only, with a warning of every live offer, fixed rate and earlier sale that would be at a loss, and goods already sold are worked out again | Done | `StockSafetyTest` |
| Step 17e: end of day (spec 6.6): a screen of everything still open before closing — open drafts by the person who opened them (with how long the oldest has been open), quick notes not done, online orders and pre-orders waiting, requests waiting for the admin, mismatch reports, and for those who read reports the sales not fully paid today and whether today's cash is counted; counter staff get the list without any money, the day page shows it on top for today | Done | `EndOfDayTest` |
| Step 17f: quantity discount steps (spec ch 4): the admin sets steps per medicine and unit (from N boxes/strips/pieces, a percent or taka off each unit; none means no discount); the public price follows the quantity on the cart, on the counter's retail sale and on the placed order (a price set by hand is left alone, a shop's own rate is not touched, a partial pick keeps the price the buyer saw); the cart says "You are getting 10% off" and "Take 2 more for 15% off"; the product page shows the steps to everyone | Done | `QuantityStepsTest` |
| Step 17g: habit-based reminders (spec ch 7): from each shop's own online orders of the last three months the system learns the time of day it usually orders (round the clock, so 23:50 and 00:10 are close; no regular time means no reminder; at least three orders), and every ten minutes sends the shop a message at that time, once a day, not to a shop that already ordered today, turned it off, is suspended, or on a closed day; each shop has its own time, a bulk buyer is pointed at its own page; the shop sees the time and has an on/off switch on its order page; the owner can turn the whole thing off or change the order count in Settings | Done | `OrderHabitsTest` |
| Step 17h: stale drafts (spec 6.6): a counter draft nobody has touched for a day (a setting) is marked on the counter page with two buttons, Return to stock (closes it, goods back) and Keep (goods stay held, asked again after two days, a setting); online carts and finished sales are never asked about. Shown on the counter screen; no push or SMS yet | Done | `StaleDraftTest` |
| Step 17i: cold-chain medicines (spec ch 11): a medicine flagged "Keep refrigerated" is never offered a courier at checkout, it goes by our own delivery inside our area (the page says it is packed with ice), an address only a courier could reach is refused with the way out (collect from the shop or an address in our area, also when the address is changed later), collecting is always allowed; staff see the flag on the line ("pack with ice"), the memo carries it, and an order with a cold item that carries a courier cannot be sent | Done | `ColdChainTest` |
| Step 17j: All Companies and brand filter (spec 29-A): on a shop's or bulk buyer's order page a company can be chosen to list all its products at the buyer's own rate, 40 at a time with Show more, each ordered as a normal order line (offers keep their own rules); a bulk buyer without the medicine category sees no companies; the public search page narrows the matches by brand with counts | Done | `ByCompanyTest` |
| Step 17k: loss inside an invoice (spec 6.2): setting an invoice % no longer blocks the whole discount or lets a loss slip through — for staff, the lines the % would sell below cost keep their price and are marked, the rest get the %; each marked line takes a lower % of its own (within the staff limit, never below cost for staff, the admin is told of an attempt); the admin may go below cost and it is counted as a loss; the stock record is re-taken at the new price so profit is right and stock is never taken twice; a draft with a marked line can still be finished | Done | `LossInInvoiceTest` |
| Step 17l: the Deleted bin (spec ch 25): what the owner deletes from a screen — a tier, a staff role, a courier with its zone rates, an expense, a manual customer rate — is copied whole into a bin first and stays 30 days (daily purge, a setting); the owner's Deleted page lists it with days left and Put back writes it again under the same number, refusing when something with the same name was made since | Done | `DeletedBinTest` |
| Step 17m: image compression (spec ch 28): a picture uploaded as a prescription or a licence is scaled to at most 1,600 px (2,000 for licences), turned upright from the phone's note, put on a white page if transparent, and saved as a progressive JPEG (a rough picture first, sharper after, for slow internet); a small JPEG is never made bigger, a PDF or an unreadable file is kept exactly as it came | Done | `ImageCompressorTest` |
| Step 17n: product Q&A (spec ch 23): a signed-in buyer, shop or bulk buyer asks under a product (8-500 characters, no web address or phone number, ten a day), staff who handle orders are told and see a Questions screen with a count on the menu, answer or hide; an answered, not hidden question is shown to everyone under the product with the shop's name and never the asker's, the asker is told once in their inbox with a link; visitors cannot ask | Done | `ProductQuestionsTest` |
| Step 17o: notes, voice notes, photo and time with an order (spec ch 23): at checkout (guest, account, shop or bulk buyer) and afterwards on the order page until it is finished, a buyer can add a written note (appended), record a voice note in the browser or pick an audio file, send a photo (made smaller on the way in) and say what time it is needed by; a new voice note or photo replaces the old one and the old file is removed; staff see the time, play the voice note and open the photo on the order screen, files are private to staff who handle orders; none of it is required and nothing refuses an order; a return can carry a voice note too (recorded on the return form, played on the staff return card, private to staff who answer returns) | Done | `OrderNotesTest` |
| Step 17p: the counter without internet (spec ch 8): while the line is up the counter device keeps a copy of the medicine list, printed prices, quantity steps and barcodes (6-hourly refresh), and a small service worker keeps the offline counter page; with the line down, retail sales are made on the device (search or scan, quantity steps, end discount within the staff limit, payment method), given a temporary memo number and a printed temporary memo, and kept in the device's own storage across closed tabs; when the line is back they are sent automatically and each gets the real memo number and its true day; the same sale sent twice is recorded once; a sale the stock says could not have happened (stock below zero, or a price that is now below cost) is still recorded, outside the stock, and the owner is told "stock went below zero for these items"; a discount above the staff limit or a thin-margin medicine's ceiling is cut and flagged; loss protection travels as a biggest-discount number only where the margin is thin, never the cost or the stock; controlled medicines are not sold offline and a refused sale stays on the device with the reason. Retail only; shop (paikari) sales offline are not done. Checked in the browser with the server stopped and restarted (queue, retry, real number, refreshed copy); the service worker itself could not run in the pane, so opening the page with no line at all is untested | Done | `OfflineCounterTest` |
| Step 17q: the simple screen for staff (spec 6.10): a button on the counter draft hides everything but search, quantity and finishing (special %, bring-from-outside, invoice/end discount, change customer, merge), chosen per device and remembered; the shelf number on each stock lot (optional) was already there | Done | browser only (front-end choice) |
| Step 17r: product page extras and buy again (spec ch 13): the product page says what the printed price was before its last change while that change is recent (90 days, a setting); shows up to three medicines that often go on the same finished memo (at least 5 different memos agree, a setting; never a controlled, inactive or deleted medicine; no names, stock or buyers in the page); a plain account that buys the same medicine about every month (at least 3 buys on different days, 10-60 days apart and regular) gets one inbox reminder from two days before the next is due, once per purchase, at most three a day, with an on/off switch on the orders page and an owner setting | Done | `ProductPageExtrasTest`, `BuyAgainTest` |
| Step 17s: the Help board (spec ch 19): a public board where anyone reads approved posts (filter by Need a worker / Looking for a product / Need help, search) and signed-in people (any account type) post with title, details and contact; every post waits for the owner or a sub-admin who manage customers, who publish it or turn it down with a reason (the poster is told); who wrote a post is never shown and the contact only to signed-in people; limits per person (3 waiting or open, 2 a day, settings); a post open a month asks its owner "Still needed?" and closes after a week of no answer; the owner closes a solved post; comments are plain words or a link to one of our products which shows as a card (photo, name, the viewer's own price, View; "no longer available" if removed; no card at all if the viewer may not see the medicine), other links and phone numbers are refused, no photos anywhere; people can report a post and enough reports take it down until we look; the "we are not responsible" notice is on every page. Browser-checked | Done | `HelpBoardTest` |
| Step 7b-1: the marketplace beyond medicine, selling and browsing (spec ch 13, 14, 15): the owner opens sectors (code, name in English and Bangla, unit, fractions allowed, premium amount, pre-order and chat switches stored, return rule shown on the product page); a trade account sells in the sectors it was given (never medicine; a shop or public account never) with a public price, a trade price with a minimum, its own delivery charge and a count; every listing waits for the owner or sub-admin (queue shows days waited and no-photo, prohibited words are flagged), who set our commission as a percent or taka on top; sellers can ask for less and we change it any time; price, stock and delivery change at once while words, photo and sector go back to be read; buyers see the seller price plus commission as one price and never the parts; a trade-only product does not exist for anyone else; every product carries our name unless the owner switches the seller's own name on; the market page has sector icons, a budget that offers only ranges that hold products, a Premium stall that fills and empties by itself as prices change, search, sorting, and sellers in the buyer's own area first; a listing with orders on the way cannot be deleted, only paused; holiday mode for sellers | Done | `MarketplaceTest` |
| Step 7b-2: market orders and month-end statements (spec ch 14): a buyer (guest or any buying account) orders with name, phone and address and pays the seller on delivery; the price, seller price and our commission are kept per order; the seller is told, accepts, sends with a tracking note or cancels with a reason, and never sees the buyer's name, sees phone and address only while the goods are on their way (then ***), and sees only what they are paid; the buyer follows it from a private link, cancels before it is sent, confirms or reports not received (not a complete sale), or a sent order is taken as received after a week; stock the seller counts is held and given back on cancel; no buying your own product; commission counts only on delivered orders and the owner sees who owes how much and how many of each product sold per month, and orders reported not received; on the first of each month each seller gets "Sales were X, our commission is Y" and answers Paid or Problem (reason needed), we check or send it back; no answer in 10 days switches selling off (not the account) until they answer; the unseen order count shows on the seller's menu. Money never passes through us. SMS, penalties, complaints, returns to the seller and white-label extras are not done | Done | `MarketOrdersTest`, `StatementsTest` |
| Step 7b-3: complaints and returns on market orders (spec ch 14): after it is sent a buyer can complain, and for a few days after it arrived (setting, 7) send it back; the seller sees the reason (never the buyer's name) and solves it, takes the goods back, or says they cannot (which brings it to us); a case left unanswered for the statement wait comes to us on its own; we get a copy of each and decide on the serious ones (send back or close, with a note); a returned order is no longer a complete sale (no commission), stock goes back, and returns with reasons show on the seller's record; admin sees how many complaints and returns stand against each seller. Tests in CasesTest. | Done |
| Step 7b-4: reports for sellers and supplier rank (spec ch 14): every market listing page opened counts one look per person per half hour (not the seller's own); the seller's report (`/wholesale/sell/reports`) shows each product's looks, orders and earnings, flags 'looked at but never ordered' with the picture first and the price second, names the best earner, and shows twelve months with the rise on the month before; a rank from 0 to 100 over the last 90 days (up to 40 off for returns, 30 for 'not received', 30 for slow sending; a seller with fewer than 3 finished orders sits at 70, labelled New) puts better sellers higher when the market is sorted by nearby, and shows beside each seller on the admin complaints page. Tests in SellerReportsTest. | Done |
| Step 7b-5: gradual trust and penalty steps (spec ch 14): a new seller may list products only up to a set price (settings: 5000 taka, until 10 delivered orders that never had a complaint or return; the owner can trust a seller sooner or take that back); the owner takes steps in order of his choosing — warning, a listing taken down (with the reason as the note the seller reads), selling switched off, payouts held — each with a written reason that the seller is messaged and shown on their statements page; a penalty stop is kept apart from a statement stop so answering a statement cannot undo it; sellers with 3 'not received' in 90 days (setting) are flagged for a look; the admin complaints page lists every seller with trust, rank, next step and history. 'Payouts held' is recorded and shown but holds nothing until online payment exists. Tests in PenaltiesTest. | Done |
| Step 7b-6: report a copied product (spec ch 14): anyone signed in except the seller can say a market product is a copy (once each, with a reason); the owner sees the open reports with the other listings that share its picture (same file) or its title, takes it down (the listing goes off with the reason as its note, the seller is messaged through the penalty step, and every reporter of that listing is told) or leaves it up with a note. Tests in ListingReportsTest. | Done |
| Step 7b-7: bulk order upload and custom quotation for bulk buyers (spec ch 14, Phase 2): a bulk buyer with the medicine category uploads a CSV (name, quantity, unit; name may carry the strength; comma, semicolon or tab; up to 300 lines) on the wholesale order page; matched lines go into the order to read through and place, the rest are listed with the reason and nothing is guessed (a bare name that fits two strengths is refused); stock and cost are never shown. A bulk buyer asks for a price on anything (`/wholesale/quotes`: what, how much); staff with the orders permission answer with one total and the days it holds (`/admin/quotes`); the buyer accepts or declines, a price past its last day cannot be accepted, staff are told on acceptance. Accepting does not place the order by itself — staff place it at the quoted price (so the quotation is only partly built). Tests in BulkAndQuotesTest. | Done |
| Step 8a: made-to-order wood and clothing, buyer side and our memo (spec ch 16): anyone who buys (public, shop, bulk buyer) signed in asks for wood work (wood chosen from a list where each wood has its own rate, size in inch/foot/cm, polish, drawers, handles, glass, a photo of the place) or clothing (men's or women's measurements for one to twenty people, fabric from us at a rate or the buyer's own, collar, sleeve, buttons, pockets, a design photo); measurement profiles are saved by name with their date, edited, asked about again when older than six months (setting), listed with the orders they were used for, and several can go in one order, copied into it so later edits never change an agreed job; we make the memo ourselves (material cost, wages, price, advance percent default 30, days, note; urgent adds a percent, default 20), the buyer sees one total and never the costs, ticks that they checked the measurements and confirms, the history keeps who gave and confirmed what and when; work starts only once the advance is in and wages are set; clothing has a trial step; ready work not collected is reminded after seven days; cancel before work starts refunds the advance, after it does not (we cancel: always refunds); no returns (said on every screen and the memo) but a 'work not done right' complaint we answer with fix, refund or the buyer's own measurements; no commission anywhere; profit = price − material − wages shown only to us; the owner sets the rates of woods and fabrics. The buyer never meets the word craftsman (G1). Not yet: craftsman accounts and jobs, wages by percentage, chat (steps 8b and 8c), stock deducted for wood. Tests in CustomOrdersTest, checked in the browser (clothing form in Bangla). | Done |
| Step 11a: the site and the outside services, set from the admin panel (spec ch 27, 28: nothing hard-coded). **Site design** (`/admin/site`): the shop name (English and Bangla), tagline, logo and browser icon (uploaded, versioned, long-cached), contact details (phone, email, address, WhatsApp, Messenger, drug licence, opening hours), social pages, the home page heading and line, which shop cards show and in what order and whether each is open or opening soon, a notice bar, the footer (about, links with Bangla words, bottom line), and the search-engine description. Defaults are in `config/site.php`, saved values win, an empty value falls back to the older `.env` brand settings, so the site is complete before anyone saves; the cache is cleared on every save; links must be a path on the site or https (never javascript:, data: or a protocol-relative address); a card can only point where the code allows; the audit log names the fields changed, never the words. This also fixes the home page that said "coming soon" for sections that were already built. **Connections** (`/admin/connections`): a catalogue (`config/integrations.php`) of text messages, sign-in (Google, Facebook), payment (bKash, Nagad, SSLCommerz), courier (Steadfast, Pathao, RedX) and maps (OpenStreetMap, Google), each with its fields; secrets are encrypted in the database with the app key, never sent back to a page (only "set" and the last four characters), a blank secret keeps the old one, the audit log names fields only; a test or live mode; only one text service or map on at a time; a service whose code is not built can have its keys saved but cannot be switched on, and the screen says so. Built and working now: the text service over HTTP (any Bangladeshi text provider: address, GET or POST, field names, key kept encrypted, https and public hosts only so it cannot reach inside the network, nothing real sent in test mode, a Try button that records the answer as a fixed sentence); the site's text gateway now follows this setting. Not built yet, in this order: 2FA (needs no provider), social sign-in, courier booking and tracking, online payment, maps and automatic location. 37 tests. 1631 tests pass. Checked in the real browser on MySQL: the name saved from the admin page showed on the public pages, all six home cards open, and the secret was encrypted in the row and absent from the page | ✅ |
| Step 11b: two-step sign-in (item 12; needs no outside service). A person turns it on under Settings > Two-step sign-in: the password is asked first (so someone at an open screen cannot lock the owner out with their own phone), a QR code is drawn in the browser (no outside service) with the key to type by hand, and it only counts once a code from the phone has been proven; then eight one-use recovery codes are shown once and kept as hashes. The codes are standard time-based codes (RFC 6238; checked against its test values) written in the code itself, accepted one 30-second step either side and never twice (the last step is kept). At sign-in the password alone signs nobody in: they are held at a code page for ten minutes, five wrong tries a minute lock it, a recovery code works once, and every sign-in rule that already existed (device limit, login log) still runs once the code is right. Turning it off and making new recovery codes need the password. The secret is encrypted in the database, hidden from every page and log. The owner can switch it off for a person who lost their phone (Staff page), and can require it of staff and the owner (Settings > Security; off by default). 28 tests. 1659 tests pass. Checked in the real browser on MySQL: setup, the QR drawn, recovery codes shown once, the half-signed-in state refused on a protected page, a wrong code refused and the right code let in; the dev admin was then switched back off | ✅ |
| Step 11c: sign in with Google or Facebook (item 2), switched on from Admin > Connections with the owner's own keys (the screen shows the exact return address to register with them). Buttons on the sign-in and sign-up pages appear only for the ones that are on. Rules that keep it safe: an outside account already joined to a person signs that person in; an unknown one is joined to an existing person by email only when Google says the address is verified (Facebook's is never taken as proof), otherwise the person is told to sign in with their password and connect it in Settings, so nobody can take over an account by opening an outside account with someone else's address; only a plain buyer account can be signed into this way (staff, the owner, shops and trade keep their password and second step); a new person is always a plain buyer account, never anything with powers; two-step sign-in, if on, is still asked afterwards; a closed account cannot sign in; Cancel at the provider and any provider failure give a calm message with no details. Settings > Connected accounts lets a buyer connect or disconnect; someone who signed up this way has no password yet, can set one without a current one, and cannot disconnect their only way in until they do. The keys are read from the saved settings only at the moment they are needed. Not tested against the real Google or Facebook (no keys here): the provider is simulated in tests, and on the real server the redirect to Google was checked to carry the saved client id, the exact return address, the scopes and a state token. 26 tests. 1685 tests pass | ✅ |
| Step 11d: courier booking and tracking with Steadfast (item 492, spec ch 20). A courier on the shop's list can be tied to a connected company (Couriers screen, "Book parcels through"); the keys, an optional password for their progress messages, and the address to give them are set in Connections. For an order that goes by that courier, staff press Book the parcel: the company's own number is saved, their tracking code becomes the order's tracking note, the order is marked sent and the buyer is told, exactly as before. The call follows the company's own published API (Api-Key and Secret-Key headers, POST /create_order with invoice, recipient name, phone and address and the cash to collect, GET /status_by_cid, GET /get_balance for the test button). Care taken: a parcel is booked once only (the order is locked while the company is asked and a saved number blocks a second booking, because a second booking is a second charge); real parcels are booked only when the connection is Live (Testing mode refuses); the cash to collect is what the buyer still owes after any advance; the phone is turned into 01XXXXXXXXX; an order that is not checked, is collected, has medicine that must stay cold, or has no company on its courier says plainly why it cannot be booked; a company that says no, is down, or refuses the keys gives a short fixed sentence that never repeats what they wrote; the address is https only and never a private network. Progress comes back by their message (a public address that exists only when the company is on and a password is saved, needs that password in the Authorization header, and can only change a parcel we booked, matched by their number and our invoice reference together) and by asking every thirty minutes for parcels booked in the last thirty days. A delivered parcel counts as delivered unless the buyer said it did not come; returned, part-delivered and on-hold parcels come to the office. The buyer sees a plain word (With the courier, On hold, Part delivered, Delivered, Returned) and never the company's internal number. Pathao and RedX need city and zone numbers per address and are not built (item stays partial). Not tested against the live Steadfast (no keys here): their answers are simulated from the documented shapes; on the real server the public address was checked to answer 404 while off, 401 for no or a wrong password, ignore an unknown parcel, and refuse a malformed message. 34 tests. 1719 tests pass | 🟡 |
| Step 11e: online payment with SSLCommerz (items 572, part of 351; spec ch 27). A buyer whose online order has been checked sees Pay online on the private order page for what is still owed (never less than 10 or more than 500000 taka); the amount comes from our books, never the browser. The keys, Testing/Live and the message (IPN) address are set in Connections. Nothing counts as paid because a browser came back or a message arrived: we ask SSLCommerz ourselves (validation API) and the transaction number, amount and currency must all match; the same payment is applied once however often it is confirmed (return and IPN both confirm, under a lock). Their Testing gateway is recorded as a practice and never changes an order's money. An order cancelled after payment (or paid after cancel) becomes Refund due, staff are told, and the Online payments screen lists it until the office refunds in the company's panel and presses I refunded it. Return/IPN addresses are exempt from CSRF but throttled; the payment page address must be on the company's own host. Not tested against live SSLCommerz (no keys): answers are simulated from documented shapes. Direct bKash/Nagad and marketplace payment hold (351) are not built. 28 tests. 1747 tests pass | 🟡 |
| Step 11f: automatic location (item 460, part of 461; spec ch 20). With a map service switched on in Connections (OpenStreetMap, free, needs a contact email; or Google Maps with a key), the checkout shows Use my location. The phone gives two numbers after the buyer allows it; our server (so a key never reaches a browser) asks the service what is there and fills the district (matched to our 64, with old spellings such as Comilla, Chittagong, Barisal), the area and the street; the buyer checks, adds the house or flat and the How to get there note, and may change anything; if they refuse or it fails, they type as before. Care taken: only points inside Bangladesh are asked about; answers are kept a day by rounded point so the same place is not asked twice; text from the service is cleaned and cut to 80 characters; a service address that is not public https is never called; failures give short fixed sentences that never repeat what the service wrote or the key; the lookup is throttled; the Connections test button looks up central Dhaka and says whether it was found. The point (6 decimals) is kept with the order and with a saved address, and staff see Open the delivery place on the map on the order. A draggable pin on a map picture, and distance-based charges (435, 436), are not built (461 stays partial). Real OpenStreetMap refuses placeholder contact emails (checked: 403), so the owner must give a real one; Google not tested live (no key). 18 tests. 1765 tests pass | 🟡 |
| Step 11g: cash on delivery per product and online payment for market orders (items 308, 309, part of 351; spec ch 13). Each listing has Buyers can pay when it arrives (default on, so everything already listed is unchanged); the seller turns it off on the product form. The rule lives in one place (placing an order), so the product page, the offers and the cart all follow it: a product without it is an online order, and one such product in a cart makes every order in that cart online (cash on delivery is hidden for the whole cart and the page says why). If online payment is not switched on, such an order is refused at once and nothing is taken off the shelf. An online order waits for its payment: the buyer pays it from the order page or the packages page through the same SSLCommerz checks as shop orders (amount from our books, checked with the gateway, counted once, practice mode never marks it paid); only then is the seller told and able to accept (accepting before is refused). An unpaid online order is cancelled after a day and its stock goes back, without bothering the seller. Cancelling or sending back a paid order makes the money Refund due for the office. The money is held and paid out: a delivered online sale is owed to the seller as what the buyer paid less our commission; the office sees To pay to sellers on the Online payments screen and presses I paid the seller; such sales are left out of the month-end commission statement because the seller never held the money and our commission is already kept; sales that were sent back or not received are not paid out. Each order is paid on its own (not one payment for the whole cart yet), and no hold period or automatic payout is built, so 351 stays open. 23 tests. 1788 tests pass | 🟡 |
| Step 11h: guest orders join the account by a verified phone number (item 15, spec ch 03). A signed-in person opens Settings, Mobile number, types a Bangladesh mobile number (any usual way of writing it) and gets a six-digit code by text message (through the connected SMS service, or the log while none is set up). Typing the code within ten minutes makes the number the account's own (one account for each number) and then every order placed as a guest with that number, in the shop and in the market, that nobody owns yet, is linked to the account and the person is told how many. Care taken, because anyone can type anyone's number into a checkout form: nothing is linked on a typed number alone, only a verified one; only the hash of the code is kept; five wrong tries void the code, a new code voids the old one, one a minute and five a day; a number already on another account is refused before any text is sent and again at the moment of verifying; orders that belong to another account, counter sales, and orders with other numbers are never touched. Tried on the real server (MySQL): code sent, typed back, number verified, page in Bangla. Sign-up itself still asks for email only; the number is added afterwards. 14 tests. 1802 tests pass | ✅ |
| Step 11i: digital products with download limits (item 319, spec ch 13). On their own product the seller presses Add a file to download, picks one file (PDF, e-book, music, video, picture, text, Word, Excel or ZIP, up to 50 MB; scripts, web pages and programs are refused) and sets how many downloads and how many days. That makes it a prepaid product: always paid online, no delivery charge, no shelf to count, no cash on delivery, no address asked of the buyer, one at a time. The buyer pays through the same checked online payment; the moment the payment is confirmed the order is complete (delivered), the download opens on the buyer's private order page and the buyer is told. Each download is counted in one database statement, so two taps at once cannot use the last one; after the last download, or after the days are over, it stops with a plain sentence; the limits are copied onto the order when it is paid, so changing them later changes nothing already bought. Care taken: the file is kept on a private disk under a random name (never in a public folder), sent only as a download with the seller's cleaned-up file name and no content sniffing, only through the buyer's private link, and a missing or removed file does not use up a download. A downloaded product cannot be sent back (a complaint is still possible); the sale is owed to the seller like other online sales. Tried on the real server (MySQL): the seller screen shows the new button. The e-mail copy of the link is not built (orders do not hold an e-mail); the buyer gets an inbox notice. 20 tests. 1822 tests pass | ✅ |
| Step 11j: festival-rush hardening, first part (item 601, spec ch 28). Slow outside calls no longer hold up a buyer's request: text messages are put in a queue (a SendSms job: four tries, waits of 30 seconds, 2 minutes and 10 minutes, 25-second limit; giving up is logged with only the last three digits of the number and never the message) and emails are queued too, so an order is saved first and the messages follow, and a provider that is down or slow cannot stop or slow checkout. A code someone is waiting to type (phone verification) is the exception and is sent at once. Where no worker is kept running (for example a shared host), the scheduler empties the queue every minute; on a server, keep `php artisan queue:work` running under a process supervisor for instant sending. Stock was already taken under database locks and orders, payments and downloads are already counted in single statements. Measured on the dev machine (single PHP dev server, MySQL, nothing cached): home, medicines, market and cart pages answer in about half a second. Not done, so 601 stays partial: a real load test with many buyers at once on production-like hardware, page caching of the busiest public pages, and a cache that is not the database (Redis) for sessions and queue; these are server choices to make before a big festival (also run config, route and view caching and OPcache). 8 tests. 1830 tests pass | 🟡 |
| Step 11k: courier booking and tracking with Pathao (item 492, spec ch 20, second company). A courier on the shop's list can now be tied to Pathao as well as Steadfast; the client id and secret, the merchant's email and password and the Store ID are set in Connections (an address field lets the owner point it at Pathao's practice server). Booking follows Pathao's merchant API as published: a login gives an access token that is kept until shortly before it ends and renewed once if Pathao says it is stale; POST /orders books with the store, our order reference, the buyer's name, 11-digit phone and address, Pathao's own city and zone numbers, normal delivery, a parcel, the weight (from the products' weights, half a kilo at least) and the cash to collect (what the buyer still owes); GET /orders/{id}/info says where it is; GET /stores proves the login and that the Store ID is one of the merchant's stores (the test button). The city and zone are the new part: the order page shows Pathao's lists with our best guess already chosen (the buyer's district, including old spellings such as Chittagong and Comilla, and the area they typed), staff can change either before pressing Book, and if no zone can be told apart the booking is refused with a plain sentence rather than guessed. Everything else is shared with Steadfast: booked once only under a lock, live mode only, cold-chain medicines refused, buyer told when the parcel is sent, progress asked for every thirty minutes, delivered counts as delivered unless the buyer said it did not come, returned/part/stuck parcels go to the office; Pathao's words (Pending, In Transit, Delivered, Partial Delivery, Return, On Hold, Delivery Failed and so on) are turned into the site's own few, and the keys never reach a browser or a log. Not tested against live Pathao (no keys here; their login address answered, a dummy login was not accepted as expected): answers are simulated from the documented shapes. RedX was dropped on request, and Pathao's progress comes by the thirty-minute check rather than by its own messages, so courier booking is complete with Steadfast and Pathao. 18 tests. 1848 tests pass | 🟡 |
| Step 11l: support tickets (item 607, spec ch 29 Phase 2). Anyone writes to the shop from Contact us (a footer link, the order pages' Get help with this order, and the account page's My requests): a topic (order, payment, delivery, product, account, other), name, mobile number, a title and the message; no account is needed. They land on a private page (the long link is the proof of whose it is) showing the thread, the state (Waiting for us, We answered, Closed) and a box to write again; a signed-in person also sees a list of their requests. A ticket can be tied to an order only by that order's private link, so the office sees the order beside the request. The office has Support tickets in the sidebar: tabs with counts for waiting, answered and closed, a search on name, phone or title, a Mine only filter, a Late mark when the buyer has waited more than a day, a thread with the buyer's name, phone and order, Take it / Give it back, Close and Open again, and an answer box; answering moves it to We answered, makes the answerer its owner and tells the buyer (the inbox for an account holder; a text with the link for a guest only if the owner switched text on for this kind of message, because texts cost money). A buyer who writes on a closed request within two weeks reopens it; after that they are asked to open a new one. Care taken: at most three open requests for one phone, five new requests an hour, a limit on length, markup stripped, the office pages and answers are for staff only. Tried on the real server (MySQL): a guest wrote a request, staff answered it, and it moved to We answered under their name. 22 tests. 1870 tests pass | ✅ |
| Step 11m: web-app sync of the cart (item 583, spec ch 27). A signed-in person's cart, in the shop and in the market, is kept under their account instead of in one browser's session, so a product put in on the website is in the cart on the phone and in the installed app, and a change on one shows on the other. A visitor's cart still lives in the session and takes nothing from the shelf. At sign-in (including sign-up and social sign-in) what the visitor had gathered joins the account's cart: the same product is counted once with the larger number, never added twice; the session is left empty. A product that went away drops out quietly, ordering empties the saved cart, one person never sees another's cart, and deleting the account deletes it. (The older abandoned-cart reminder keeps its own small record.) 11 tests. 1893 tests pass | ✅ |
| Step 11n: the customer app as an installable website, with an update message (item 16 part, item 588; spec ch 3 and 27). The site can be installed on a phone's home screen and opens like an app: a manifest made from the shop's own name and a colour the owner sets in Site design (App colour), icons drawn from the shop's logo on that colour (or a plain drop when there is no logo; a separate safe-area version for phones that cut icons round), the colour of the phone's bar, an Install the app button in the footer where the browser offers it and a two-line hint on an iPhone, and a plain no-internet page when the line is down (the office pages are left alone, and the counter keeps its own offline page). A new build is noticed: the service worker file carries the build's fingerprint, installed copies check now and then, and when a newer build is waiting they show A new version is ready with an Update now button; the page is never changed under the person, who decides when to switch. Checked on the real server: manifest, icon, worker and no-line page answer correctly; the browser pane here refuses service workers, so registration and the update banner could not be watched working. Store apps (Google Play, App Store) and the separate supplier app are not built, so 16 stays partial. 12 tests. While doing this I found that the site told browsers never to share the phone's place (a security header), which would have stopped Use my location (Step 11f) from working in a real browser; it now allows the site's own pages to ask and still blocks the camera, microphone and payment request. 1893 tests pass | 🟡 |
| OQ-1, OQ-3, OQ-5 | **Open**, defaults in `config/pricing.php` until Siam decides | §7.4 |

Test suite: 1184 passing (`php artisan test`). The public site is Bangla by default with an EN toggle (`lang/bn.json`, a test fails if any `t()` key lacks Bangla). The translation dictionary is filtered per viewer so a guest never receives the words paikari, wholesale, MPO or craftsman (G1, tested). The home hero is a marketplace shop directory: a search card plus one 3D tile per shop, with animated waves. Known gaps: Laravel validation messages are not yet Bangla, the auth/settings pages were translated in bulk and not reviewed visually, 3 starter-kit tsc errors, ESLint config broken. Production dependency audit: clean for PHP and npm.

---

# PART 3 — FOUNDATION

## 3.1 Apps (ch 03)

| App | Used by | Contains |
|---|---|---|
| **Customer app** | Public, paikari, wholesalers (buying), food/gaming customers | Browse, search, cart, order, tracking, returns, chat, pre-order, memos, wallet, measurement profiles |
| **Supplier app** (**P2**) | Wholesalers (selling), food vendors, MPOs, craftsmen | Product upload, stock, orders, memos, commission accounting, reports, chat |
| **Admin panel** (web; usable on mobile) | Siam, sub-admins, staff | Everything operational; full power on PC |

- **At launch: one app with an internal switch** (customer ↔ supplier). Split into two apps when users grow (Step 6).
- One login works in both. A "Go to Supplier app" button lives in the Customer app.
- Notifications: new orders → Supplier app; updates on things you bought → Customer app. Product links always open the Customer app.
- Everything also works on the **web**, with fewer notifications. Works on Android, iOS, web.
- Role changes what a user sees *inside* the same app. MPOs see only their section. Craftsmen see only their jobs.

## 3.2 Account types (ch 03, 05, 26)

| Type | How created | Phase | Access | Never sees |
|---|---|---|---|---|
| **Super admin (Siam)** | Exists | P1 | Everything | — |
| **Sub-admin** | Siam creates | P1 | Almost everything; **limited on prices and account settings**; approves Help posts; sees sales and profit | — |
| **Staff** (and custom roles) | Siam creates | P1 | Counter sale, drafts, stock entry per role | Sales totals, profit |
| **Paikari buyer** | Siam creates, or they apply | P1 | Paikari + public + other sectors | **Wholesale — not even a hint** |
| **Wholesaler** | Application + licence upload | P1 | Wholesale (own category only) + public + others | Paikari prices/section |
| **MPO** | **Only Siam** (no sign-up) | P1 | Own section only | Other MPOs, other suppliers, real buyers |
| **Public buyer** | Self sign-up or guest | P1 | Public market, food, gaming, help | Paikari, wholesale |
| **Food vendor** | Application | P2 | Own menu and orders | Other vendors |
| **Craftsman** | Siam creates (his own workers) | P2 | Assigned jobs, measurements, photos, chat, deadline, own wages | Buyer price, Siam's profit, buyer phone, other craftsmen's jobs, stock |

**Staff roles (ch 26)** — starting set, but the system **MUST** allow creating new roles and toggling each permission individually:

| Role | Can |
|---|---|
| Super admin | Everything |
| Sub-admin | See above |
| Order manager | Orders, status, fulfillment, messages — not prices or account settings |
| Wholesale manager | Approve applications, tiers, rates, categories |
| Product manager | Add/edit products, update stock |

**Visibility matrix (ch 05):**

| Type ↓ can enter → | Public | Paikari | Wholesale | Others |
|---|---|---|---|---|
| Public | Yes | No | No | Food, Gaming, Help |
| Paikari | Yes | Yes | **No (invisible)** | Yes |
| Wholesaler | Yes | No | Yes (own category) | Yes |
| MPO | No | No | No | Own section only |
| Craftsman | No | No | No | Own jobs only |

## 3.3 Login and account rules (ch 03, 26)

| ID | Requirement | Phase |
|---|---|---|
| ACC-1 | Each account type redirects to its own start page after login (public → public, paikari → paikari, wholesaler → wholesale). Paikari/wholesale users can still reach the public page. | P1 |
| ACC-2 | **One account per phone number**, phone verified at sign-up. | P1 |
| ACC-3 | **2FA** for admin, staff, wholesale and paikari. | P1 |
| ACC-4 | Social login (Google, Facebook) — **public accounts only**. | P1 |
| ACC-5 | **Guest checkout**: name + phone + address mandatory, no password. | P1 |
| ACC-6 | Guest orders are **auto-linked** if the buyer later signs up with the same phone. | P1 |
| ACC-7 | Forgot password → OTP code to phone. | P1 |
| ACC-8 | Closing an account hides personal data but **keeps invoices and transactions forever** (accounting/legal). Same for staff who leave — their memos stay. | P1 |
| ACC-9 | One person may hold **two business accounts** (e.g. wood + clothing). Each is its own seller entity linked to one login; stats never mix. Supplier dashboard has a business switcher + optional "all businesses" summary. | P2 |
| ACC-10 | A separate account for **every** staff member is mandatory; limit simultaneous devices for staff and sellers. | P1 |
| ACC-11 | Force-update very old app versions; show an urgent update message. | P1 |

## 3.4 Visibility and seller identity (ch 05)

| ID | Requirement |
|---|---|
| VIS-1 | **Server-side check on every API request**: who is asking, are they allowed. Hiding menu items is not security. |
| VIS-2 | **Ownership check** on every order, memo, chat: changing an ID in a URL must never show someone else's data. |
| VIS-3 | Paikari users see **zero trace** of wholesale: no lock icon, greyed menu, "access denied", hint. It appears only if Siam upgrades them to wholesaler. |
| VIS-4 | **Deep links** to something the viewer cannot access → silently redirect to the public homepage. No error, no login prompt. |
| VIS-5 | **Category-based wholesale access**: a medicine wholesaler sees only medicine, a wood wholesaler only wood; neither knows the other category exists. Both businesses → both categories granted. |
| VIS-6 | **Seller identity:** every product shows Siam's platform name by default. Per-seller **name display toggle**; only sellers with it ON get a **shop page** and the **pickup-point** option. |
| VIS-7 | Craftsmen: see measurements, job description, photos, chat, deadline, own wages. Never: buyer price, profit, buyer phone, other craftsmen's jobs. |
| VIS-8 | MPOs: isolated from each other; identity visible only to Siam (staff by role). |
| VIS-9 | Sub-admin/staff data access limited by role; **log who viewed whose data and when**. |
| VIS-10 | Community product cards (Help sector) obey visibility rules; if the viewer cannot see the product the card does not render at all. |

## 3.5 Pricing engine (ch 04) — build in Step 0

**Retail/public price is the same for everyone.** Per-person pricing exists **only for paikari and wholesale** (tiered users). Public buyers get **quantity discounts** (steps shown on the product page).

### The four layers (highest wins; lower layers are ignored when a higher one exists)

| Priority | Layer | Meaning |
|---|---|---|
| **1** | **Customer-specific manual rate** | Set by hand for one customer on one product |
| **2** | **Product-level override** | Different % (or taka amount) on this product only |
| **3** | **Company-level rate** | One % covering all products of a company |
| **4** | **Tier default** | Discount of the customer's tier (A 10%, B 15%, C 20%…) |

(The chapter calls the tier default "Layer 1" and the manual rate "Layer 4" — same layers, numbered bottom-up. **Use layer names in code, not numbers.**)

| ID | Requirement | Phase |
|---|---|---|
| PRC-1 | Unlimited tiers; Siam names them and sets rates, changes any time. A tier-rate change updates all prices in that tier. | P1 |
| PRC-2 | **Manual rates are NEVER touched when a tier changes or a tier offer runs** (a manual agreement must not silently vanish). | P1 |
| PRC-3 | Siam can move any customer up/down a tier; behaviour follows the new tier. **Tier change log**: who, whose tier, when, old → new. | P1 |
| PRC-4 | Percentage **and** taka entry both supported; taka entry is auto-converted and shown as % too. Customer has a % ↔ taka display toggle. **Define rounding rules first** (ch 04 dev note). | P1 |
| PRC-5 | Public quantity-discount steps: unlimited steps per product, % or fixed taka, none if no steps. Screen shows "You are getting 10% off". | P1 |
| PRC-6 | **Discount order:** (1) base price (tier/offer/manual) → (2) quantity discount → (3) token → (4) return credit. Two discounts are never *added* together — whichever is larger applies. *(See open question OQ-1: ch 24 reverses steps 3–4.)* | P1 |
| PRC-7 | **Invoices lock the price/rate of that day.** | P1 |
| PRC-8 | Price sanity checks: warn on an abnormally low price ("90% lower than before"); negative not allowed; zero warns. | P1 |
| PRC-9 | Items in a cart whose price changed update and show "Price changed". | P1 |
| PRC-10 | Offer + token don't combine (see PRC-6). | P2 |

## 3.6 Admin panel (ch 25)

Everything on **one page per customer** (tier, manual rates, flags, notes, payment methods, free-delivery threshold, location, category access, commission rate, name/sales display toggles, stock module on/off, order/return/message history, suspension, account-type upgrade).

| Area | Contains |
|---|---|
| **Product management** | Add/edit/hide/feature; where it shows (public/wholesale/paikari with different prices); category, MOQ, quantity steps, COD toggle, advance delivery charge, weight, stock display mode, offer tag/min boxes, photos/video/variants/SEO, commission per product |
| **Application review** | Wholesale, paikari, food-vendor applications; trade licence and **drug licence** verification; approve/reject with reason |
| **Order management** | All orders by sector; status/payment; create memos, edit prices; finalise pre-order memos; paikari fulfillment; place orders on behalf of a buyer; approve returns |
| **Sector management** | Create sectors (§5.3); central lists (medicines, companies, food ingredients, other products, couriers); pending "add to list" requests; "I was looking for this" requests (grouped with counts); holiday/closed days |
| **MPO / craftsman / food** | Accounts, territories, companies, product selection, bonus approval, bidding; job assignment, wages; restaurant approval, commission |
| **Reports** | Per-sector sales, profit, best sellers, month/year comparison, customer ranking, return analysis, staff speed, monthly ranking (§4.3.9), **CSV/Excel export**. Big reports generated overnight; corrected entries marked |
| **Nightly summary** | WhatsApp/SMS at a set time: sales, profit, expected drawer cash, items finished, low stock, open drafts, pending approvals, returns. Optional for sub-admins |
| **VAT/Mushak** (report P2; **fields P1**) | Rate per category, VAT amount per invoice line in DB from Day 1; monthly summary exportable |
| **Activity log** | Tier changes, price changes, suspensions, deletions, MPO company changes, commission changes, return approvals, stock corrections, discounts above limit and who approved — each with who/what/when |

Other admin rules: **30-day deleted bin** (restore); notifications sorted by importance; time-limited **temporary extra powers** for a sub-admin (P2); day-by-day breakdown to find month-end mismatches; holiday/closed-days setting; data export.

## 3.7 Staff and security (ch 26)

| ID | Requirement |
|---|---|
| SEC-1 | Staff see **no sales totals or profit**, but **do** see loss warnings. |
| SEC-2 | IP block; temporary lock after repeated wrong passwords; suspicious-login records; device limit per account. |
| SEC-3 | Account suspension: permanent **or time-limited** (e.g. 2–3 days, auto-reactivates), with a reason field. |
| SEC-4 | Big admin actions (prices, stock, deleting accounts) need separate confirmation, plus 2FA. |
| SEC-5 | Account type verified on the server for every request. |
| SEC-6 | **Backup:** automatic nightly, ≥30 days of copies, stored **off the server**, **restore-tested monthly**. |
| SEC-7 | **System time from the server only (Bangladesh time), never editable** — expiry, return windows and offer months depend on it. |

---

# PART 4 — PHARMACY

## 4.1 Medicine Database (ch 11) — Step 0-A

A MedEx-style database of **every medicine on the Bangladeshi market**. It is not just information — generic suggestions, MPO product selection and stock entry all depend on it.

| Field / rule | Detail |
|---|---|
| Per medicine | Brand, generic, company, strength, form (tablet/syrup/injection), MRP, uses, alternatives with same generic, unit structure (strips per box, pieces per strip) |
| Flags | **Prescription required**, **Cold chain** ("Keep refrigerated"), **Controlled**, **Inactive** (discontinued: hidden from new orders, kept in history) |
| Barcodes | One or more per medicine (§4.3.8) |
| Search | Misspelling-tolerant and Bengali ↔ English ("নাপা" finds "Napa"); alternative names linked ("tablet"/"bori"); in-stock first, most-sold first |
| **No free typing** | Nobody types a medicine name — pick from the DB (search, or open a company and pick). Company list is preset; only Siam adds companies. This guarantees no duplicates. |
| Generic alternatives | Searching a brand shows offer/discounted products of the **same generic** below it |
| Cold-chain | Flag shown on product page, picking list and memo; **blocked from normal courier** (own delivery / counter pickup only, "pack with ice"); checkout warns and offers pickup |
| Controlled | Triggers the sale register (§6.7) |
| Unit changes | Warn "this will change old calculations"; log who/when |

### 4.1.1 Existing data and how it is loaded (implemented)

The medicine data **already exists** — it is not a data-collection job any more.

| Item | Detail |
|---|---|
| Source DB | MySQL database `pharmacy` (old PharmaTrack-style app, scraped from MedEx): **25,933 medicines, 291 companies** |
| Source images | `public/medicine_images/` — 16,010 `.webp` files named `med_<n>_<name-slug>.webp`. `<n>` is **not** the medicine id. |
| Company logos | `public/company_logos/` — 84 `.webp` files named `company_<n>_<company-name-slug>.webp`, matched to companies **by name** (all 84 matched, none ambiguous). Result: **84 local logos, 4 remote-only, 203 companies with no logo** (`Company::logo` returns local, else remote). |
| Target tables | `companies`, `medicines` (migrations `2026_10_10_00000{1,2}_*`; models `Company`, `Medicine`) |
| Importer | `php artisan medicines:import` (`--dry-run` to preview). Reads the `legacy` DB connection (`LEGACY_DB_DATABASE`, default `pharmacy`). **Idempotent** on `legacy_id`. |
| Fields carried over | brand, generic (25,197 have one), strength, form, volume, box/strip/piece structure, MRP per piece/strip/box (taka), company, active flag, source URL |
| Image linking | Within one brand name, image files in `<n>` order = medicines in id order. Linked when the file count equals the count of medicines that had a source photo (or all medicines of that name). **14,684 linked locally; 885 keep their remote source URL only; 1,326 image files were skipped** because counts disagreed (guessing would attach wrong photos). `Medicine::image_url` returns the local file, else the remote URL. |
| **Not in the source** | Prescription-required, cold-chain, controlled flags (all default `false` — Siam must flag these), alternative names, barcodes, uses/description |
| Not imported | The old app's orders, shops, users, short lists, rankings, categories (MPO groupings) — those belong to later steps and use the new schema |

**Caveats to raise with Siam:** images carry a "MedEx" watermark and come from medex.com.bd (check reuse rights before showing them publicly); 93 groups have duplicate brand+strength+form+company rows; MRPs are as scraped and should be verified before launch.

---

## 4.2 The five "drafts" — use these code names (ch 06.0-A)

Plus the **sale state** (ch 06.0). **Store them separately in the DB.**

| Code name | What it is | Mechanism | Ends when |
|---|---|---|---|
| `pending_delivery` → `complete_sale` | **Sale state:** goods not yet received vs received | Status field on sales | Buyer receives (delivery OTP / photo / "Received" tap / counter collection) |
| `open_invoice` | Counter bill kept open while goods are added; stock deducted as items go in | Separate table: items, who opened, when | Money taken and invoice finalised |
| `incomplete_stock_entry` | Purchase entry paused halfway. **Adds nothing to stock.** | Holds typed data only | Whole invoice saved |
| `charge_pending_memo` | Memo held open so an extra delivery charge can be added. No goods. | Charge field only | Parcel handed to courier |
| `preorder_draft_memo` | Pre-order memo editable until real receipt is entered; buyer sees it live | Has "Receive" button | Siam presses "Receive" |

**Both can run at once:** a paikari order opens an `open_invoice`; when the memo is finalised that ends, but the sale stays `pending_delivery` until the buyer receives → `complete_sale`.

**What the sale state controls:**

| Where | Effect |
|---|---|
| Reports | Count **only complete sales** |
| Commission | Calculated **only on complete sales** |
| Return window | Starts from the **day of receipt**, not the order date |
| Stock | Goods on a pending sale are held; if never received they return to stock |

## 4.3 Stock System (ch 06) — Step 1, **Siam's pharmacy only**

### 4.3.1 Sources and purchase price

| ID | Requirement |
|---|---|
| STK-1 | Unlimited **sources** (company, MPO, outside shop), each with a default % = how far below MRP goods were bought. |
| STK-2 | **Three-layer purchase rate** (each overrides the one before): source default → company rate (all that company's products) → product rate (typed per medicine). |
| STK-3 | **Cascading discounts, never added:** company % first, then MPO % *of the result*. (MRP 100 → −12% = 88 → −10% = 79.2; total 20.8%, not 22%.) MPO % is typed by hand each time. System displays the final total purchase %. |
| STK-4 | **Bonus ("10+2") option is manual**, turned on only when there is a bonus. System computes true per-box cost: paid 792 ÷ 12 received = 66 each → bought at 34% of MRP 100. Show the %, not "10+2". |
| STK-5 | A medicine is set up once; later arrivals are only a stock entry (source + % + quantity). |
| STK-6 | **Purchase price is locked in taka the moment it is saved; it never changes.** (Corrections by admin only, e.g. a wrong offer price → system flags "this offer is now at a loss" and recalculates sold profit.) |
| STK-7 | When MRP changes, all stock sells at the **new** MRP (no two MRPs). Profit differs per lot. |
| STK-8 | Entry will **not save** without a purchase %; source default auto-fills. Set the % once at the top for a whole company list, override per line. |
| STK-9 | Only DB medicines; optional shelf number; warn if the same invoice is entered twice; two invoices on one day = two lots with invoice number/time. |

### 4.3.2 Lots

- Each purchase = its own **lot** with its own taka price per box. UI shows one total; lots tracked internally. Warn on very old, very small lots.
- **Stock is not FIFO** — it is kept by percentage group. When selling, the system **picks a lot where the sale still leaves profit**. Example: 10 boxes at 14% + 20 at 20%. Sold at 12% → from the 14% lot; sold at 25% → from the 20% lot.
- Profit uses the purchase price of the **lot the goods came from**, not the price at confirmation time.

### 4.3.3 Profit/loss protection (ch 06.2)

| ID | Requirement |
|---|---|
| LOS-1 | **Block a loss-making rate at the moment it is set.** Checked when setting: regular selling rate, offer rate, manual customer rate, invoice %, retail end discount. (Customers never face "in stock but can't sell".) |
| LOS-2 | Loss inside an invoice: don't block the whole discount. Flag only the loss-making lines and ask for a lower % on them; others keep the invoice %. |
| LOS-3 | **Staff can never sell at a loss.** Attempt → instant notification to Siam → **one-tap yes/no**. No answer within a set time → **auto-cancel**, staff sell at the normal rate; nothing stays hanging. |
| LOS-4 | **Staff discount limit** per role (and per staff): e.g. max 5% on retail end discount or invoice. Above limit → same one-tap approval flow. Every above-limit discount and its approver is logged. |
| LOS-5 | **Admin can force a loss sale** (e.g. clearing near-expiry). Such sales are **flagged as losses in reports**. |
| LOS-6 | Works offline using last-synced data (CTR-5, §4.5). |

### 4.3.4 When stock is deducted + Master Stock Sync (ch 06.5)

- **Moment of deduction:** when an item is **added to the open draft** (after search + quantity + rate). Not at search, not at memo confirmation. Removing/cancelling returns it instantly; history is kept.
- **Core rule:** whatever is in the system is deducted when sold; what is not in the system has nothing to deduct.

| Sale | Master Stock Sync **OFF (launch)** | **ON (later)** |
|---|---|---|
| Offer product order | Deducted | Deducted |
| Normal paikari | Not deducted | **Deducted** |
| Retail | Not deducted | **Deducted** |
| Staff verification | Staff go to the store room and **type** the number | Number **shown on screen**, click "Available" |
| Loss protection | Offers only | Every sale |
| Expiry management | Only goods entered in stock | Whole pharmacy |
| Monthly ranking / cash reconciliation | Incomplete / limited | Complete / whole day |

**Master Stock Sync rules:** one **global** switch (not per product); **write the deduction code for retail and paikari now**, wrapped in this switch; **keep it OFF at launch**; show a warning before turning on ("Make sure all goods are entered first"); allow turning it off again. Siam turns it on the day the large pharmacy's goods are entered. It is *not* one of the ordinary toggles (name display, sales display, COD, sold-out-today, chat, %/taka) — do not confuse them.

### 4.3.5 Draft invoice (`open_invoice`) behaviour (ch 06.6)

| ID | Requirement |
|---|---|
| DRF-1 | Invoice stays open all day while goods are added; multiple open drafts, each with its **own number** (Invoice 1, 2, 3; retail: "Customer 1…" or a name). Unlimited retail drafts. |
| DRF-2 | Drafts can be merged; one memo at the end shows which items came from which draft. A shop with online + counter drafts shows "This shop has 2 drafts open" — mergeable. |
| DRF-3 | Rate = rate **on the day goods were added** (offer-day rate kept even past month-end). |
| DRF-4 | Shows who opened it and who is working on it. Anyone may enter; **first click wins**, both screens update live. |
| DRF-5 | Timer per draft ("open 45 minutes"); colour changes after 1 hour; corner count "7 drafts open". |
| DRF-6 | Draft idle 1–2 days → notification with **Return to stock / Keep** (asks again after 2 days). |
| DRF-7 | "Move to another draft" in item's menu: rate changes to the new customer's rate, **stock does not move**. |
| DRF-8 | Warn if the same medicine is added twice (merge or separate line). |
| DRF-9 | **Every item saved instantly to the server** (never batched) so internet loss loses nothing. |
| DRF-10 | **"Bring from outside"** mark: price on invoice, stock not deducted, feeds the "asked for but not available" report. |
| DRF-11 | **End of day** button lists unresolved items by staff name (open drafts, unpaid money, stock requests). |
| DRF-12 | After finalising: **invoice correction** (admin only) — old memo cancelled, new number, both kept; **change customer** (admin) — new rate applied, both histories keep a record. |

### 4.3.6 Stock correction and stock count (ch 06.7)

| ID | Requirement |
|---|---|
| COR-1 | **Only admin** corrects stock numbers (otherwise staff could hide theft). A correction is an entry-error fix — **not counted as a loss**. Record who/what/when and a reason (broken, lost, stolen, entry mistake); month end shows money lost. |
| COR-2 | Staff have **"Report mismatch"** ("found 50, system says 60") → notification to Siam. If two reports conflict on one medicine, warn Siam. |
| CNT-1 | **Stock count mode:** admin starts a session (whole shop / company / shelf; pausable) → staff enter counts or scan barcodes → system shows system vs counted and taka value of difference → **only admin approves** adjustments → saved count report (date, who, total difference). |
| CNT-2 | Sales may continue during a count; items sold after counting started are adjusted for. Approved differences are recorded as count adjustments with a reason, separate from sales. |

### 4.3.7 Expiry management (ch 06.3–6.4)

The real risk is only **stationary** stock; regularly moving goods need no expiry entry.

| Detection | Rule (configurable) |
|---|---|
| **Stagnant** | Nothing bought and nothing sold for 6+ months (6 mo / 1 yr setting) |
| **Months of stock** | Stock ÷ monthly sales > 12 months (e.g. 15 boxes at 1/month = 15 months) |
| Display | Money at risk at top: "Goods worth 42,000 taka need checking" |
| Outcome of a check | **Expired** → expired section, removed from stock. **Checked** → stays; optional expiry date entry; if a date is given the system reminds at the right time, not by the 6-month rule |
| **Near-expiry** (e.g. 3 months left, configurable; only items with a date) | System suggests the **lowest safe offer rate**; one tap to the offer flow (Siam still approves). If even that won't sell it in time → suggest returning to the company before its return deadline |
| **Expired section** | Sorted by company; per-company boxes/money; select-all; mark **"Returned to company representative"**; **PDF** generated. Three states per batch: **returned / not taken back / pending**. "Not taken back" = counted as a loss; annual waste visible |

The "company representative" collecting expired goods is **not** the e-commerce MPO.

### 4.3.8 Barcode scanning (ch 06.10)

Scanning puts an item on the **draft** (counter), **purchase invoice** (stock entry) or **stock count**. USB/Bluetooth scanners and phone camera. Multiple barcodes per medicine. Unknown barcode → ask which medicine (search DB) → link once, remembered forever. Scanning the same item again **increments quantity** (no duplicate line).

### 4.3.9 Reports (ch 06.9)

- **Stock:** total stock value; per-medicine movement; low-stock alert; "what finished today" (morning ordering).
- **Two-way reorder suggestion:** buy more if it keeps running out; buy less if it's sitting.
- **Monthly ranking (A–Z):** demand ranking, boxes needed per day, ideal stock level, boxes to order, which company moves most, customer ranking, which customer buys which company, profit-per-medicine ranking, fast/slow lists, **seasonal forecast** (last year's pattern), month-to-month and same-month-last-year comparison.
- **Business direction:** profit, average sale, up/down trend.
- **Shop expenses → net profit** (P1; admin/sub-admin only): categories (rent, salaries, electricity, internet, transport, packaging, other, custom), each entry with date/amount/category/note, **recurring expenses repeat monthly**. *Gross profit − expenses = net profit*; monthly/yearly trend.
- **Per customer:** total sales, Siam's profit from them, companies they buy most, returns, **inactive customers**.
- **End-of-day cash reconciliation:** system says "Today: X"; Siam counts the drawer and enters it; mismatch caught the same day (admin/sub-admin only). Credit sales stay in a paper ledger for now.
- **Staff name on every memo** (accountability).
- **"Asked for but not available":** one click; monthly report shows repeatedly requested, never-stocked medicines.
- **Monthly report date is configurable** (not hardcoded to the 1st).

### 4.3.10 Speed and error reduction (ch 06.10)

Show **strength (bold) and company** next to every name; warn on similar names; in-stock first, most-sold first; keyboard flow (search → Enter → quantity → Enter → next); top-20 daily medicines in one click; a **simple staff screen** (search, quantity, save — everything else hidden).

### 4.3.11 Wholesalers' simplified stock (ch 06.12)

Third-party wholesalers get only: what they add stays; decreases as they sell; low-stock alert; their own stock view (Siam can see it, they can't see each other's; who gets the module is Siam's manual decision). They get **no** source %, bonus/cascade, profit/loss protection, expiry, expired section, offer-to-tier, counter, drafts or reconciliation. Other sectors have no purchase price/profit/expiry at all (§5.3).

## 4.4 Paikari sector (ch 07) — Step 2

**Siam's own business only.** No wholesaler/seller can list here. Mainly **admin-driven**: many buyers order by phone and Siam enters it at their rates. Paikari buyers can be upgraded to wholesaler later based on sales.

### 4.4.1 Hard rules

| ID | Rule |
|---|---|
| PAI-1 | **Never show stock to paikari buyers** — not "in stock", not "out", not a number. Permanent (reasons: not all goods are in the system; buyers who see low stock order less, and unavailable-item demand data would be lost). Exception: offer products may show stock, usually only "In stock" (§4.6). |
| PAI-2 | No wholesaler or seller listings in paikari. |
| PAI-3 | Prescriptions go **only to Siam, sub-admins, staff**. Show unseen-prescription count; time-based reminder. |

### 4.4.2 Fulfillment flow (10 steps)

| # | Step | Details |
|---|---|---|
| 1 | **Buyer orders** | Search medicine, enter pieces/strips/boxes. Order reaches Siam and staff instantly. Siam can place the order **on the buyer's behalf** at the buyer's rates. Counter has a **quick-note** field for phone orders. |
| 2 | **Initial MRP memo** | One button → preliminary PDF at MRP, replaced later. **Offer items skip it** (directly at offer rate). |
| 3 | **Order becomes an `open_invoice`; staff verify** | Per medicine: **Fully available** (enter qty; after Master Sync just click) / **Partially** (type how many) / **Not available**. Stock deducted as items are placed. **Offer items** marked with colour and/or "OFFER" label; no quantity typing. Several staff may work one order — first click wins, live updates. Staff screen shows who is working. |
| 4 | **Buyer sees result instantly** | Got / partial / not available list, real time, so they can look elsewhere right away. |
| 5 | **"Not available" → PharmaTrack short list** | Builds the demand list automatically. |
| 6 | **Real-time status** | Delivery today or not → Picking → Packed → Sent for delivery. |
| 7 | **Final memo** | Priced at the buyer's own price (not MRP); **Siam can edit each line's price first** (purchase prices vary per lot). Layout: **offer section first → regular section → one grand total**, each with subtotal. Replaces the MRP memo. |
| 8 | **Buyer adds more** | Even after the final memo; repeats from step 3. |
| 9 | **Collection or delivery** | Three options: come and collect / send someone / need delivery. **Delivery charge typed by hand** (varies each time). **Free-delivery threshold per buyer** (e.g. 6,000): their screen shows "4,000 more for free delivery"; warn if Siam types a charge past the threshold. |
| 10 | **Payment** | Bank, bKash, COD, advance; "Paid / Due" on the invoice. |

### 4.4.3 Retention and behaviour features

| Feature | Rule |
|---|---|
| **Short list** | Free list-building (no order needed), shows **their own prices**, offer rates and MPO products; one tap to order. Keeps buyers returning daily. |
| **Frequency suggestions** | Most-ordered items suggested inside the normal flow (no separate re-order button). |
| **Habit reminders** | System learns each shop's usual ordering time and notifies at exactly that time, per shop; can be turned off. |
| **Night orders** | Message encourages night ordering (packed overnight). **No cut-off; orders accepted 24/7.** |
| **Holiday orders** | Always accepted; on closed days: "Your order is received — will be processed on the next working day." Uses admin closed-days setting. |
| **Monthly total** | Month end only (not mid-month), online + counter combined, **toggle per customer**. Shop owner cannot see it otherwise. |
| **Tier upgrade task** | At month end: "Buy X more to reach next tier". Completing it **goes to Siam for manual review and upgrade** (not automatic); congratulations message on upgrade. **No live progress** (prevents artificial bulk buying). |
| **Problem-buyer flag** | Repeated orders never collected → auto-counted/flagged; threshold in settings; Siam decides suspension. Uncollected packed goods: whole thing refunded, stock returns. |

### 4.4.4 Cancellation

| When | What happens |
|---|---|
| Before staff start picking | Buyer cancels themselves |
| After picking starts | Cancellation **request** appears on staff screen ("This shop wants to cancel") |
| After purchase complete | Goes to Returns (§4.7) |

### 4.4.5 All Companies sector (ch 29-A)

A browsing convenience, not a separate system: list of every Bangladeshi pharma company → tap a company → see all its products (even those not in stock) → order. Orders go through the **normal staff verification flow**. Offer products must be ordered in **boxes only** with a stated minimum. **Brand/company filter** available platform-wide and in search results.

## 4.5 Counter Sale (ch 08) — Step 2, **Phase 1 because most sales happen here**

Without it, stock only goes up and every calculation (expiry, profit, stagnancy) is wrong.

| Mode | How |
|---|---|
| **Retail** | No customer. All at MRP. **One end discount** (5/8/10%) at the bottom, shown on memo. Items with their own special discount are **excluded** from the end discount (never discounted twice). Retail drafts = "Customer 1, 2…" |
| **Paikari** | Select customer first (search shows area + phone; duplicates get a code); everything at their rate; pieces/strips/boxes; sale auto-added to their e-commerce profile |
| **Wholesale** | Same, at wholesale rate |

**Counter rate priority:** (1) per-medicine % → (2) invoice-top % → (3) customer's tier rate. *(See OQ-3 for how this meets the 4-layer engine.)*

| ID | Requirement |
|---|---|
| CTR-1 | Counter and e-commerce are synced: same rates online and at the counter; counter sales appear in the customer's e-commerce profile. The shop owner cannot see their own business totals except via the month-end toggle. |
| CTR-2 | **Quick profile** at the counter (name, phone, area; default tier). Searching a customer shows "This shop has an active order". |
| CTR-3 | **Offers at the counter** run only in paikari and wholesale, **never retail**. Search shows normal on top, offer below. **Never auto-applied — staff must click it.** Min box count not met → offer blocked, customer's profile rate applies (profit then calculated at the normal rate). Siam can see **all** offers at the counter and give one to anyone regardless of online visibility. An offer given at the counter is recorded in the customer's offer section exactly like an online order. Bonus works the same (clicking the offer applies it). |
| CTR-4 | Mixed invoice: offer items at offer rate (stock deducted) + normal items at customer rate (stock not deducted while Sync is off). Layout: offer section → regular section → one grand total with subtotals. |
| CTR-5 | **Offline counter (P1):** works fully without internet — search, scan, drafts, finalise, print. Temporary memo numbers replaced on sync; screen shows "Offline — will sync" + pending count. Local copy of medicine list, customer rates and stock snapshot. Loss protection and staff discount limits still work from last-synced data. On sync, oversold stock raises "Stock went below zero for these items" — Siam decides. Offline sales are never lost. |
| CTR-6 | Barcode scanning at the counter (§4.3.8). Keyboard shortcuts, quick list, simple staff screen, similar-name warnings. |

## 4.6 Offers (ch 09) — Step 3

| | **Offer Product** | **Tier Offer** |
|---|---|---|
| What | A specific product on offer | A tier's rate temporarily changed |
| Minimum boxes | **Required** | Not required |
| Unit | **Boxes only** | Pieces/strips/boxes |
| Who sees it | Whoever Siam chooses per offer (public/paikari/wholesale, different price each) | All paikari buyers in that tier |
| Stock | Deducted from the **same** stock | n/a |

### Offer Product rules

| ID | Requirement |
|---|---|
| OFF-1 | Not a separate stock or business: same stock with an "offer" tag. |
| OFF-2 | **Only the lot bought at the highest discount goes on offer** (14% + 25% lots → only the 25% lot). |
| OFF-3 | **Nothing goes on offer automatically.** Ways in: by hand, or by notification (Siam sets a threshold, e.g. 20%; when above-threshold goods arrive the system asks; goes only if approved). |
| OFF-4 | Siam sets a minimum profit margin (e.g. 3%); system suggests a selling rate from it. |
| OFF-5 | **Cannot order more than offer stock**, enforced in the background even when only "In stock" is shown. Stock display mode: exact number or just "In stock" (usually "In stock"). |
| OFF-6 | Quantity discounts above the minimum (e.g. min 5; extra 1% at 6…). |
| OFF-7 | **Search pop-up:** normal result on top, offer below (also by generic); order directly. Also shown in an Offers section tagged "Offer". |
| OFF-8 | **Alert before offer stock runs out** (threshold in settings). New stock within the same month rejoins the offer automatically. |
| OFF-9 | **When offer stock runs out:** listing stays **greyed for 1–1.5 hours** ("Was on offer a moment ago — stock out"), cannot be carted/ordered. Purpose: teach paikari buyers to act quickly on the next offer. Then auto-removed. Offer ending ≠ medicine ending. |
| OFF-10 | Weight field optional; **every product always has a minimum delivery charge** (e.g. 150/200/300); weight-based only for heavy goods. |
| OFF-11 | No MRP memo for offers. |
| OFF-12 | A return that switches an offer back on **notifies Siam** (2 boxes may not be worth an offer). Month ends with an offer in a cart → price reverts, "This offer has ended"; if already on a draft, old rate stays. |
| OFF-13 | Corrected purchase price → system shows "This offer is now at a loss"; sold profit recalculated. |

### Tier Offer rules

- Company/bonus offers go into a **separate list** showing the effective purchase % (not "10+2"). Siam sets the sell %, clicks **"Upload to paikari tier"** and all paikari buyers get it instantly.
- **Tied to the month**: a duration field; at month end it auto-reverts, then the system asks whether to keep it; stops immediately when stock runs out; new stock within the month returns to the offer rate.
- **Customers with a manual rate are not affected** (their manual rate wins).

## 4.7 Returns (ch 12) — Step 3

### 4.7.1 Core rule

> **Medicine is generally not returnable** — not on public, not on wholesale. **Only paikari buyers** get a return option, under strict conditions. *(See OQ-2 for the public-return flow in the same chapter.)*

| Buyer | Policy |
|---|---|
| **Paikari** | Bring the memo; within **2–3 days of receipt**; **exchange only** (full-value credit); cash = **50%** ("Taking cash back: 50% applies, because we only offer exchange"). Sealed-or-not = staff judgment. Section lists all purchases; those within the window show "Returnable"; older have no option; search by date. Rules are printed on the memo. |
| **Public (online)** | Must be logged in (guests open an account). **Apply → Siam approves → Siam calls.** Note/reason **or voice note mandatory**. |
| **Retail (walk-in)** | No profile/account (300–400 customers a day). Refunds handled **only in PharmaTrack**, manually. Find the sale by **memo number, date, or medicine name**. Rules printed on retail memos; **day count set in settings**. Optional "will receive money for returned goods" field. |

### 4.7.2 Rules

| ID | Requirement |
|---|---|
| RET-1 | **Partial returns = part of a box/unit** (e.g. 30 of 50 pieces at 10 taka = 300), priced **at the price they paid**. |
| RET-2 | Approval by Siam (paikari at the shop: he checks first; outsiders: he contacts them). Approved → account shows **"Credit: X taka"**, auto-deducted on the next memo, labelled "Return product"; warning if unused after 1–2 months. |
| RET-3 | Return credit works **only in the sector where it was returned**. |
| RET-4 | Returned goods go **back into the same lot at the original price**; a closed lot reopens with just the returned goods. |
| RET-5 | **Offer returns:** credit; exchange; cash = **50% of the offer rate** (offer 300 → 150). |
| RET-6 | **Money when goods aren't taken:** delivery charge, sent and came back → **whole charge forfeited**; cancelled before sending → **50% refunded**; product advance, goods not taken → **50% refunded**. |
| RET-7 | "Return without invoice" (admin enters price by hand, separately marked). Reprinted memos say **"Reprint"**; second print can't enable a second return. "Previously returned" mark on re-returned goods. |
| RET-8 | Memos mixing medicine and other products state both return rules. Other sectors use the **same return screens**, but rules/days/refund are set by the **seller** (custom orders: no returns). |
| RET-9 | Analysis: each customer's returns + top reasons; separate report of most-returned products and why. Repeated same-reason mistakes → auto-warning to staff or shop. |

## 4.8 MPO sector (ch 10) — Step 4

**Concept:** MPOs (pharma reps under sales targets) release allotted products via a **secret channel**. Siam is the **middleman**; goods pass through his hands (MPO never ships to buyers) and enter his stock with source "Through MPO". **Both sides wear masks.**

| Who | Sees |
|---|---|
| Buyer | Buys from Siam's account; MPO invisible. Product name format: **"Anonymous 1 (store name)"** / "Unknown 1 (store name)" (English; numbered per MPO) |
| MPO | Order placed by Siam's account; real buyer invisible |
| Siam | Everything |

| ID | Requirement |
|---|---|
| MPO-1 | **Only Siam creates MPO accounts** (no sign-up). Profile: name, photo, phone, **companies (one-to-many)**, territory, selected products (**one-to-many**), created date, submitted/sold counts, notes, **failed-delivery count**. |
| MPO-2 | **Territory** set at creation; MPOs **auto-group by territory** (updates on change); reports by territory. |
| MPO-3 | MPO sees their company's full medicine list and **chooses which products they work with** (search, multi-select, editable; Siam can edit). May get more companies (added/removed only by Siam). |
| MPO-4 | MPOs are **isolated from each other**. |
| MPO-5 | MPO can: submit selected products, report stock status, give days-until-arrival, offer a **bonus (10+2, any ratio)**, edit own product list, see and accept bids, enable pre-order for target products. MPO **cannot**: set prices, decide who sees it, see other MPOs/real buyer, add a company, change own territory. |
| MPO-6 | Stock status: In stock / Pre-order (days). Exact number or "In stock". **No expiry date** needed from the MPO (Siam's stock system handles it once goods arrive). |
| MPO-7 | **Bonus:** default "No bonus"; if on, ratio entered; shown on memo as a separate line (value 0) and total received; bonus shows from **Siam's** account; no price but deducted from stock. |
| MPO-8 | **Siam reviews every submission** before market: approve; which markets (wholesale/paikari/public); price in each; stock display; bonus display; in stock vs pre-order + days. |
| MPO-9 | **Blind bidding (reversed from common assumption):** **wholesalers** propose a % on an MPO product. The **MPO** sees anonymous bids ("Anonymous 1: 10%") and **accepts one** — the final decision is the MPO's, not Siam's. Wholesalers never see each other's bids. Siam sees everything with identities. Order confirmed for that wholesaler; both sides see Siam's account. |
| MPO-10 | No accepted bid → notify MPO "Would you like to increase the % yourself?". Accepted but not delivered in time → order cancelled, wholesaler told, MPO's failure counter +1. |
| MPO-11 | Closing an MPO account removes products; ongoing orders must finish. Two MPOs, same medicine and price → both stay; Siam's panel shows them side by side. |
| MPO-12 | MPOs sell under Siam's drug licence. Real money is settled **outside** the platform — no payment/commission module; the order record is the MPO's proof for their company target. |

## 4.9 Pharmacy permissions summary

| Capability | Siam | Sub-admin | Staff | Paikari | Wholesaler | Public |
|---|---|---|---|---|---|---|
| Sales totals / profit | ✔ | ✔ | ✘ | ✘ | ✘ | ✘ |
| Loss warnings | ✔ | ✔ | ✔ | — | — | — |
| Force loss sale | ✔ | per role | ✘ (request approval) | — | — | — |
| Stock correction | ✔ | per role | ✘ (report mismatch) | — | — | — |
| Approve stock count | ✔ | per role | ✘ | — | — | — |
| Prescriptions | ✔ | ✔ | ✔ | upload only | — | — |
| See stock | ✔ | ✔ | ✔ | **never** | own stock module only | n/a |
| Expenses / net profit | ✔ | ✔ | ✘ | — | — | — |
| Cash reconciliation | ✔ | ✔ | ✘ | — | — | — |

---

# PART 5 — OTHER SECTORS

## 5.1 Public Market (ch 13) — Step 5

Open to everyone; retail prices the same for all. A public buyer **never** sees wholesale or paikari — not even their existence.

| Feature | Rule |
|---|---|
| **Homepage** | Sectors as big icons; **own-area products/shops first**; categories, search, filters (price/category/type/rating), sort (newest/price/popularity), personal recommendations (P2) |
| **Budget browsing** | Buyer enters a budget → that range's sector comes first. **Only ranges that actually have products are shown.** |
| **Premium sector** | Siam sets a taka threshold (per category); products above it auto-enter Premium; price drop moves them out, rise moves them back |
| **One product, many sellers** | Central catalog (sellers pick, new products need Siam's approval); one page compares all sellers. Ranking = **price + delivery charge + rating + shipping speed** (P2) — not price alone |
| **Product page** | Photos + zoom, size/colour variants, **quantity-discount steps**, delivery info, reviews with photos/video (**verified buyers only**, "Verified buyer" badge), social share, Q&A (§6.5), price history, frequently-bought-together |
| **"Why didn't you buy?"** | On exit: reasons list; "price too high" opens a **price-proposal** field; seller accepts or counters; auto-cancel after ~24 h with no reply |
| **"I was looking for this"** | One click on empty search results; requests grouped with counts in admin ("23 people looked for this") |
| **Cart** | Mixed sectors, wishlist, multi-address book (+ **"For whom"** field), auto-location (§6.1), note/voice note. **COD per product; if any cart item lacks COD, COD hides for the whole cart.** |
| **Multi-seller cart** | One cart, one checkout, one payment; **split by seller**: separate packages, tracking, memos (in each seller's name), couriers/charges |
| **After purchase** | Tracking, nice memo, order history, buy-again reminders |
| **Edit/cancel** | By buyer for a set window (15–30 min); address change until packed; stale unprocessed orders auto-cancel after a set time |
| **Digital products** | Download link right after purchase + email copy; **download count and expiry limits** |
| **Holiday** | Order accepted: "Will be processed on the next working day" |

## 5.2 Wholesale and Commission (ch 14)

### 5.2.1 Buying side — Step 4 (**P1**)

A closed, protected section for approved accounts only.

- **Application with licence upload required**; accounts given only after documents are collected.
- **Category-based access** (§3.4). **MOQ** per product with clear message. **Payment methods (COD/advance) set per customer.** **Dashboard:** purchase history, spending trend, own grade/level. **Pre-order time window** 2/3/4/5 days, replied Accepted/Declined. Tier progress shown **only at month end**.
- Bulk order upload and custom quotation: **P2**.

### 5.2.2 Selling side and commission — Step 6 (**P2**)

Wholesalers may also sell. Where: **public and/or wholesale (can set different prices); never paikari.**

| ID | Requirement |
|---|---|
| COM-1 | **Commission is added on top** of the seller's price (seller 500 + 2% → buyer pays 510; the 2% accumulates for Siam). |
| COM-2 | **Commission is set per product** — % or fixed taka (not one per profile). Siam sees who owes what and units sold. |
| COM-3 | Sellers can **request lower commission** via a note field; Siam decides. |
| COM-4 | **Every product needs Siam's approval before publishing** (wholesale and food alike), with the commission set. Show pending count, wait time, and flag products without photos. |
| COM-5 | **Month-end statement** to each seller: sales X, commission Y → reply **"Paid"** (Siam verifies) or **"Problem"** (note required). **No reply → account suspended.** Cancelled orders remove commission; totals use **complete sales only**. |
| COM-6 | **Money does not pass through the platform:** buyer pays the seller's own bKash/bank; Siam collects only commission at month end. (Online payments are held by Siam until delivery, then paid out; returns refunded from that.) |
| COM-7 | Seller decides: price, courier and delivery charge, packing, receiving payment, **receiving returned goods**, return policy. Siam decides: commission, publish approval. Siam's 2–3-day/50% medicine rules apply **only to his own products**. |
| COM-8 | **White-label dashboard.** Sellers see buyer **phone and address only** (for delivery); **name hidden**. After the sale/delivery both are **masked as \*\*\***. Notifications to the buyer go from Siam's platform. |
| COM-9 | **Anti-poaching:** masked contacts, **no promotional material in packages**, all communication via platform, penalty ladder (warning → listing removed → suspended → payouts withheld). |
| COM-10 | Returns go straight to the seller (no extra delivery cost) and into the seller's profile (sold/returned/why). COD returns: seller refunds; online: refund from held money. Unrefunded caught at commission collection. |
| COM-11 | Complaints go to the seller **and Siam gets a copy**; serious complaints tracked separately. |
| COM-12 | Seller reports: viewed-but-not-bought, top earners, monthly trends, **supplier rank** (fast shippers, few returns rank higher). |
| COM-13 | Guards: buyer can report "Not received" (not a complete sale, no commission); **block seller buying own product**; **vacation mode** (new orders off, existing continue); products with active orders can only be hidden, not deleted; report copied listings; unseen-order count + SMS backup. |
| COM-14 | **Gradual trust:** new sellers start with low-value or COD-only; limits rise after 10–20 clean orders. **Security deposit: F** (not turned on at launch). |

## 5.3 Multi-Sector builder (ch 15) — Step 7 (**P2**)

**Do not write separate code per sector.** Build a **sector-builder** so Siam can create sectors himself. When creating a sector he sets: needed fields (colour, size, measurements, weight, expiry, warranty); unit (piece, foot, yard, kg, sack, liter); whether **fractional quantities** are allowed (2.5 yards); pre-order; chat; custom orders; return rules; premium threshold.

- Other sectors are "like a normal e-commerce site": **no** generics, MPO, bonus, expiry, %-purchase price or loss protection. Basic stock only (add, sell, low-stock alert, best/worst sellers, optional cost entry).
- **Colour × size grid:** one grid (colours down, sizes across), not 25 SKUs. A sold-out colour greys only that colour (pre-order possible). Per-combination price (else base price) and per-colour photo.
- A product may be in several categories but has **one stock**. Mixed-sector carts split per package/memo/courier. Each sector's rules are shown on the product page. Prohibited-category list; Siam checks at approval.
- **Wood:** every item can have size (height/length/width); made items carry a given size; unmade items via pre-order options or custom order.
- **Field templates for future sectors** (ch 29-B, don't build now): Electronics (warranty, serial no., specs, original/copy, service centre, box contents); Cosmetics (expiry mandatory, skin type, ingredients, authenticity code); Baby (age range, weight size, expiry); Grocery (kg/liter/piece/dozen/hali, fractional, fresh/packaged, expiry, same-day delivery for fresh); Stationery & books (author, publisher, edition, class, set vs single); Hardware (piece/sack/foot/ton, grade, heavy → arranged delivery); Agriculture (crop, season, per bigha, expiry); Car parts (model-year, fits-which-car, new/reconditioned, part no.); Second-hand (condition, years used, bargaining); Rental (per day/week/month, deposit, return date, late fine); Mobile recharge/bills (operator, instant); Services (type, price per hour/job, area, time booking).

## 5.4 Custom Orders (ch 16) — Step 8 (**P2**)

Wood and clothing made to measure by **Siam's own craftsmen**. **Not the same as pre-order** (pre-order = existing product, limited options, no chat, returns allowed; custom = all options, chat, **no returns**).

| Topic | Rule |
|---|---|
| Who orders | Everyone (public, paikari, wholesale) |
| Pricing | Siam makes the memo directly: material + wages + total. **No commission** — it's Siam's own business. Profit = buyer price − material − wages. |
| **Wood measurement form** (via chat "Send measurements") | L/W/H, depth/thickness, unit (inch/ft/cm), **wood type (price changes immediately)**, polish/colour, drawers/shelves, handles/glass; photo of the space; craftsman sets time; minimum advance |
| **Clothing** | Men: chest, waist, length, sleeve, shoulder, neck, trouser length, thigh, hem. Women: bust, waist, hip, length, sleeve, shoulder, neck depth, kameez length, salwar length. Fabric type, colour, design photo, collar/sleeve/buttons/pockets, **fabric from buyer or shop (price changes)**; optional shop-visit measuring and a **trial step** |
| **Measurement profiles** | Unlimited named profiles ("Mine", "My son's"…); pick several in one order; dated; reminder when old (e.g. 8 months); editable; list of past orders |
| **Craftsmen** | One mainly responsible per order; may shift or share the job (history shown). Assigned **manually** by Siam/sub-admin (workload via active-job counts). Order not sent until wages are agreed. Craftsman sees job, not price. |
| **Wages = progress %** | Total wage split by entered percentages (1,000 → 20/50/30% = 200/500/300). The **same % is the buyer's progress** ("70% done"); buyer never sees craftsmen's names. Below 100% = not "Complete". **A photo is mandatory with every % update.** Main craftsman decides the split; Siam can fix. Reminders; no update 3 days → notify Siam. Profile has Owed/Paid. |
| **Advance** | % set per order (e.g. 30/50%); advance goes to the craftsman. Cancel before work starts → refund; after → no refund (printed on memo). |
| **No returns** | Memo prints "Returns do not apply to custom orders…". "Work not done right" complaint → Siam reviews (craftsman's mistake → fix/refund; wrong measurements → buyer's responsibility; **both sides confirm measurements**, record kept). |
| **Other** | Deadline miss → notify Siam and buyer; craftsman quits → reassign; "Materials needed" → job shows Waiting; **urgent option with extra charge**; **"Delivery by arrangement" mandatory**; stock material is deducted; material-cost field. |

## 5.5 Food (ch 17) — Step 9 (**P2**)

Foodpanda-style; **fast food only**. Siam sells nothing — he takes a **commission set by hand per shop**.

- **Browse:** take customer location → show connected restaurants → show only currently available items.
- **Restaurant controls:** menu/photos/prices, item availability (on/off, **"Sold out today" auto-resets next day**), open/closed + hours (auto-closes), cooking time, own delivery vs platform courier, advance, delivery charge.
- **Order states:** Pending → Confirmed (owner enters ETA) → cooking (timer on buyer screen) → Packing → Out for delivery → Delivered. No confirmation → prompt buyer "Cancel?". Options: **home delivery** or **"I'll eat there"** (ordering ahead; time optional with basic options).
- **Custom food:** buyer builds from a base (burger/pizza/sandwich/roll) + ingredients from **Siam's central ingredient list** (restaurants *tick* what they have, can't type; they can request additions). Groups: meat, cheese, vegetables, sauces, extras. **Each restaurant sets its own ingredient prices.** Save favourite builds; incompatible-combination rules; maximum ingredient limit; kitchen print-out; extra time for custom.
- **Delivery charge (3 options):** area-based / per-km (**road distance**, not straight-line) / manual; **max distance** (no orders beyond); **minimum charge**.
- **Advance:** set by owner or Siam; required on large orders.
- **Memo** in the restaurant's name, Siam's name small at the bottom; custom food lists each ingredient with price.
- **Rules:** cancelled after cooking starts → advance not refunded, no commission; restaurant can't close with active orders; photo complaints go to restaurant + Siam; nearby orders grouped for the delivery person.
- **Dashboard:** new-order alerts, status buttons, menu, today/month sales, net after commission, open/closed switch.

## 5.6 Gaming (ch 18) — Step 10 (**P2**)

Codashop-style **diamond/top-up only**: choose game → enter player ID → **ID verified (player name shown for buyer to confirm)** → pick package → pay online (bKash/Nagad/Rocket/card; **no COD**) → automatic top-up (keep a **manual backup** in admin if automation isn't available at start) → confirmation. Failed top-up → automatic refund or retry + failed list; late → buyer told and may cancel for refund. Admin: games, packages/prices, order list, sales report.

## 5.7 Help (ch 19) — Step 10 (**P2**)

A Fiverr-ish **post board** (no payment/escrow/ratings): unemployed students find small jobs; businesses post needs. Everyone may post; **Siam or a sub-admin approves every post first**. Categories: Need a worker / Looking for a product / Need help. Comments allowed; **no photos or hangout**; product links in comments render as **product cards** (photo, name, price at viewer's price, View button; obey visibility; deleted → "no longer available"). Post auto-closes if "Still needed?" unanswered after 30 days; limit posts per person; transactions are explicitly not the platform's responsibility; report option.

---

# PART 6 — CROSS-CUTTING SYSTEMS

## 6.1 Delivery and Courier (ch 20)

**Auto-location:** "Use my location" fills area/thana/district (editable house/flat), saved addresses, optional map pin, "How to get there" note. Ask permission first; manual entry must still work.

### Delivery charge priority

| Order | Layer | Applies when |
|---|---|---|
| 1 | **Mandatory charge on the product** (e.g. in-zone 100 / out-zone 150, or the product's minimum 150/200/300) | Always wins, even if weight is also set |
| 2 | **Weight-based courier charge** | Weight given for **every** item |
| 3 | **Area-based flat charge** | Any item has no weight → flat charge for the **whole order** |

- **Paikari:** no courier calculation — Siam types the charge (§4.4.2 step 9).
- **Couriers are set in each seller's profile (not per product)**, unlimited, entered **by hand — no API integration** (Pathao/Steadfast/RedX integration is optional only). Each has zones (own area / inside district / outside district), first-1-kg charge, additional-kg charge, COD %. Calc: first kg + (remaining kg × additional rate) + COD %. Checkout shows **all couriers side by side with prices**. Weight rounds **up**; rounding rule in settings. Seller with no courier set can still order normally. **Reminder every 3 months** to re-verify courier rates; prompts to add weights (dashboard count, bulk edit).
- Product upload sets: weight (optional), mandatory charge, COD allowed, advance (and amount), free delivery.
- **Advance delivery charge:** orders above a threshold (e.g. 5,000) require the charge upfront; explained at checkout/product page.
- **Wholesale pharmacy courier:** options 50-50 / advance / fixed; stays a draft; whatever Siam finally enters is the charge.
- **`charge_pending_memo`:** buyer paid 150 advance, real cost 200 → memo stays draft; extra 50 added; final memo shows "Extra delivery charge 50" and "Product on the way" at courier hand-off.
- **Pickup point:** only for sellers with name/location display ON; reminder after ~3 days; uncollected → cancelled, 50% rule.
- **Delivery person app:** today's list grouped by area, cash to collect, mark delivered, "Arrived" notification; record of cash held, reconciled daily.
- **Delivery confirmation (P1):** OTP to buyer phone entered by the delivery person, or photo proof, or buyer "Received" tap in app (courier deliveries); auto-confirms after a set number of days unless "Not received" is reported. **This is what turns `pending_delivery` into `complete_sale`.** No proof → not a complete sale, no commission.
- **Rating after delivery (P2):** 1–5 stars + comment for delivery and service, linked to delivery person and staff (buyers never see staff names); low ratings alert Siam.

## 6.2 Pre-Order (ch 21) — Step 4 (**P1**)

**One system across all sectors;** the order goes to **whoever turned it on**: MPO → the MPO; Siam → Siam; wholesaler → the wholesaler. Time set by the owner (2/3/4/5 days); seller replies Accepted/Declined; cancellations always notified.

- Medicine: by company or by product. Other sectors: by existing **size/colour only** (limited options; made-to-measure = custom order).
- **Accumulated view:** when Siam orders from a company, that company's pre-orders appear together (own 20 + Karim 5 + Rahim 3) so he can add or leave them. **Not linked to stock.**
- Buyer is told **nothing** when sent to the company; told **after goods arrive**. Then Siam reserves in their name or puts in general stock.
- **`preorder_draft_memo`:** editable; Siam enters actual received boxes per line; pressing "Receive" finalises and the buyer sees it live. If 10 wanted and 6 came, **first-ordered gets first**; the rest are told.
- Never arrives → cancelled; draft becomes a sorry note; refund. Overdue → turns red + notification; seller sets a new time or buyer cancels. Buyer may cancel if goods come after the time limit (advance refunded). Seller-fault cancel → full refund; buyer cancel → seller's rules. Advance as % or taka.

## 6.3 Memos and Invoices (ch 22)

| ID | Requirement |
|---|---|
| MEM-1 | Every sector produces a clean memo, **including public**. Contents: business name/logo, **memo number with year**, date, buyer, items (name/qty/unit price/total), discount, **bonus as its own line**, **return credit as a minus labelled "Return product"**, delivery, grand total, payment status/method, return policy, **staff name**. One line per calculation step. |
| MEM-2 | **Mixed offer + regular:** offer section → regular section → each subtotal → one grand total. |
| MEM-3 | Sector styles: Public (nice PDF) · Paikari (MRP memo then final, editable) · Offer (no MRP memo) · Pre-order (draft) · Custom (material + wages separate, "Returns do not apply") · Food (restaurant name, Siam small) · Gaming (top-up receipt). |
| MEM-4 | **Thermal printer** 58 mm and 80 mm (setting), one-click print, optional cash-drawer open; also A4/PDF. |
| MEM-5 | Send by PDF, email, WhatsApp; any old memo viewable; works without a printer. |
| MEM-6 | Numbers include the year; counter and online numbers never collide. Corrections: new number, old marked **"Cancelled"**, both kept. Reprints say **"Reprint"**. |

## 6.4 Messages, Notes, Chat (ch 23)

- **Automatic status messages** for everyone (public too): received → picking → packed → sent → delivered, plus partial supply (with list) and cancelled (with reason). Channels: inbox, push, SMS, email — **per-message channel control in admin** (SMS costs money).
- **Notes/voice notes/time/photo** can be added to any order.
- **Chat exists only in custom orders** (wood & clothing); a **per-sector toggle** (P2). Text/photo/voice; buttons **"Send measurements"** (opens form) and **"Send offer"** (price + time; buyer taps Accept → order created). Phone numbers and links are **blocked**; Siam can read all chats; buyer chats with the craftsman **under Siam's name**; measurements/offers show as cards; slow craftsman reply alerts Siam.

## 6.5 Q&A

Under every product; the **owner is notified**, answers publicly; seller identity stays masked unless name display is ON; unanswered after a set time → comes to Siam; repeated → mark on profile.

## 6.6 Wallet and Tokens (ch 24) — Step 6 (**P2**)

Wallet has two parts: **works everywhere** (tokens, referral) and **sector-only** (return credit). **"Aqua Token"** replaces coupons: **platform token** (only Siam gives, works in every sector) and **seller token** (same for all that seller's customers, only on their products). Cancelled order → token returns. Credit and token both used → credit first, then token, two memo lines. No combining with offers. Phone verification prevents farming. *(Order mismatch with ch 04: OQ-1.)*

## 6.7 Payments, Notifications, Legal (ch 27)

| Sector | Payment rule |
|---|---|
| Public | All methods; COD by product toggle |
| Wholesale | COD/advance set per customer |
| Paikari | Bank, bKash, COD, advance; return credit deducted |
| Food | Online + COD; advance on big orders |
| Gaming | **Online only** |
| Custom order | Advance + rest on delivery |

Methods: bKash, Nagad, Rocket, bank transfer, card, COD, advance. Notifications: push (status, offers, back-in-stock, messages), **SMS** (confirmations, OTP, delivery, packed — most effective in Bangladesh), email (downloads, memos).

**Legal (P1):** Siam holds the **drug licence** (MPOs sell under it); wholesaler licences collected; Terms of Service and Privacy Policy pages mandatory; food licence if needed.

**Controlled-medicine sale register (P1, legal):** selling a *Controlled* medicine **cannot be finalised** without buyer name, phone/address, prescription number, doctor name, quantity, date, staff name. A register screen lists every controlled sale (filter by date/medicine), **print/PDF export for inspectors**, edits only by admin/sub-admin and logged.

**Future:** prescription marketplace (reserve DB + stub UI). Trust: verified badge, return-policy page, real address, WhatsApp/Messenger button, FAQ/Help centre. Reward points, referral, bundles, flash sale, abandoned-cart, price-drop (P2); Pixel/analytics/affiliate/bulk SMS (F).

## 6.8 Technical requirements (ch 28, 29-C)

| # | Requirement |
|---|---|
| T1 | Server-side visibility (§3.4). |
| T2 | **Real-time updates** everywhere: paikari fulfillment, drafts, bidding, messages, food, custom-order progress, staff concurrency. Never ask users to refresh. |
| T3 | Three **central lists**: medicines, food ingredients, other products (Siam maintains; sellers pick; request additions). |
| T4 | Medicine data collection = separate job, **start Day 1**. |
| T5 | MPO: one-to-many (MPO↔companies, MPO↔products) from the start. |
| T6 | Sector-builder system (§5.3). |
| T7 | Backups (SEC-6). Server time (SEC-7). |
| T8 | Work never lost on connection drop; each item saved immediately; counter fully offline (CTR-5). |
| T9 | **Bulk import** tool for old data (rates, tiers, addresses) before launch. |
| T10 | Performance: old data archived separately (P2), **image auto-compression, low-quality preview first**, handle festival peaks, server updates at night/closed days (announced), move old media to cheaper storage over time. |
| T11 | **Store user behaviour data from Day 1** (search, view, buy, skip). |
| T12 | VAT/Mushak fields in DB from Day 1. |
| T13 | Bangla/English toggle — **product names are never auto-translated**. Text-size option. SEO for public pages. Web ↔ app cart sync. |
| T14 | Document all settings so a successor can understand them. Baki khata / supplier dues ledger: not now, connect later (F). |

---

# PART 7 — REFERENCE TABLES

## 7.1 Worked examples (use as test cases)

| Topic | Input | Result |
|---|---|---|
| Price priority | Karim, Tier B 15%; company K 20%; this medicine 12%; manual 25% | nothing set → 15% · company set → 20% · product set → 12% · manual set → **25%** |
| Manual rate vs tier change | Tier B rate changes | Karim's manual 25% **unchanged** |
| Cascade | MRP 100; company 12%; MPO 10% | 88 → **79.2** (20.8% total), *not* 78 (22%) |
| Bonus | 10+2 at 79.2/box | paid 792 ÷ 12 = **66/box** → 34% below MRP 100 |
| Lot choice | 10 boxes @14%, 20 boxes @20% | sell at 12% → 14% lot; sell at 25% → 20% lot |
| Offer lot | 10 @14% + 10 @25% | only the **25% lot** (10 boxes) is offered |
| Quantity discount (public) | 1–4: 0; 5–9: 5%; 10–19: 10%; 20+: 15% | applied automatically, shown on screen |
| Months of stock | 15 boxes, 1/month | 15 months → flagged (>12) |
| Partial return | Box 500, 50 pcs; return 30 | 10/pc × 30 = **300** |
| Offer return cash | Offer rate 300 | **150** |
| Courier | 6 kg, outside district; first kg 120, +20/kg; COD 1% on 3,000 | 120 + 5×20 = 220; +30 COD = **250** |
| Commission | Seller 500, commission 2% | buyer pays **510** |
| Craftsman wage | 1,000 total; 20/50/30% | **200 / 500 / 300** |
| Net profit | Gross 85,000; rent 15,000 + salaries 30,000 + electricity 4,000 + other 3,000 | **33,000** |
| Tier offer | Bought at 25%, upload at 20% | all paikari buyers see **20%** instantly (manual-rate customers excluded) |
| Staff limit | Limit 5%; customer asks 8% retail | screen stops at 5%, "8% needs admin approval" → one-tap |

## 7.2 State and status dictionary

| Domain | States |
|---|---|
| Sale | `pending_delivery` → `complete_sale` |
| Paikari order | Received → Picking → Packed → Sent for delivery → Delivered/Collected (+ cancellation-requested) |
| Item verification | Fully available / Partially available (n) / Not available |
| Expiry check | Expired / Checked (date optional) |
| Returned expired batch | Returned / Not taken back (loss) / Pending |
| Offer listing | Active → (stock out) grey 1–1.5 h → removed; (month end) off / ask to keep |
| Food order | Pending → Confirmed (cooking) → Packing → Out for delivery → Delivered |
| Return | Applied → Approved (credit) / Rejected |
| Seller commission | Statement → Paid / Problem / No reply (suspended) |
| Pre-order | Placed → Accepted/Declined → Received (draft memo → final) / Cancelled (sorry note) / Overdue |
| Gaming top-up | Paid → Topped up / Failed (refund/retry) |
| Help post | Submitted → Approved/Rejected → Closed (solved or 30-day timeout) |

## 7.3 Settings catalogue — every value Siam wants to change himself

| Area | Setting |
|---|---|
| Stock | **Master Stock Sync** (global, default OFF) · stagnant months (6) · months-of-stock threshold (12) · near-expiry months (3) |
| Approval | Loss/discount approval timeout · discount limit per role and per staff |
| Drafts | Colour-change time (1 h) · idle notification days (1–2) · re-ask interval (2 days) |
| Offers | Offer-stock alert threshold · min profit margin · notification threshold % · grey-out duration (1–1.5 h) |
| Returns | Paikari return days (2–3) · retail return days · credit warning (1–2 months) |
| Paikari | Free-delivery threshold per buyer · problem-buyer flag count · monthly-total toggle per buyer · habit reminders on/off |
| Delivery | Courier list/rates · courier review interval (3 months) · weight rounding · "Received" auto-confirm days · pickup reminder days · advance-delivery threshold |
| Orders | Edit/cancel window (15–30 min) · stale-order auto-cancel · Q&A answer timeout · price-proposal timeout (24 h) |
| Calendar | Holiday/closed days · monthly report date · nightly-summary time |
| Display toggles | Name display (per seller) · sales display (per customer) · COD (per product) · %/taka (per customer) · sold-out-today (per food item) · chat/pre-order (per sector) |
| Notifications | Channel per message type · importance levels |
| Printing | Thermal width 58/80 mm · cash-drawer open |
| Custom orders | Minimum advance · reminder interval · 3-day no-update alert |
| Help | Still-needed prompt (30 days) · posts per person |
| Commission | Statement-reply timeout · commission per product/restaurant |

## 7.4 Open questions — **ask Siam, don't guess**

| # | Question | Where it conflicts |
|---|---|---|
| **OQ-1** | **Discount order:** ch 04 says price → quantity → **token → return credit**; ch 24 says "credit is deducted first, then the token". Also ch 04 says discounts are applied in sequence *and* "never added — whichever is larger applies". Which is final, and which discounts compete vs stack? | ch 04 vs ch 24 |
| **OQ-2** | **Public medicine returns:** ch 12 says medicine is not returnable on public/wholesale, yet describes a public apply→approve flow. Is that flow for non-medicine products only? | ch 12 |
| **OQ-3** | **Counter vs engine:** the counter has a 3-level priority (per-medicine % > invoice % > tier) while the engine has 4 layers (manual > product > company > tier). Does a counter invoice % override a customer's manual rate? | ch 04 vs ch 08 |
| ~~OQ-4~~ | ~~Data source for the medicine database~~ — **resolved:** existing `pharmacy` DB + `public/medicine_images` (see §4.1.1). Still open: who sets the prescription/cold-chain/controlled flags. | ch 11, 28 |
| OQ-5 | Rounding rules for % ↔ taka — decide before the pricing engine. | ch 04 |

## 7.5 Chapter map

| Topic | File |
|---|---|
| Header, glossary | `00-header.md` |
| How to read, phase tags | `01-how-to-read.md` |
| Business concept | `02-business-concept.md` |
| Accounts, roles, two apps | `03-accounts-roles.md` |
| Pricing | `04-pricing.md` |
| Visibility | `05-visibility.md` |
| Stock system | `06-stock-system.md` |
| Paikari | `07-paikari.md` |
| Counter sale | `08-counter-sale.md` |
| Offers | `09-offers.md` |
| MPO | `10-mpo.md` |
| Medicine database | `11-medicine-db.md` |
| Returns | `12-returns.md` |
| Public market | `13-public-market.md` |
| Wholesale and commission | `14-wholesale.md` |
| Multi-sector | `15-multi-sector.md` |
| Custom orders | `16-custom-orders.md` |
| Food | `17-food.md` |
| Gaming | `18-gaming.md` |
| Help | `19-help.md` |
| Delivery and courier | `20-delivery.md` |
| Pre-order | `21-pre-order.md` |
| Memos and invoices | `22-memos.md` |
| Messages, chat, Q&A | `23-messages-chat.md` |
| Wallet and tokens | `24-wallet-tokens.md` |
| Admin and reports | `25-admin-reports.md` |
| Staff and security | `26-staff-security.md` |
| Payments, notifications, legal | `27-payments-legal.md` |
| Technical notes | `28-technical.md` |
| Development order | `29-dev-order.md` |
| All companies sector | `29A-all-companies.md` |
| New-sector field templates | `29B-new-sectors.md` |
| More complications | `29C-complications.md` |
| Feature checklist (613 items) | `30-feature-checklist.md` |

---

*Digest of: siams-aqua-ecommerce-spec (5,983 lines, written by Siam). Full text: `docs/chapters/`. Last reviewed against all 34 chapters.*

### Security status and production checklist (audit of 12 Oct 2026)

Clean: admin routes need `auth`, the admin section gate and the `manage-customers` policy; every write validates its input; no raw HTML output; no request-wide mass assignment; role (`account_type`) cannot be mass-assigned; denied and unknown pages redirect silently (G1); product requests, search and the instant-search endpoint are throttled; every response carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` (and HSTS over HTTPS).

Before going live: set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, a real `APP_URL`; delete `public/hot`; run `npm run build`; configure trusted proxies if behind a load balancer; add a nonce-based Content-Security-Policy (Inertia and Vite inject inline scripts, so it needs its own piece of work); move backups off the server (BACKUP_DISK).
