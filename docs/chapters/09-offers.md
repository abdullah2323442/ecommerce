## 09 — Offers

> **[IMPORTANT]** **There are two kinds of offer. They must not be confused.**

| Item | Offer Product | Tier Offer |
|---|---|---|
| **What** | An offer on a specific product | The tier's rate changed temporarily |
| **Minimum boxes** | **Required** | **Not required** |
| **Quantity unit** | Boxes only | Pieces/strips/boxes |
| **Who gets it** | Those I show it to | All paikari buyers |

---

### 9.1 — Offer Product

> **[IMPORTANT]** An offer product is **not a separate business or separate stock**.
>
> It's **the same stock** with an "offer" tag on some items. When sold, it is deducted from that same stock.

#### What the offer tag changes

| Item | On offer |
|---|---|
| Quantity | **Boxes only**, minimum boxes stated |
| Memo | No MRP memo — directly at the offer rate |
| Staff verification | Goes on the draft, but **no number needs to be typed** — it comes from stock. Marked with a different color / "OFFER" label on the staff list. |

#### Two ways to put something on offer

| Way | How |
|---|---|
| **By hand** | I put any product on offer myself |
| **By notification** | I set a threshold (e.g., 20%). When goods bought above that discount come in, the system **asks** — "This is above 20%, put on offer?" It goes only if I approve. |

> **[NOTE]** **Nothing goes on offer automatically** — my approval is always needed. So there's no risk of an offer worse than the tier rate.

- I set the **minimum profit margin** by hand (e.g., 3%)
- The system suggests the selling rate from that

#### Only the higher-percentage lot goes on offer

> **[IMPORTANT]** If one medicine has two lots, **only the lot bought at the higher discount** goes on offer.

<!-- -->

> **[EXAMPLE]** 10 boxes bought at 14%, 10 boxes bought at 25%. Total 20.
>
> The offer shows **only 10 boxes** — the one bought at 25%.
>
> The 14% lot does not go on offer; it stays at the normal price.

#### Visibility and stock display

- For each offer product I decide who sees it — **public / paikari / wholesale**, each at its own price
- Stock in two modes — **exact number** ("65 boxes available") or just **"In stock"**

> **[NOTE]** **My policy:** stock is usually limited. Seeing exact numbers, paikari buyers would think stock is low and hesitate. So in most cases I'll show **only "In stock"**.

#### Cannot order more than the stock

> **[IMPORTANT]** An offer order **can never exceed the stock that exists**. If 2 boxes are in stock, nobody can order 3 — the quantity field stops at the available amount (even when only "In stock" is displayed, the limit is enforced in the background).

#### Quantity-based discount

> **[EXAMPLE]**
>
> - Minimum 5 boxes
> - 1% extra at 6 boxes
> - More as quantity rises

#### Weight and delivery

Offer products also have a **weight field** (optional). Delivery charge follows Chapter 20.

> **[IMPORTANT]** **Minimum delivery charge:** every product has a fixed delivery charge set — e.g., **150, 200 or 300 taka**.
>
> Weight-based calculation is needed **only for heavy goods** and is optional. But **every product always has a delivery charge set**.

<!-- -->

> **[NOTE]** Paikari delivery has no courier calculation — there I enter the charge by hand.

#### Where it shows

> **[IMPORTANT]** **Search pop-up:** when someone searches a medicine, **the normal one appears on top and the offer pops up below** (by generic too — e.g., searching any calcium shows my calcium offer). They can order straight from there.

- In normal browsing it also shows in an offer section with a clear "Offer" tag
- **Difference:** the normal one in any quantity (piece/strip/box). The offer one with minimum boxes, but cheaper. Same stock.

#### Alert before the offer stock runs out

> **[IMPORTANT]** **Before** the offer stock finishes, **I get an alert** (e.g., "Only 2 boxes left on this offer") — so I can bring in new stock in time.
>
> If I bring new stock **within the same month**, it goes onto the bonus/offer rate automatically.

<!-- -->

> **[DEV NOTE]** Keep the "alert at X boxes left" threshold in settings.

#### When stock runs out

> **[IMPORTANT]** When the offer stock runs out it stays in the listing for **1 to 1.5 hours** — black-and-white/grey, saying **"Was on offer a moment ago — stock out"**.
>
> During that time it **cannot be added to cart or ordered**; only the information is visible.
>
> **Why:** paikari buyers learn they have to come quickly when an offer appears. Next offer, I get a faster response. If they didn't buy while it was in stock, there's nothing I can do.

<!-- -->

> **[NOTE]** **Offer ending ≠ medicine ending.** The medicine may still be in the shop; only the offer lot ran out.

If a draft is cancelled and goods return to stock, the listing **becomes normal again**.

---

### 9.2 — Tier Offer

> **[IMPORTANT]** When a company gives me an offer or bonus, those go into a **separate list**. I can pass the benefit on to paikari buyers.

#### What the offer list shows

- Which product has an offer running
- Including bonus, **the percentage it was bought at** (directly the %, not "10+2")
- I decide **what percentage to sell at**

#### One click to the paikari tier

1. Set the selling percentage
2. Click **"Upload to paikari tier"**
3. Instantly **all paikari buyers** get that rate

> **[EXAMPLE]** A product was bought at 25%. I upload it to the paikari tier at 20%. Instantly that product becomes **20%** in the paikari tier.

#### Offer duration

- There is a **field** for how long it runs
- **At month end the offer automatically becomes non-offer**
- After the month, **the system asks** — keep it on or turn it off (I say on → on; off → off)
- **When stock runs out, it stops immediately** (returns to normal)
- New stock entered within that month **automatically goes back to the offer rate**

So the offer is **tied to the month**, not to the stock. It is only for that month.

#### Relationship with manual rates

> **[IMPORTANT]** If a customer has a **special manual rate**, the offer doesn't touch them. They get their own rate — because manual rates are on top (Chapter 04). (E.g., if a customer has 22% set specially, they get 22%.)

### Offer complications and solutions

> **[PROBLEM]** The offer period ended, stock is left.

<!-- -->

> **[SOLUTION]** It returns to normal stock and sells at the normal rate — even more profit.

<!-- -->

> **[PROBLEM]** A return came in and switched the offer back on.

<!-- -->

> **[SOLUTION]** It switches on, but **notifies me** — running an offer with 2 boxes may be pointless. I can keep it off.

<!-- -->

> **[PROBLEM]** The month ended, but an offer is sitting in a cart.

<!-- -->

> **[SOLUTION]** The cart price changes and shows "This offer has ended". If it's already on a draft, the old rate stays.

<!-- -->

> **[PROBLEM]** A customer without visibility asks for the offer.

<!-- -->

> **[SOLUTION]** At the counter **I see every offer** — I can give it to anyone.

<!-- -->

> **[PROBLEM]** After launching the offer, the purchase price turned out to be wrong.

<!-- -->

> **[SOLUTION]** The purchase price can be corrected (admin). The system then shows **"This offer is now at a loss"**. Profit/loss for items already sold is recalculated.

---

