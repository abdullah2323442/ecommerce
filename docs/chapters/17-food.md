## 17 — Food Sector

Like Foodpanda. I'll give accounts to some **fast food** shop owners, and they sell their food.

> **[NOTE]** Only fast food — not grocery shops. If a license is needed, I'll get it.

<!-- -->

> **[IMPORTANT]** **My role:** I don't sell food myself. The shop owners sell; I only take a **commission**. I set the commission rate **by hand** — separately for each shop.

### Browsing flow

1. First the **customer's location** is taken
2. Only **restaurants connected** in that location are shown
3. The menu shows only **what's available right now**

### What the shop owner controls

- **Create and edit the menu** — items, photos, descriptions
- **Set prices**
- **What's available now** — on/off any time
- **Shop open or closed**, and opening hours
- **Cooking time per item**
- **Ready-made or needs cooking time**
- **Deliver themselves or use the platform's courier**
- **How much advance is needed**
- **Delivery charge**

### Order steps

1. Buyer orders → **notification to the owner**, buyer sees **"Pending"**
2. Owner confirms. **While confirming, they write how long it may take.** Buyer sees **"Confirmed — cooking"**
3. A **timer** runs on the buyer's screen based on the cooking time
4. **Packing**
5. **Out for delivery**
6. **Delivered**

> **[PROBLEM]** The restaurant never confirms.

<!-- -->

> **[SOLUTION]** Notification to the buyer — **"Your order hasn't been approved yet. Do you want to cancel?"** The buyer decides.

### Two options

| Option | Details |
|---|---|
| **1. Home delivery** | Give an address, or with location on it's picked up automatically |
| **2. I'll eat there** | Order in advance, then go and eat |

**Why the second:** fast food takes time to cook. Ordering ahead means no waiting when you arrive.

With "I'll eat there", **giving a time is optional**. Some **basic time options** are offered from my site; the buyer can pick one if they want.

### Custom food

> **[IMPORTANT]** The buyer can **build their own**. They choose a base — burger, pizza, sandwich, roll. Then add ingredients.

#### Central ingredient list

> **[IMPORTANT]** **I keep a list of all the world's ingredients in advance.**
>
> When a restaurant opens an account, it **ticks from that list** — what it has. **It cannot type new names itself.**

**Problems this solves:**

- One writes "চিজ" (cheese in Bengali script), another "Cheese", another "পনির" (paneer) — they'd become three different things
- A buyer looking for "mozzarella" finds it at every restaurant
- I can see in reports which ingredient sells most
- No misspellings, junk names or irrelevant items get in

> **[NOTE]** Same principle as picking medicines from the medicine database.

#### Ingredient groups
- **Meat** — beef, chicken, mutton
- **Cheese** — cheddar, mozzarella
- **Vegetables** — tomato, lettuce, onion, cucumber
- **Sauces** — mayonnaise, ketchup, barbecue
- **Extras** — egg, bacon, jalapeño

Each has its own price; adding raises the total. The buyer sees the total on screen.

> **[NOTE]** **Each restaurant sets its own prices** — the same cheese costs differently at each shop. The list is mine; the prices are theirs.

#### Save favorite combinations
Someone builds a favorite burger and saves it with a name. Next time, one click.

#### Custom food complications

> **[PROBLEM]** An ingredient ran out.

<!-- -->

> **[SOLUTION]** The restaurant sets **"Sold out today"**; buyers can't select it. It turns back on automatically the next day.

<!-- -->

> **[PROBLEM]** The buyer wants a combination that doesn't work.

<!-- -->

> **[SOLUTION]** The restaurant can set some rules — "This doesn't go with that".

<!-- -->

> **[PROBLEM]** Too many ingredients — 15 things in one burger.

<!-- -->

> **[SOLUTION]** A maximum limit can be set.

<!-- -->

> **[PROBLEM]** Made wrong — the buyer wanted no onion.

<!-- -->

> **[SOLUTION]** Order details shown large on the restaurant screen, and printable for the kitchen.

<!-- -->

> **[PROBLEM]** The restaurant has an ingredient that's not in the list.

<!-- -->

> **[SOLUTION]** The restaurant can **send a request**; I review and add it. Once added, everyone gets it.

<!-- -->

> **[PROBLEM]** The list becomes huge and hard to search.

<!-- -->

> **[SOLUTION]** Grouped — meat, vegetables, cheese, sauce, spices. Plus search.

<!-- -->

> **[PROBLEM]** Custom food takes longer to make.

<!-- -->

> **[SOLUTION]** Extra time is added for custom items, and the buyer is told.

### Delivery charge

> **[IMPORTANT]** Food orders come many times a day. Typing the charge on each is impossible — the food has to go while it's hot.
>
> So **three options**, the restaurant chooses.

| Option | How |
|---|---|
| **1. Area-based** | One price inside their own area, another outside. Simplest. |
| **2. Per kilometer** | Distance measured from the location and calculated automatically (10 km × 5 taka = 50 taka) |
| **3. Entered by hand** | Special cases |

- A **maximum distance** — beyond it, no orders (food gets cold; food is mostly sold nearby)
- A **minimum charge** — "However close, not less than 30 taka"

> **[DEV NOTE]** Kilometers must be calculated by **road distance**, not straight-line. Straight-line is wrong because of rivers and railways.

### Advance payment
- Large orders **require an advance**
- Small orders don't
- **The advance amount is set by hand by the owner or me**

### Memo

> **[IMPORTANT]** The memo is made **in the restaurant's name**. At the bottom, **my e-commerce name in small text**.
>
> For custom food, every ingredient is on its own line with its price.

### Delivery

Each restaurant decides — **its own delivery staff** or **the platform's courier**. Can change any time.

### From my side
- Review and approve restaurant applications
- **Set each one's commission by hand**
- Monitor all orders, **see their sales**
- How much commission has accumulated
- Suspend a restaurant if needed

### Restaurant dashboard
- New order notifications
- Active orders and status update buttons
- Menu management
- Today's and this month's sales
- What they'll receive after commission
- Shop open/closed switch

### Complications and solutions

> **[PROBLEM]** The shop is closed but they forgot to close it in the app; an order came.

<!-- -->

> **[SOLUTION]** Opening hours are set, and it closes automatically by them.

<!-- -->

> **[PROBLEM]** An item is finished but still on the menu.

<!-- -->

> **[SOLUTION]** Per-item **"Sold out today"** toggle, automatically back on the next day.

<!-- -->

> **[PROBLEM]** Two orders to nearby houses.

<!-- -->

> **[SOLUTION]** Shown together on the delivery person's list; the charge can be shared.

<!-- -->

> **[PROBLEM]** The food arrived spoiled.

<!-- -->

> **[SOLUTION]** **Complaint with a photo** — goes to the restaurant and me.

<!-- -->

> **[PROBLEM]** Cancelled after confirmation; cooking had started.

<!-- -->

> **[SOLUTION]** Commission is on complete sales; cancelled → no commission. But once cooking starts, **the advance is not refunded**.

<!-- -->

> **[PROBLEM]** The restaurant closes its account midway.

<!-- -->

> **[SOLUTION]** It can't close until active orders are finished. New orders stop; old ones must be completed.

<!-- -->

> **[PROBLEM]** The buyer gave a wrong address; the delivery person is wandering.

<!-- -->

> **[SOLUTION]** Address and phone verified before confirming. If the food spoils, who's responsible is in the restaurant's policy.

---

