## 08 — Counter Sale

> **[IMPORTANT]** **This is needed in Phase 1, not later.**
>
> Most of my shop's sales are at the counter. Without this, sales never enter the system — stock only goes up, never down. Then expiry, stagnant stock, profit — every calculation is wrong.

### Three separate sections

| Section | How it works |
|---|---|
| **Retail** | No customer selection. Calculated at MRP, discount at the end. |
| **Paikari** | Choose the customer first. Then everything at their rate. |
| **Wholesale** | Same — choose the customer, then their rate. |

> **[NOTE]** Retail drafts and the counter's retail section are the same thing — Customer 1, Customer 2, optionally named.

### Rate priority

> **[IMPORTANT]**
>
> **1. Separate percentage per medicine** (highest)
>
> **2. Percentage set at the top of the invoice**
>
> **3. The customer's own tier rate** (default)
>
> Entering the customer's name fills their tier rate automatically. But whatever I set above overrides it.

### In the paikari and wholesale sections

1. **Search the shop's name and select it**
2. Every medicine added is **at their own rate**
3. Pieces / strips / boxes — all three
4. The sale is **automatically added to their e-commerce profile**

> **[IMPORTANT]** **The shop owner cannot see** how much business they gave — only I can. But **at month end** I can show it (toggle).
>
> **If a refund is needed**, they can do it from there (e-commerce or PharmaTrack — wherever they bought).

<!-- -->

> **[PROBLEM]** A new customer arrives with no profile.

<!-- -->

> **[SOLUTION]** **Quick profile creation** right at the counter — name, phone, area. The rate can be set later; for now the default tier.

<!-- -->

> **[PROBLEM]** Two shops with the same name.

<!-- -->

> **[SOLUTION]** In search, show **area and phone number** beside the name. If even the phone is the same, a separate code.

<!-- -->

> **[PROBLEM]** They also ordered online and forgot.

<!-- -->

> **[SOLUTION]** Searching the customer shows **"This shop has an active order"**.

### Retail section — MRP and end discount

1. Every medicine is calculated **at MRP**
2. At the end, **one discount** — 5%, 8%, 10%
3. The discount **appears on the memo**

> **[IMPORTANT]** **Special rule:** if a product has its own special discount, it **is not included in the general end discount**. One item never gets discounted twice.

<!-- -->

> **[DEV NOTE]** This MRP + end-discount system works the same way in both PharmaTrack and e-commerce.

### Offers work at the counter too

> **[IMPORTANT]** E-commerce and the counter are synced. So **whatever offer is running on e-commerce runs at the counter too** — but only in paikari and wholesale. **There are no offers in retail.**

<!-- -->

> **[EXAMPLE]** A paikari buyer comes and asks for Napa. I search.
>
> The screen **shows two** — normal on top, offer below.
>
> I ask: "Will you take the offer? Minimum five." If they agree, **I click the offer** — it's set at the offer rate and deducted from stock.
>
> If the minimum isn't met I won't give the offer; their profile rate applies.

- The offer is never applied automatically — **I have to click it**
- Bonus works the same way — if 10 boxes carry a bonus, clicking the offer applies the bonus
- When I give an offer at the counter, it **automatically goes into that customer's profile, in their offer section** — exactly as if they had ordered the offer online
- At the counter **I can see all offers** — I can give an offer to anyone, even someone whose e-commerce visibility doesn't include it. Visibility only controls who sees it on e-commerce.

#### When the minimum isn't met

> **[IMPORTANT]** Someone wants an offer product but **won't take the minimum boxes** — e.g., minimum 5, they want 2.
>
> Then **no offer rate** — their profile's normal rate applies.

<!-- -->

> **[DEV NOTE]** **Stock and profit:** the goods still come from the same stock. But since it's sold at the normal rate, **profit is also calculated at the normal rate** — not the offer rate.
>
> The system picks **a lot that leaves profit** (6.2).

<!-- -->

> **[NOTE]** There is no way to accidentally apply the offer rate below the minimum — the system blocks it.

### Two kinds of rate in one invoice

> **[EXAMPLE]** A paikari buyer takes 5 medicines — 2 from offer, 3 normal.
>
> - The 2 offer items → **offer rate**, deducted from stock
> - The other 3 → **their own rate**, not deducted from stock
>
> Both are on the same invoice.

<!-- -->

> **[IMPORTANT]** **Invoice layout order:** the **offer items section comes first**, then the **regular items section**, and at the bottom **both are added together into one grand total**. Each section shows its own subtotal.

### Barcode at the counter

Scanning a box adds it to the open draft at the customer's rate (details in 6.10). Scanning again increases the quantity.

### Working without internet (offline counter)

> **[IMPORTANT]** If the internet goes down, **counter sales must not stop**. The counter keeps working offline and **syncs everything when the internet returns**.

- Offline, staff can still: search medicines, scan barcodes, make retail and paikari drafts, finalize memos and print
- Each offline memo gets a **temporary number**, replaced by the real number on sync
- The screen clearly shows **"Offline — will sync"** and how many sales are waiting

> **[PROBLEM]** While offline, the same last box is sold at the counter and also ordered online.

<!-- -->

> **[SOLUTION]** On sync, the system detects the conflict and alerts me — "Stock went below zero for these items". I decide (supply from elsewhere or tell the online buyer). Offline sales are never lost.

<!-- -->

> **[DEV NOTE]** Keep a local copy of the medicine list, customer rates and the stock snapshot on the counter device. Loss protection and staff discount limits must also work offline using the last synced data.

### Reducing mistakes

> **[PROBLEM]** Similar medicine names; picking the wrong one in a rush.

<!-- -->

> **[SOLUTION]** In the list show **strength and company name**, strength in bold. Warn on similar names.

---

