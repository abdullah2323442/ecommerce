# PART 5 — NEEDED EVERYWHERE

---

## 20 — Delivery and Courier

### Automatic location

Today everyone types their address by hand — slow and error-prone.

> **[IMPORTANT]** I want the address fields to **fill in automatically** from the buyer's location.

- "Use my location" button
- The system takes the location and fills the fields — area, police station (thana), district
- Editable (house number, flat)
- Saved once, it appears automatically next time
- Option to drop a pin on a map
- A **"How to get there"** field — "Next to the mosque, red gate"

**Why:** many deliveries fail because of wrong addresses. The delivery person keeps calling to search — time and money wasted.

> **[DEV NOTE]** Ask permission before taking location. If refused, manual entry must still work.

### Layers of delivery charge

> **[IMPORTANT]** The delivery charge can be decided in several ways. So the developer doesn't confuse them, here is the order.

| Order | Which | When it applies |
|---|---|---|
| **1** | **Mandatory charge on the product** | If the seller sets a mandatory charge on the product (in-zone 100, out-zone 150) — **this is the highest** |
| **2** | **Weight-based courier charge** | If weight is given, using the profile's courier rates |
| **3** | **Area-based flat charge** | If there's no weight |

> **[NOTE]** **Paikari is different.** No courier calculation there — I enter it by hand, because by auto/toto the cost differs every time (Chapter 07).

### What is set when uploading a product

Like Daraz, the seller decides:

- **Weight** — optional
- **Whether the delivery charge is mandatory**, and if so how much (every product has a minimum delivery charge — e.g., 150/200/300 taka)
- **Whether COD is allowed**
- **Whether an advance is taken**, and if so how much
- **Free delivery** or not

### Weight-based courier charge

> **[IMPORTANT]** The real courier charge is **based on weight**. So this system is needed — **in every sector/market**.

#### Everyone's own courier list

> **[IMPORTANT]** **Each seller sets their own couriers and charges in their profile.**
>
> **Why separate:** not every courier exists in every area, and charges differ by area. One seller's profile has one rate, my profile another — because our areas differ.

<!-- -->

> **[IMPORTANT]** **Couriers are set only in the profile — not on each product.** That avoids different-courier trouble in one cart.
>
> **Unlimited couriers can be added — no limit.** At checkout the buyer sees them all side by side, each with its price. If a seller has no courier set, the order can still be placed the normal way.

#### Charges are entered by hand

> **[IMPORTANT]** **The seller enters the courier charges by hand.** No courier company API or integration is needed.
>
> If rates change, they update them themselves.

#### What each courier has

This is how Bangladeshi couriers (Pathao, Steadfast, RedX) calculate:

- **Courier name**
- **Zone** — own area / inside district / outside district
- Per zone, **first 1 kg charge**
- Per zone, **each additional kg charge**
- **COD charge** — the percentage the courier takes for collecting cash on delivery

#### Calculation

1. **Total order weight** — each product's weight × quantity
2. The **zone** is decided from the address
3. Charge = **first-kg charge + (remaining kg × additional rate)**
4. If COD, **add the percentage**
5. The buyer **chooses one courier**

> **[EXAMPLE]** A 2 kg product, buying 3 → **6 kg**. Outside the district.
>
> Speed Fast: first 1 kg 120 taka, each additional kg 20 taka
>
> Calculation: 120 + (5 × 20) = **220 taka**
>
> If COD at 1% — on a 3,000-taka product, 30 more = **250 taka**
>
> **At checkout the buyer sees:**
>
> - Speed Fast — 250 taka
> - Pathao — 280 taka
> - Steadfast — 230 taka

<!-- -->

> **[DEV NOTE]** Couriers **round weight up**. Keep the rounding rule in settings too.

#### If no weight is given

> **[IMPORTANT]** If **even one product** in the order has no weight, the **area-based general charge** applies to the whole order (e.g., one price inside the area, another outside).
>
> Half weight-based and half flat would mess up the calculation.

<!-- -->

> **[DEV NOTE]** **Reminding sellers to add weight:**
>
> - A note on upload — "Adding weight gives the correct charge and makes it easier for customers"
> - A dashboard count — "12 products have no weight"
> - The option to set weights in bulk from there

