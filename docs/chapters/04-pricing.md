## 04 — Pricing

> **[IMPORTANT]** **First, clearing up a big misunderstanding.**
>
> I will **not set a separate price for everyone**. The retail / public price is **the same for everyone**.
>
> I set prices by hand **only for wholesalers and paikari buyers** — the people who have tiers.

### Quantity-based discount on Public

Everyone sees the same price, but buying more gives a bigger discount. I set the steps when uploading the product.

> **[EXAMPLE]**
>
> - 1–4 boxes → no discount
> - 5–9 boxes → 5% off
> - 10–19 boxes → 10% off
> - 20+ boxes → 15% off
>
> When the quantity increases, the price drops automatically and the screen shows "You are getting 10% off".

- Different steps per product, as many steps as I want
- If I don't want a discount, I simply don't add steps
- Can be a percentage or a fixed taka amount
- The discount steps **must be shown on the product page**, so the buyer understands that taking 2 more gives a bigger discount

### Tiers — for wholesale and paikari

A tier is a class of customers. Each tier has its own discount rate.

> **[EXAMPLE]**
>
> - Tier A = 10% off
> - Tier B = 15% off
> - Tier C = 20% off

I can create as many tiers as I want and change the rates any time. When a tier's rate changes, the prices for every customer in that tier change together.

From the admin panel I can move any customer's tier **up or down**, and their features/prices then work according to the new tier.

### The four layers of pricing

#### Layer 1 — Tier default
The discount of the tier the customer is in. This is the base.

#### Layer 2 — Company level
One rate applied to **all products of a company at once**.

**Why:** a company can have 200 medicines. Setting them one by one is impossible.

#### Layer 3 — Product-level override

> **[EXAMPLE]** The company's general rate is 20%. But a few medicines have a low margin, so they get 12%. Some have a high margin, so they get 25–30%.
>
> For some I enter a **direct taka amount** instead of a percentage — a 100-taka medicine at 90 taka. **When a taka amount is entered, it is automatically converted and also shown as a percentage.**

#### Layer 4 — Customer-specific manual rate
A price set by hand for one specific customer on one specific product. Highest of all.

### Priority order

> **[IMPORTANT]** **From top to bottom:**
>
> **1. Customer-specific manual rate**
>
> **2. Product-level override**
>
> **3. Company-level rate**
>
> **4. Tier default**
>
> If something exists in a higher layer, the lower one is ignored.

<!-- -->

> **[EXAMPLE]** Mr. Karim is Tier B (15%). He is buying company "K"'s medicine.
>
> **Case 1:** nothing set anywhere → **15%**
>
> **Case 2:** company set to 20% → **20%**
>
> **Case 3:** this medicine set to 12% → **12%**
>
> **Case 4:** manual 25% set for Mr. Karim → **25%**

### When a tier changes

When I move a tier up or down, all prices change automatically.

> **[IMPORTANT]** **One exception:** if a product has a **manual rate** set, it **stays unchanged** when the tier changes.
>
> **Reason:** a manual rate is a special decision. If it were wiped, the agreement would break and I would not even notice.

### Percentage or taka

Both can be entered. The customer has a **toggle** to view prices either way.

> **[DEV NOTE]** The system must calculate both directions. Avoid decimal mess — decide the rounding rules first.

### Order of discounts — all together

> **[IMPORTANT]** Up to four kinds of discount can exist at once. **The order is:**
>
> **1.** The product price is set (tier / offer / manual — whichever applies)
>
> **2.** Quantity-based discount
>
> **3.** Token
>
> **4.** Return credit
>
> **Two discounts are never added together** — whichever is larger applies.

### Complications and solutions

> **[PROBLEM]** A price was entered wrong — 50 instead of 500. Someone bought it.

<!-- -->

> **[SOLUTION]** Warn on abnormally low prices — "This is 90% lower than the previous price, are you sure?"

<!-- -->

> **[PROBLEM]** A zero or negative price was entered.

<!-- -->

> **[SOLUTION]** Below zero is not allowed. Zero shows a warning.

<!-- -->

> **[PROBLEM]** Something was left in the cart and the price went up.

<!-- -->

> **[SOLUTION]** The cart price updates and shows "Price changed".

<!-- -->

> **[PROBLEM]** Opening an old invoice shows today's rate.

<!-- -->

> **[SOLUTION]** The invoice **stores that day's rate and price**. Changing rates later never changes an old memo.

### Tier change log

Who changed whose tier and when — date, staff name, old tier, new tier. Essential for accountability when several staff work.

---

