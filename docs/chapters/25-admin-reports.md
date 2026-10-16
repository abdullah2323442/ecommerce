## 25 — Admin Panel and Reports

> **[IMPORTANT]** The admin panel is my control room. I make almost every important decision, so it has to be very powerful. Admin has full access.

### Product management
- Add, edit, delete products
- **Where it shows** — public / wholesale / paikari, a different price for each
- Category, MOQ, quantity-discount steps
- **COD toggle**, **advance delivery charge**, **weight**
- **Stock display mode**, **offer tag and minimum boxes**
- Photos and video, variants, SEO info
- **Commission per product** — percentage or taka

### Customer management

> **[IMPORTANT]** Every control on **one page** of the customer's profile. No jumping between pages.

- **Category access**, **tier**, **manual rates**
- **Payment methods**, **free delivery threshold**, **shop location**
- **Account type change** — Upgrade to Wholesaler
- **Give or remove the stock module**
- **Commission rate**, **name display toggle**, **sales display toggle**
- Order history, total and monthly sales, **returns**
- Message history, account suspension

> **[DEV NOTE]** **All transactions on one page** — orders, returns, messages, commission together. Helpful while talking on the phone.

### Application review
- New wholesale, paikari and food-vendor applications
- **Trade license and drug license verification**
- Approve or reject, with a reason

### Order management
- All orders, filter by sector
- Update status and payment
- **Create memos, edit prices, upload**
- **Finalize draft pre-order memos**
- **Paikari fulfillment**, **placing orders on someone's behalf**
- **Approve return requests**

### Sector management
- **Create new sectors** — which fields, which unit, chat or not
- **Central lists** — medicines, food ingredients, other products
- **Requests to add to lists** — pending count, how long waiting
- **"I was looking for this" requests** from buyers — grouped with counts
- **Holiday / closed days** setting

### MPOs, craftsmen and food
- MPO accounts, territories, companies, product selection, bonus approval, bidding
- Craftsman accounts, job assignment, wages, active job counts
- Restaurant approval, commission, sales

### Reports
- **Sales report** — each sector separately
- **Profit**, best sellers, monthly and yearly comparison
- **Customer ranking** — who buys how much, with time filters
- **Return analysis**, **staff speed report**
- **Monthly ranking** (6.9)
- Data export — CSV/Excel

> **[DEV NOTE]** Big reports are **generated overnight**, ready instantly in the morning. Corrected entries are marked separately.

### Nightly summary to my phone

> **[IMPORTANT]** Every night (time set in settings), a short summary is sent to my phone by **WhatsApp or SMS**:
>
> **today's sales · today's profit · cash expected in the drawer · items that finished today · low-stock items · open drafts · pending approvals · returns today**.

- Can also be viewed in the admin panel
- Sub-admins can receive it too if I turn it on for them

### VAT / Mushak report for the accountant

- Monthly sales and VAT summary, exportable (Excel/PDF) for the accountant
- VAT rate set per product category; VAT shown on invoices where applicable
- Date-range filter and sector filter

> **[DEV NOTE]** Keep VAT fields in the database from the start (rate per category, VAT amount per invoice line) even if VAT display is turned off at launch.

### Activity log
Tier changes · price changes · account suspensions · product deletions · MPO company changes · commission changes · return approvals · stock corrections

> **[NOTE]** Essential for accountability when several staff work.

### Complications and solutions

> **[PROBLEM]** I'm on holiday; decisions are stuck.

<!-- -->

> **[SOLUTION]** Give a sub-admin **temporary extra powers**, with a time limit.

<!-- -->

> **[PROBLEM]** Too many notifications pile up.

<!-- -->

> **[SOLUTION]** Sorted by importance — urgent on top, normal below.

<!-- -->

> **[PROBLEM]** I deleted something by mistake.

<!-- -->

> **[SOLUTION]** Deleted items stay **30 days in a "Deleted" bin** and can be restored.

<!-- -->

> **[PROBLEM]** Month-end totals and reality don't match.

<!-- -->

> **[SOLUTION]** A **day-by-day breakdown** to find where the mismatch is.

---