### Delivery charge draft

> **[IMPORTANT]** **What really happens:** the buyer paid a 150-taka advance. But sending cost 200 taka. **50 taka extra.**

1. The buyer's order stays **pending** until it's packed
2. Their memo also stays as a **draft** — not fully confirmed
3. When sending, I see 50 taka more was spent — **I add it to the memo**
4. When it leaves with the courier, the **confirmed memo** goes to them, showing **"Extra delivery charge 50 taka"**
5. At the same time it shows **"Product on the way"**

### Courier in the wholesale market

> **[IMPORTANT]** In the wholesale pharmacy market, courier charges are a bit different.
>
> When sending goods there's an option — **courier charge 50-50, or advance, or a fixed amount**.

- Set when they order or when I send
- Of course **by weight and by carton**
- It stays as a draft; **whatever I finally enter becomes their charge**

### Advance delivery charge

> **[IMPORTANT]** **The problem:** delivering a small product costs 100–150 taka. A big product 3,000–4,000.
>
> If someone orders a big product and **returns it**, I lose 3–4 thousand taka.
>
> **Solution:** some products require the delivery charge upfront.

- "If this product's order is above a set amount (e.g., 5,000 taka), the charge must be paid first"
- I set the threshold and amount
- At checkout the reason is explained clearly
- The product page says so in advance too

### Pickup point

> **[IMPORTANT]** To save the delivery charge, someone can **collect it themselves** from a specific shop.
>
> **But only on products of sellers whose name and location display is on.** For others the option doesn't appear at all — otherwise the buyer wouldn't know where to go.

<!-- -->

> **[PROBLEM]** Goods sit there; nobody comes to collect.

<!-- -->

> **[SOLUTION]** Reminder after set days (e.g., 3 days). Still not collected → order cancelled, 50% rule applies.

### Delivery person's system

For my own people who deliver, a simple list:

- Where to go today
- How much money to collect
- Mark delivered
- **Grouped by area** — go together
- Marking **"Arrived"** sends a notification to the buyer's phone

> **[PROBLEM]** The delivery person collected money but didn't hand it in.

<!-- -->

> **[SOLUTION]** A record of how much money each one holds, reconciled at day end.

### Delivery confirmation (OTP or photo)

> **[IMPORTANT]** When the goods reach the buyer, a **code (OTP) is sent to the buyer's phone**. The buyer tells the delivery person the code; when it's entered, the order is confirmed as delivered.
>
> That moment the sale automatically changes from **draft sale to complete sale** (6.0) — so commission, reports and the return window all start from a proven delivery.

- If the buyer can't receive the code, the delivery person can take a **photo of the handed-over parcel** as proof instead
- Works for my own delivery people, wholesalers' deliveries, and food deliveries
- For courier deliveries without OTP, the buyer can tap **"Received"** in the app; if they don't, it auto-confirms after a set number of days unless they report "Not received"

> **[PROBLEM]** A seller marks "Shipped/Delivered" but the buyer says they never got it.

<!-- -->

> **[SOLUTION]** No OTP and no photo → it is not a complete sale and there is no commission. The proof settles the dispute.

### Rating after delivery

> **[IMPORTANT]** After a delivery is confirmed, the buyer is asked to rate the **delivery and the service** (1–5 stars + optional comment) — separate from the product review.

- The rating is linked to **the delivery person and the staff member** who handled the order (buyers never see staff names)
- I see average ratings per delivery person and staff member — who is doing good work
- Low ratings (e.g., 1–2 stars) notify me immediately

### General delivery
- **Courier integration** — Pathao, Steadfast, RedX (optional; charges are entered by hand)
- **Tracking**
- **Delivery zones** — can be turned off outside a set area

> **[PROBLEM]** The courier rate is 6 months old; the courier raised prices.

<!-- -->

> **[SOLUTION]** Remind after 3 months — "Are the courier rates still correct?" Otherwise every delivery loses money without anyone noticing.

<!-- -->

> **[PROBLEM]** Both a mandatory charge and weight are set on the same product.

<!-- -->

> **[SOLUTION]** **The mandatory charge applies** — it's highest.

---

