# PART 2 — FOUNDATION

---

## 03 — Accounts, Roles and the Two Apps

### Two separate apps

> **[IMPORTANT]** Just like Daraz, there will be **two apps**:
>
> **Customer App** — for those who buy
>
> **Supplier App** — for those who sell
>
> Plus a separate **Admin Panel** — on the web.

#### Who uses the Customer App

- Public buyers
- Paikari shop owners (who buy from me)
- Wholesalers when they are **buying**
- Food customers, gamers

**Contains:** browse, search, cart, order, tracking, returns, chat, pre-order, memos, wallet, measurement profiles.

#### Who uses the Supplier App

- Wholesalers when they are **selling**
- Food vendors
- **MPOs**
- **Craftsmen** (for custom orders)

**Contains:** product upload, stock, orders, memos, commission accounting, reports, chat.

> **[IMPORTANT]** **Not everyone sees the same screens in the same app.** It changes by role:
>
> **MPOs** see only their own section — product submission, bids, bonuses. They do not see other suppliers.
>
> **Craftsmen** see only their own jobs — measurements, photos, percentage progress, wages. No product upload, no stock.

#### Admin Panel

- Me and the sub-admins
- **Staff are here too** — counter sale, drafts, stock
- Works on mobile, but full power on PC

### Account types

| Type | How it is created | Which app |
|---|---|---|
| **Public** | Self sign-up or guest | Customer |
| **Paikari** | I open it, or they apply | Customer |
| **Wholesaler** | Application + license | Both |
| **MPO** | Only I can open it | Supplier |
| **Food vendor** | Application | Supplier |
| **Craftsman** | I open it (my own people) | Supplier |
| **Staff** | I open it | Admin panel |
| **Admin** | Me | Admin panel |

### One person, two business accounts

> **[IMPORTANT]** One person can open **two business accounts** (for example a wood business and a clothing business).
>
> When they enter their profile, they can see **each business separately** — how each one is doing: sales, top products, returns, commission owed — each with its own figures. The two businesses must never be mixed together in the numbers.

<!-- -->

> **[DEV NOTE]** Treat each business as its own seller entity, both linked to one login. Add a business switcher at the top of the supplier dashboard, plus an optional "all businesses" summary view.

### Complications and solutions

> **[PROBLEM]** The same person wants to buy and also sell. Do they need to install two apps?

<!-- -->

> **[SOLUTION]** **One account, the same login in both apps.** A button in the Customer app — "Go to Supplier app".

<!-- -->

> **[PROBLEM]** Which app receives notifications?

<!-- -->

> **[SOLUTION]** New orders go to the Supplier app. Updates about things they bought go to the Customer app.

<!-- -->

> **[PROBLEM]** When someone taps a product link, which app opens?

<!-- -->

> **[SOLUTION]** Always the Customer app.

<!-- -->

> **[PROBLEM]** Two apps means double the cost and work.

<!-- -->

> **[SOLUTION]** At the start, keep **both parts inside one app** with an internal switch. Split them into two apps once users grow.

<!-- -->

> **[PROBLEM]** One person makes many accounts to grab tokens or offers.

<!-- -->

> **[SOLUTION]** Phone number verification. **One account per phone number.**

<!-- -->

> **[PROBLEM]** Someone ordered as a guest, then opened an account — old orders don't show.

<!-- -->

> **[SOLUTION]** If they open the account with the same phone number, the old orders are linked automatically.

<!-- -->

> **[PROBLEM]** A customer wants to delete their account.

<!-- -->

> **[SOLUTION]** The account is closed, but **old invoices and transactions stay** — for business accounting and legal needs. Only personal information is hidden.

### Login

- Everyone uses **the same login system**, but each type lands on a different starting page (public → public page, paikari → paikari page, wholesaler → wholesale page)
- Paikari users and wholesalers can still go to the public page from there — but every type's pages are different
- **Social login** (Google, Facebook) — public only
- **Guest checkout** — name, phone and address are enough
- **2FA** — for admin, staff, wholesale and paikari
- Forgot password → reset with a code sent to the phone

---

