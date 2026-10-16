## 15 — Multi-Sector

> **[IMPORTANT]** This is **not only a medicine platform**. There will be many kinds of sectors — wood, clothing, electronics, toys, groceries, biscuits, coffee, diapers (Pampers) — **A to Z, anything**.
>
> In one word, it is a **multi-business platform**.

### The most important decision

> **[IMPORTANT]** **If separate code is written for every sector, the work will never end.** 10 sectors = 10 times the work.
>
> So there must be **a system for creating sectors** — I can create new sectors myself, without a developer.

#### When creating a new sector I decide

- **Which fields are needed** — color, size, measurements, weight, expiry, warranty
- **What the unit is** — piece, foot, yard, kg, sack, liter
- Whether **fractional quantities** are allowed (2.5 yards of cloth)
- Whether **pre-order** is needed
- Whether **chat** is needed
- Whether **custom orders** are needed
- **Return rules**
- **Premium threshold** — above how much is premium

Then whatever sector comes to mind in future, there's no need to go to a developer.

### Medicine vs. other sectors

> **[IMPORTANT]** **Medicine is complex, the rest are simple.**
>
> Medicine has generics, MPOs, bonuses, expiry, purchase-price percentages. Other sectors have none of these. They are **like a normal e-commerce website**.

| Item | Medicine | Other sectors |
|---|---|---|
| Generic name | Yes | No |
| MPO sector | Yes | No |
| Bonus (10+2) | Yes | No |
| Expiry management | Yes | No |
| Percentage-based purchase price | Yes | No |
| Profit/loss protection | Yes | No |
| **Color** | No | **Yes** |
| **Size** | No | **Yes** |
| Quantity | Piece/strip/box | Whatever unit fits |

> **[DEV NOTE]** **For the developer:** the complex stock system of Chapter 06 is **only for my own medicine business**. Don't put it into other sectors — basic stock (add, sell, low-stock alert) is enough there.

### The stock they need in other sectors

What sellers can see in their sectors (wood, food, etc.):

- Which items **sell the most** (most-selling items)
- Which **sell the least**
- **What each one cost them** — they can enter it themselves and keep their own accounts
- Stock level, low-stock alert

> **[NOTE]** This is basic, not advanced like medicine. Entering purchase price is optional.

### Two businesses, one person

> **[IMPORTANT]** A seller can run **two business accounts** (e.g., wood and clothing). In their profile each business is shown **separately** — sales, best/worst sellers, stock, returns, commission — so they can see how each business is doing. (See Chapter 03.)

### Color and size

> **[PROBLEM]** One shirt — 5 colors, 5 sizes. That's 25 separate stock items.

<!-- -->

> **[SOLUTION]** Show it all in **one grid** — colors down the side, sizes across the top, numbers in the cells. No need for 25 separate entries.

<!-- -->

> **[PROBLEM]** Red is sold out, blue and green are available.

<!-- -->

> **[SOLUTION]** The whole product is not "out of stock". Only **that color turns grey**. If pre-order is on, that color can be pre-ordered.

<!-- -->

> **[PROBLEM]** Different prices for different color/size.

<!-- -->

> **[SOLUTION]** A separate price can be set per combination; if not, the base price applies. **A separate photo per color** too — choosing a color changes the photo.

### Wood sector specifics

> **[IMPORTANT]** Every wooden item can **have a size** — height, length, width.
>
> **Items already made** — we give the size ourselves.
>
> **Items not made yet** — if pre-ordered within the available options, or made-to-measure as a custom order (Chapter 16), the buyer can give the measurements.

### What each sector needs

| Sector | Special fields |
|---|---|
| **Electronics** | Warranty period, serial number, specifications, original or copy |
| **Cosmetics** | Expiry (mandatory), skin type, ingredients, verification code |
| **Baby items** | Age range, size by weight, expiry |
| **Grocery** | kg/liter/piece, fractional quantity, fresh or packaged, expiry |
| **Stationery & books** | Author, publisher, edition, class, set or single |
| **Hardware** | Piece/sack/foot/ton, brand and grade, separate delivery because heavy |
| **Agriculture** | Crop type, season, how much per bigha, expiry |
| **Car parts** | Model-year, which car it fits, new or reconditioned |
| **Second-hand** | Condition, years used, room for bargaining |
| **Rental** | Per day/week, deposit, return date |

(Full details for each in Chapter 29-B.)

### Returns in other sectors

> **[IMPORTANT]** Wood, clothing and other sectors use **the same Return section and the same return option** as everywhere else — the buyer goes there and returns it the same way.
>
> The difference: **the return rules (days, conditions) are set by that seller**, and **the seller receives the returned goods and handles the refund themselves** (Chapter 14). The return flow and screens are the same.

<!-- -->

> **[NOTE]** Custom (made-to-measure) orders have **no returns** — Chapter 16.

### Complications and solutions

> **[PROBLEM]** One product fits many sectors — something belongs to both grocery and cosmetics.

<!-- -->

> **[SOLUTION]** It can be in several categories, but **there is only one stock**.

<!-- -->

> **[PROBLEM]** Products from two sectors in one cart — medicine and wood.

<!-- -->

> **[SOLUTION]** Daraz-style split — separate packages, separate memos, separate couriers.

<!-- -->

> **[PROBLEM]** Each sector's rules differ — return, delivery, pre-order.

<!-- -->

> **[SOLUTION]** Each sector's own rules in settings, and the buyer can see them **on the product page**.

<!-- -->

> **[PROBLEM]** Someone wants to sell a prohibited item.

<!-- -->

> **[SOLUTION]** I check during approval. A prohibited-category list can be kept too.

---

