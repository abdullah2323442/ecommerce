## 29 — Development Order

> **[IMPORTANT]** This document has many features. Building them all at once would take years and leave everything half-done.
>
> So work **step by step**. Finish one step completely and launch it, then move to the next.

### Step 0 — Foundation (nothing stands without it)
- Account types and roles
- Login and role-based redirect
- **Server-side visibility control**
- Admin panel structure, staff roles
- Basic security — 2FA, IP block, suspension
- **The full pricing engine** — four layers
- Backup system

> **[DEV NOTE]** Changing this foundation later means rewriting almost the whole system. Take your time here.

### Step 0-A — Medicine data (in parallel)

> **[IMPORTANT]** Start **on day one**, alongside coding. Without data nothing can launch.

### Step 1 — Stock system (most urgent)
- Source-based purchase price, three-layer rates
- Cascading discounts, purchase price including bonus
- Purchase price locked in taka
- **Profit/loss protection**
- Stagnant stock and "months of stock"
- Expired section and returning to companies
- Stock correction
- **Draft invoice**
- Draft sale vs complete sale
- Master Stock Sync switch (built now, kept off)
- Reports and monthly ranking

### Step 2 — Paikari and counter sale
- Paikari accounts, tiers, rates
- Medicine search and ordering
- **Full fulfillment** — three options, real time, staff concurrency
- MRP memo → final memo
- Short list and PharmaTrack connection
- **Counter sale** — three sections
- Delivery, free delivery threshold
- Generic and frequency suggestions
- Messages, notes, voice notes

> **[NOTE]** **As soon as this step is done I'll start using it.** Then continue the rest.

### Step 3 — Offers and returns
- Offer products and tier offers
- Return sub-sector — all types
- Retail refunds and three kinds of search

### Step 4 — Wholesale and MPO
- Wholesale accounts, licenses, category access
- MPO — territory, product selection, bonus, bidding
- Pre-order

### Step 5 — Public market
- Public accounts, guest checkout
- Cart, checkout, COD rules
- **One product, many sellers**, area-based, shop pages
- Budget and premium sectors
- Reviews, Q&A, deep links

### Step 6 — Commission and two apps
- **Commission per product**, monthly collection
- White-label dashboard, data masking
- Separate **Supplier app**
- **Wallet and tokens**

### Step 7 — Multi-sector
- **The sector-builder system**
- Color, size, various units
- Wood, clothing and other sectors
- Two business accounts per person

### Step 8 — Custom orders
- Measurement forms and saved profiles
- Craftsman accounts and percentage-based wages
- **Chat**

### Step 9 — Food
- Restaurants, menus, cooking timer
- **Custom food and the central ingredient list**
- Three delivery-charge options

### Step 10 — Gaming and Help
The simplest and most independent — can be done whenever there's free time.

### Step 11 — Extras
Recommendations · reward points · referral · bundle deals · flash sale · abandoned cart · support tickets

### Step 12 — Future
Prescription marketplace · Analytics · affiliate · bulk SMS · credit ledger · full PharmaTrack sync · security deposit · service/coaching booking

### At a glance

| Step | What | Why here |
|---|---|---|
| **0** | Foundation + medicine data | Nothing stands without it |
| **1** | **Stock system** | Paikari and counter stand on it |
| **2** | **Paikari + counter** | My running business |
| **3** | Offers and returns | Needed daily |
| **4** | Wholesale and MPO | On the same structure |
| **5** | Public market | Big audience, less urgent |
| **6** | Commission and two apps | Not possible without public |
| **7** | Multi-sector | Once the structure exists |
| **8** | Custom orders | Only once sectors exist |
| **9** | Food | Almost a separate platform |
| **10** | Gaming and Help | Simple, independent |
| **11–12** | Extras and future | After the core is running |

> **[DEV NOTE]** **At the end of every step:**
>
> 1. Launch that step completely — don't move on leaving it half-done
> 2. Have me use it; fix mistakes that show up
> 3. Then go to the next step

---

