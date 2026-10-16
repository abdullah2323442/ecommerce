# PART 3 — MEDICINE BUSINESS

---

## 06 — Stock System

> **[IMPORTANT]** **This chapter is synced with PharmaTrack.**
>
> This is not just an e-commerce feature — it is the **software that runs my whole pharmacy**.
>
> **And every feature here is only for my own medicine business.** Ordinary wholesalers get none of this — theirs is much simpler, described separately at the end (6.12).

### The reality of a pharmacy — why it is designed this way

A pharmacy is not like other businesses. Goods come in today, go out tomorrow, come in again. Goods arrive from many places in one day — from the company, through an MPO, from an outside shop.

When the shop is crowded you have to make memos, type, and manage stock all at once. So the system must be **as fast and simple as possible**.

That is why the rules of foreign pharmacy software do not fit here. They have the manpower to enter batch and expiry on every invoice — we don't. In Bangladesh, companies come and go every day, goods come from outside, and every source has a different rate.

---

### 6.0 — Two States of a Sale: Draft Sale and Complete Sale

> **[IMPORTANT]** This is a **foundational concept** — it works across the whole system. Understand it first.
>
> **Draft sale** — the order is placed, but the buyer has **not yet received** the product. The sale is not complete.
>
> **Complete sale** — the buyer has **received** the product. Only then is the sale final.

#### Where these two states have an effect

| Where | What happens |
|---|---|
| **Stock** | In draft state the goods are **held**, not finally deducted. If the buyer does not receive them, they return to stock. |
| **Commission** | Commission is calculated **only on complete sales**, not on drafts |
| **Reports** | Sales figures count **only complete sales** |
| **Return window** | The 2–3 day count starts **from the day of receipt** — not from the order day |

> **[EXAMPLE]** A paikari buyer orders 10 boxes. The goods are put in a draft and moved out of stock.
>
> But he hasn't received them yet — this is a **draft sale**. It is not counted as a sale in reports.
>
> Three days later the goods arrive and he receives them — now it is a **complete sale**. It is added to reports, commission is counted, and the 2–3 day return window starts today.

<!-- -->

> **[NOTE]** Also: if a product is never received, it was never fully sold. That is why a guest who never received a product has nothing to "return".

<!-- -->

> **[NOTE]** How a sale becomes complete: by the buyer's **delivery OTP / photo proof / "Received" tap** (Chapter 20), or by collection at the counter.

<!-- -->

> **[DEV NOTE]** Store these two states **separately** in the database. When calculating reports and commission, count only transactions in the "complete" state.

---

### 6.0-A — The Word "Draft" Means Five Different Things

> **[IMPORTANT]** **Developer, read this very carefully.**
>
> In this document the word "draft" is used for **five completely different things**. In Bengali they all use one word, but they do different jobs.
>
> **Do not merge them. Each needs its own mechanism.**

| No. | Name | What it is |
|---|---|---|
| **1** | **Draft sale** | The **state** of a sale — goods have not yet reached the buyer |
| **2** | **Draft invoice** | An **open bill** at the counter — goods are added throughout the day |
| **3** | **Stock entry draft** | A purchase-invoice entry **saved when stopped halfway** |
| **4** | **Delivery charge draft** | A memo **held open** so an extra delivery charge can be added later |
| **5** | **Pre-order draft memo** | Editable until I enter **how much I actually received** from the company |

#### Each one in detail

> **[EXAMPLE]** **1. Draft sale** (6.0)
>
> This is not a form or a screen — it is a **state** (status).
>
> **Where:** on every sale record
>
> **What it does:** decides whether the sale counts in reports and commission
>
> **When it changes:** when the buyer receives the goods it becomes "complete sale"
>
> **In the database:** a status field on the sales table

<!-- -->

> **[EXAMPLE]** **2. Draft invoice** (6.6)
>
> This is a **full screen** — staff work here at the counter.
>
> **Where:** in counter sale and paikari fulfillment
>
> **What it does:** goods are added, stock is deducted, at the end a memo is made
>
> **When it ends:** when the money is taken and the invoice is finalized
>
> **In the database:** a separate table — drafts, their items, who opened it, when

<!-- -->

> **[EXAMPLE]** **3. Stock entry draft** (6.8)
>
> I'm entering a 50-medicine purchase invoice, 30 are done, a customer arrives. I'll finish the rest later.
>
> **Where:** on the stock entry screen
>
> **What it does:** prevents an incomplete entry from being lost
>
> **When it ends:** when the whole invoice is entered and saved
>
> **Important:** this draft **adds nothing to stock** — it only holds the typed information

<!-- -->

> **[EXAMPLE]** **4. Delivery charge draft** (Chapter 20)
>
> The buyer paid 150 taka, but sending cost 200.
>
> **Where:** on the online order memo
>
> **What it does:** keeps the memo from being finalized so the extra 50 can be added
>
> **When it ends:** when the parcel is handed to the courier
>
> **This is not like No. 2** — no goods are added here, only a charge

<!-- -->

> **[EXAMPLE]** **5. Pre-order draft memo** (Chapter 21)
>
> I asked the company for 10 boxes, 6 arrived.
>
> **Where:** on the pre-order memo
>
> **What it does:** keeps the memo editable until I enter how much was received
>
> **When it ends:** when I press "Receive"
>
> **Special:** the buyer can see this live too

#### How they work together

> **[IMPORTANT]** **Two drafts can run at the same time** on one paikari order:
>
> - Order arrives → a **draft invoice** (2) opens, goods are added, stock is deducted
> - At the same time the sale's state is **draft sale** (1)
> - Memo is finalized → the draft invoice ends
> - But the sale is still a **draft sale**, because the goods have not arrived
> - Buyer receives → **complete sale**
>
> **So No. 2 can end while No. 1 continues.** They are two different things.

<!-- -->

> **[DEV NOTE]** **Naming suggestion:** in code, don't use one word "draft" — give separate names:
>
> 1. **pending_delivery** (draft sale)
> 2. **open_invoice** (draft invoice)
> 3. **incomplete_stock_entry**
> 4. **charge_pending_memo**
> 5. **preorder_draft_memo**
>
> With separate names, there is no room for mistakes.

---

### 6.1 — Purchase Price: The "Source" (Medium) System

> **[IMPORTANT]** Instead of typing a taka amount every time, **choosing the source** produces the purchase price.

#### Creating sources

- I can create **as many sources as I want**
- For example: through the company, through an MPO, through an outside shop
- Each has a **default percentage**
- This percentage means **how much below MRP it was bought**

#### Rates in three layers

| Layer | What happens |
|---|---|
| **1. Source** | The default percentage |
| **2. Company** | A rate set on a company applies to all its products |
| **3. Product** | A separate rate typed on a specific product, overriding the ones above |

> **[EXAMPLE]** I set 20% on Beacon → every Beacon product is 20%.
>
> But on a few specific medicines I can type 25% or 30% by hand.

#### Stepwise (cascading) discount calculation

> **[IMPORTANT]** The company gives a discount, and the MPO gives an extra discount from his own pocket or to meet his target. These two **must not be added** — they are deducted step by step.

<!-- -->

> **[EXAMPLE]** MRP 100 taka
>
> **Step 1:** remove the company's 12% → 100 − 12 = **88 taka**
>
> **Step 2:** from that 88 remove the MPO's 10% → 88 − 8.8 = **79.2 taka**
>
> Adding them would give 22%, but it is actually **20.8%**. This difference is what matters.

- **Order:** company discount first, then the MPO's
- **I enter the MPO's percentage by hand** — the software cannot detect it, because the MPO gives it personally and it varies each time
- At the end the system shows **the total percentage it was bought at**

#### True purchase price including bonus

> **[IMPORTANT]** The bonus is not counted separately. The system calculates **how much each box actually cost**.

<!-- -->

> **[EXAMPLE]** 10+2 offer, each box at 79.2 taka:
>
> - Paid in total: 79.2 × 10 = **792 taka**
> - Received: **12 boxes**
> - Each box actually: 792 ÷ 12 = **66 taka**
> - With MRP 100 → **bought at 34%**
>
> The system shows this 34% directly — not "10+2" and then a calculation.

<!-- -->

> **[DEV NOTE]** The bonus option has to be **turned on manually** — there isn't always a bonus. Bonuses vary each time, so I enter them myself.

#### Set up a medicine once, then only stock

> **[IMPORTANT]** A medicine **does not need to be added again and again.** Name, generic, company, MRP — entered once.
>
> Next time goods arrive, only a **new stock entry** (source + percentage + quantity) is added.

#### Purchase price is locked in taka

> **[IMPORTANT]** Once bought, the purchase price is **locked as a taka amount** and never changes.

<!-- -->

> **[EXAMPLE]**
>
> Old stock: bought at MRP 100 for **80 taka**
>
> MRP rises to 120
>
> New stock: bought at MRP 120 for **88 taka**
>
> **Sales use the new MRP of 120** — for all of them; two MRPs are not used. (Old boxes with the old printed MRP we handle ourselves, or we hand out the medicine rather than the box.)
>
> But profit differs: old goods 120 − 80 = more profit. New goods 120 − 88 = less profit.

---

### 6.2 — Profit/Loss Protection

> **[IMPORTANT]** **The most important rule: a loss is blocked at the moment a rate is set, not at the moment of sale.**

<!-- -->

> **[EXAMPLE]** A medicine was bought at 10%. I try to give a 15% offer on it.
>
> The system says right then — **"This was bought at 10%, giving 15% will cause a loss."**
>
> So that rate is never activated. As a result, the situation "item in stock but can't be sold" is never created. The customer never faces any trouble — they buy whatever rate they see; the check is on my side.

#### Where the check happens

- When setting the regular e-commerce selling rate
- When setting an offer rate
- When setting a manual rate for a specific customer
- When setting a percentage on an invoice
- **Also when setting the end discount on a retail sale**

#### Staff cannot sell at a loss

> **[IMPORTANT]** **Staff can never sell at a loss.** Only the admin can.
>
> If staff try, I get a **notification**.

<!-- -->

> **[PROBLEM]** On a loss, the staff member is stuck while the customer waits.

<!-- -->

> **[SOLUTION]** The staff member sends **"Need admin approval"**, and my phone gets a notification instantly. One tap — yes or no.
>
> If a set time passes, the request is cancelled automatically and staff sell at the normal rate. Nothing stays hanging.

#### Loss inside an invoice

> **[EXAMPLE]** I set 10% on an invoice. Of 20 medicines, 3 would be at a loss.
>
> The whole discount is not blocked. The system shows **these 3 are at a loss** and asks for a separate lower percentage on them.
>
> The other 17 get the invoice's 10%.

#### Admin can force it

I can sell at a loss if I want. For example, when expiry is close, it is better to let it go even at a loss.

> **[NOTE]** Sales made this way **are counted as a loss in reports** — otherwise the month-end profit would be wrong.

#### Which lot is deducted

> **[IMPORTANT]** Stock is not kept chronologically (not FIFO) — it is kept **by percentage group**. At the time of sale the system picks a lot **where there is profit**.

<!-- -->

> **[EXAMPLE]** 10 boxes bought at 14%, 20 boxes bought at 20%.
>
> - Sold at 12% → from the 14% lot
> - Sold at 25% → from the 20% lot
>
> In one word — so that there is no loss.

<!-- -->

> **[EXAMPLE]** **How profit is counted:** bought at 20%, sold at 18% → the 18% sale is counted, no loss. Bought at 15%, sold at 12% → the 12% sale is counted. No loss is recorded because each sale is matched to a lot that still leaves profit.

#### Staff discount limit

> **[IMPORTANT]** Loss protection alone isn't enough. A staff member could still give a big discount that **cuts my profit** without causing a loss.
>
> So each staff role has a **maximum discount** it can give by itself — e.g., **up to 5%** on the retail end discount or on an invoice.

- Above the limit → the staff member sends **"Need admin approval"** (same flow as loss approval: one tap yes/no, auto-cancel after a set time)
- The limit is set **per role** (and can be set per staff member) in settings
- Every discount above the limit, and who approved it, is recorded in the activity log

> **[EXAMPLE]** Staff limit is 5%. A customer asks for 8% on a retail bill. The screen stops at 5% and shows "8% needs admin approval". I get a notification and tap yes or no.

---

### 6.3 — Expiry Management: The Real Trick

> **[IMPORTANT]** **Regularly moving medicines need no expiry worry at all.** Goods come in daily and sell daily — there is no staff to enter a date on each one, and no need.
>
> **The real risk is only in medicine that is sitting still.**

#### Caught in two ways

**1. Completely stagnant** — for a set time (6 months / 1 year) no stock came in and nothing was sold.

**2. Months of stock** — it moves, but very slowly.

> **[EXAMPLE]** 15 boxes in stock, 1 box sold per month → **15 months of stock**.
>
> Because there is movement it won't appear on the "stagnant" list. But within 15 months it can expire.
>
> So anything over 12 months also goes to the check-list. The threshold can be changed in settings.

<!-- -->

> **[DEV NOTE]** No extra entry is needed for this — it uses the stock and sales data that already exist.

#### Two outcomes after checking

| Outcome | What happens |
|---|---|
| **Expired** | Moves to the expired section and is **removed from stock** |
| **Checked** | Still has time. Stays normal and won't keep asking to be checked. |

> **[DEV NOTE]** When marking "Checked", there is a field to enter the expiry date. If a date is entered, the system reminds **at the right time**, not by the six-month rule.

<!-- -->

> **[PROBLEM]** The expiry check-list keeps growing while I'm busy.

<!-- -->

> **[SOLUTION]** Show the money at risk at the top — **"Goods worth 42,000 taka need checking"**. Seeing a taka amount makes the importance obvious.

---

#### Near-expiry → offer suggestion

> **[IMPORTANT]** When a checked item's expiry date is **close** (e.g., 3 months left — configurable), the system itself suggests: **"Put this on offer so it sells before it expires."**
>
> It shows the lowest offer rate that still avoids a loss. If even that won't sell it in time, it suggests returning it to the company before the company's return deadline (6.4).

- One tap sends it to the offer flow (Chapter 09) — I still approve the rate
- Works only for items whose expiry date was entered when checking (6.3)

---


### 6.4 — Expired Section and Returning to the Company

> **[IMPORTANT]** This is where real money is saved. Most companies take goods back before expiry. If not returned in time, the whole amount is a loss.

- **Sorted by company**
- Details for each — how many boxes, how much money
- **Select all** and mark together
- **"Returned to company representative"** marker
- **A PDF is generated** — how much goods were given to whom

> **[NOTE]** **To avoid naming confusion:** the e-commerce "MPO sector" (Chapter 10) and the "company representative" here are different things. The e-commerce MPO is a secret supplier account. The person who comes to the shop to take back expired goods is different.

<!-- -->

> **[PROBLEM]** The company didn't take it back — time passed.

<!-- -->

> **[SOLUTION]** Three states — **returned**, **not taken back**, **pending**. The money for ones not taken back is **counted as a loss**. You can see how much is wasted per year.

---

### 6.5 — When Stock Is Deducted

> **[IMPORTANT]** **Core rule: whatever is in the system is deducted when sold. Whatever is not in the system — there is nothing to deduct.**

#### Current state — in one line

> **[IMPORTANT]** **Right now only offer products are deducted from stock. Paikari and retail are not.**

| Which sale | Deducted from stock? |
|---|---|
| **Order from the offer** | **Yes** |
| The same medicine ordered normally | No |
| Normal paikari (blind order) | No |
| Retail sale | No |

> **[EXAMPLE]** Same medicine — Napa. It is on offer and also normal.
>
> Someone orders **from the offer** → stock is deducted
>
> Someone orders **normally** → not deducted
>
> Because normal medicine stock is not in the system.

<!-- -->

> **[IMPORTANT]** **For now, old stock is entered manually.** When a paikari buyer orders, I check and type how many there are.

#### The future switch — "Master Stock Sync"

> **[IMPORTANT]** **Developer, remember this switch by its own name — "Master Stock Sync".**
>
> There are many other "toggles" in this document (name display, sales display, COD, "sold out today"). Those are **simple on/off switches** — each turns one small thing on or off.
>
> **But this master switch is different.** It **changes the behavior of the whole system.** Confusing it with the others means building the wrong thing in the wrong place.

##### What changes when this switch is turned on

| Item | Now (off) | Later (on) |
|---|---|---|
| **Retail sale** | Stock not deducted | **Stock deducted** |
| **Paikari sale** | Stock not deducted | **Stock deducted** |
| **Offer** | Stock deducted | Stock deducted (no change) |
| **Staff verification** | Go to the store room and type the number | **Number shown on screen**, just click |
| **Profit/loss protection** | Works only on offers | **On every sale** |
| **Expiry management** | Only on goods entered in stock | **On the whole pharmacy's goods** |
| **Monthly ranking** | Incomplete | **Complete** |
| **Cash reconciliation** | Limited | **Whole day** |

> **[EXAMPLE]** **The day I turn it on:**
>
> I will close the small shop and enter the whole large pharmacy's goods into this system. Then go to settings and turn this switch on.
>
> After that, if 4 boxes go out retail, the offer will also show 6 boxes left. **One stock, two doors.**

<!-- -->

> **[DEV NOTE]** **Technical guidance:**
>
> 1. This switch is **one global setting** — not per product
> 2. The stock-deduction code for retail and paikari **must be written now**, just wrapped in this switch's condition
> 3. Warning before turning it on — "Turning this on will start deducting every sale from stock. Make sure all goods are entered in the system first."
> 4. Keep the option to turn it off again after turning it on — in case something goes wrong

##### The other toggles — these are ordinary

| Toggle | What it does |
|---|---|
| Name display toggle | Show the seller's real name or not (per seller) |
| Sales display toggle | Whether a customer sees their own monthly total (per customer) |
| COD toggle | Whether this product allows pay-on-delivery (per product) |
| "Sold out today" toggle | Whether a food item is available today (per item) |
| Chat toggle | Whether this sector has chat (per sector) |
| Percentage/taka toggle | How prices are displayed (per customer) |

> **[NOTE]** These are all **small, independent switches**. Changing one does not affect another. They have nothing to do with Master Stock Sync.

#### What works how much right now

| Feature | Now |
|---|---|
| Stock deduction | Offers only |
| **Profit/loss protection** | Offers only |
| Expiry management | Only goods entered in stock |
| Monthly ranking | Only goods entered in stock |
| Cash reconciliation | Limited |

#### The moment of deduction

> **[IMPORTANT]** Stock is deducted **the moment it is added to the draft** — after the medicine is searched and the quantity and rate are entered. **Not when the memo is confirmed, and not when it is merely searched.**

---

### 6.6 — Draft Invoice

> **[IMPORTANT]** A paikari buyer takes 2 items, an hour later 2 more, then more later. Each time I go into his account and add them. The invoice stays **open**. At the end of the day he settles and pays, and then it is finalized.

<!-- -->

> **[EXAMPLE]** **Why stock is deducted the moment an item is added:**
>
> When I put goods on the draft, I'm taking them off the shelf and handing them to the customer. **They are practically sold.**
>
> Goods shown to one person cannot be shown to another. A draft is "almost final" — the goods are packed, he will just pay later and take them.

#### Removing returns it

- Removing from the draft or cancelling it **returns it to stock instantly**
- But everything **stays in history** and can be brought back
- When it returns to stock, the offer listing also becomes normal again

#### Draft rules

- The same shop can have **several drafts** open
- Each draft has its **own invoice number** — Invoice 1, 2, 3
- Each can have its own rates (e.g., one buyer brings three prescriptions — three drafts, each at its own rate)
- At the end **everything adds up into one memo**, showing which items came from which draft
- Option to **merge** two drafts
- **Unlimited drafts for retail** — Customer 1, Customer 2, a name can be given
- If two staff work on the same draft, **first click wins**

> **[EXAMPLE]** I'm looking at one prescription, another customer arrives mid-way. I leave the first in draft and take the second. Later I go back to the first.

#### Whose rate and profit

> **[IMPORTANT]** The rate on **the day the goods were added to the draft** applies. Even if the price changes in between, the draft's rate doesn't change. (An offer item in a draft that passes month-end keeps its draft-day offer rate.)
>
> Profit is calculated using **the purchase price of the lot the goods came from**, not the price at confirmation time.

### Draft complications and solutions

> **[PROBLEM]** Retail drafts pile up and goods get stuck. 30 drafts a day, staff forget to remove them.

<!-- -->

> **[SOLUTION]** Show **time** beside each draft — "Open for 45 minutes". The system counts it automatically; nobody enters it. After an hour the color changes. A count in the screen corner — "7 drafts open".

<!-- -->

> **[PROBLEM]** A draft sits for 1–2 days.

<!-- -->

> **[SOLUTION]** A notification with two buttons — **"Return to stock"** and **"Keep"**. If kept, it asks again after 2 days.

<!-- -->

> **[PROBLEM]** A staff member opened a draft and went home.

<!-- -->

> **[SOLUTION]** At day end, show it **by staff name** — "Robiul has 3 drafts open".
>
> **Anyone can enter any draft.** The draft shows "Robiul opened it / Robiul is working" — that alone keeps others out.

<!-- -->

> **[PROBLEM]** Staff put goods into the wrong customer's draft.

<!-- -->

> **[SOLUTION]** In the item's **three-dot menu** — "Move to another draft". Normally hidden; can be brought out when needed.
>
> Moving does not touch stock; only **the rate changes to the new customer's rate**.

<!-- -->

> **[PROBLEM]** The same medicine is added twice to a draft.

<!-- -->

> **[SOLUTION]** Warn — "This is already in this draft". Choose to merge or keep a separate line.

<!-- -->

> **[PROBLEM]** The same customer has an online draft and a counter draft.

<!-- -->

> **[SOLUTION]** Searching the customer shows "This shop has 2 drafts open". They can be merged.

<!-- -->

> **[PROBLEM]** Internet drops and the draft is lost.

<!-- -->

> **[SOLUTION]** Every item is saved the moment it is added, not all at once at the end. When the internet returns, continue from where you were.

<!-- -->

> **[PROBLEM]** A prescription medicine isn't in stock; it must be brought from outside.

<!-- -->

> **[SOLUTION]** Mark it in the draft as **"Bring from outside"**. The price goes on the invoice, but stock is not deducted. At month end it goes to the "asked for but not available" list.

<!-- -->

> **[PROBLEM]** A mistake is found after the invoice is finalized.

<!-- -->

> **[SOLUTION]** **"Invoice correction"** — admin only. Removing a wrong item returns it to stock; adding a new one deducts. The old memo is cancelled and a new memo number is created; both stay in history.

<!-- -->

> **[PROBLEM]** The whole invoice was made under the wrong customer's name.

<!-- -->

> **[SOLUTION]** **Change customer** option (admin). The new customer's rate is applied; both customers' histories keep a record.

<!-- -->

> **[PROBLEM]** Closing the shop with drafts still open.

<!-- -->

> **[SOLUTION]** **"End of day"** button. Pressing it shows everything unresolved — open drafts, unpaid money, stock requests — before closing.

---

### 6.7 — Stock Correction

> **[IMPORTANT]** The system number and the real number never match perfectly. Boxes break, get lost, get misplaced, entries have mistakes.

- Correction only **from the admin account**
- **Staff cannot** — otherwise someone could remove goods and fix the number to hide it
- A correction **only fixes the number; it is not counted as a loss** — because it was an entry mistake
- A **record** of who changed what and when
- A field for the reason — broken, lost, stolen

> **[PROBLEM]** Staff find a mismatch but can't correct it. I'm not in the shop.

<!-- -->

> **[SOLUTION]** Staff have a **"Report mismatch"** button. They can't correct, but they can report — "Found 50 here, system says 60". I get a notification.
>
> Work doesn't stop, and the mismatch isn't lost.

<!-- -->

> **[PROBLEM]** Two staff report two different numbers for the same medicine.

<!-- -->

> **[SOLUTION]** The request shows who, when, how many. If one medicine has two requests, warn me.

---

### 6.7-A — Stock Count Mode (Physical Stock-Taking)

> **[IMPORTANT]** Correcting items one by one isn't enough. From time to time the whole shop must be counted and matched with the system.
>
> **Stock count mode** lets staff count **shelf by shelf / company by company** and compare with the system in one go.

1. Admin starts a count session — whole shop, or one company, or one shelf
2. Staff open the list and enter the real count for each item (or scan barcodes)
3. The system shows **differences** side by side — system number vs counted number, and the money value of the difference
4. **Only the admin approves** the final adjustment (staff can count but cannot correct — same rule as 6.7)
5. A **count report** is saved — date, who counted, total difference in taka

> **[DEV NOTE]** Sales can continue during a count. Items sold after counting started must be adjusted for, so the count isn't wrong. A count session can be paused and continued later.

<!-- -->

> **[NOTE]** Differences approved here are recorded as count adjustments with a reason (broken, lost, stolen, entry mistake) — not mixed into normal sales.

---

### 6.8 — Stock Entry Complications

> **[PROBLEM]** Many small lots of one medicine — 2 boxes at 80 taka, 3 at 88.

<!-- -->

> **[SOLUTION]** Stock shows the total; inside, the lots are separate. Warn on very old, very small lots.

<!-- -->

> **[PROBLEM]** Two invoices from the same company on the same day (different prices).

<!-- -->

> **[SOLUTION]** Two separate lots. A field for the invoice time or number so it can be found later.

<!-- -->

> **[PROBLEM]** Forgot to enter the percentage.

<!-- -->

> **[SOLUTION]** The source's default percentage fills in automatically. If it is still empty, **the entry will not save** — without a purchase price, profit/loss doesn't work.

<!-- -->

> **[PROBLEM]** Invoice entry stopped halfway.

<!-- -->

> **[SOLUTION]** The stock entry is also **saved as a draft**.

<!-- -->

> **[PROBLEM]** Adding 50 medicines of one company means typing the percentage 50 times.

<!-- -->

> **[SOLUTION]** Shown as a list with quantity fields beside each. **Set the percentage once at the top** and it applies to all; change individually if needed.

<!-- -->

> **[PROBLEM]** A medicine's unit is wrong — entered as 10 strips per box, actually 12.

<!-- -->

> **[SOLUTION]** Changing a unit warns — "This will change old calculations". Record of who changed it and when.

<!-- -->

> **[PROBLEM]** It's in stock but nobody knows which shelf.

<!-- -->

> **[SOLUTION]** Optional **"Shelf number"** field — useful in a large shop.

<!-- -->

> **[PROBLEM]** Two staff entering stock at the same time.

<!-- -->

> **[SOLUTION]** No problem — two separate lots. But warn if **the same invoice** is being entered twice.

<!-- -->

> **[NOTE]** Medicines are always picked from the database (search, or open a company and pick from its list). Nobody types a medicine name, so duplicate names cannot be created.

---

### 6.9 — Reports and Analysis

#### Stock

- **Total stock value**
- How each stock item is moving
- **Low-stock alert**
- **What finished today** — useful when ordering in the morning

#### Two-way reorder suggestion

> **[IMPORTANT]** **Buy more:** if it keeps running out — "10 boxes isn't enough, take 15"
>
> **Buy less:** if too much was bought and it's sitting — "Take less of this, it isn't moving"

#### Monthly ranking report

> **[IMPORTANT]** One complete monthly report with **A to Z everything**.

- **How each medicine is moving** — most in demand to least
- **How many boxes are needed per day**
- **What the stock level should be**
- **How many boxes should be ordered**
- **Which company's products move most**
- **Customer ranking** — who bought how much
- Which customer buys which company's products most
- Which medicine went most in which month — month-to-month comparison
- Fast-moving and slow-moving lists
- **Which medicine gives the most profit** — a profit ranking, not just sales
- **Seasonal forecast** — "Last year this sold a lot at this time"

#### Profit and business direction

- **Profit**, **average sale**, **is business going up or down**
- Comparison with the same month last year

#### Shop expenses and real (net) profit

> **[IMPORTANT]** The profit shown so far is **before expenses**. To see the **real profit**, I can enter the shop's expenses — rent, staff salaries, electricity, internet, transport, packaging, other.
>
> At month end the report shows: **gross profit − expenses = net profit**.

- Expense categories can be added by me; each entry has date, amount, category, note
- Recurring expenses (rent, salary) can be set once to repeat monthly
- Only admin and sub-admin can see or enter expenses
- Monthly and yearly net-profit trend

> **[EXAMPLE]** Gross profit this month 85,000 taka. Rent 15,000 + salaries 30,000 + electricity 4,000 + other 3,000 = 52,000. **Net profit 33,000 taka.**

#### Per customer

- Their total sales and **my profit from them**
- **Which company's products they buy most**
- Returns
- **Inactive customers** — who hasn't come for how long, and how much they used to buy
- In the future — how much they owe (Future)

#### End-of-day cash reconciliation

The system says "Today's sales: X taka". I count the drawer and enter it, and it shows whether it matches. A mismatch is **caught the same day**. Only admin/sub-admin do this.

> **[NOTE]** Credit sales are kept in a paper ledger for now, so this is fine. When a ledger book is built in the future, the invoice's **"Paid / Due"** mark will be used.

#### Staff accountability

Every memo carries **the staff member's name**. If there's a mistake or a complaint, you know whose work it was.

#### Asked for but not available

> **[IMPORTANT]** When someone asks for a medicine I can't give — **one click** marks it "Asked for, not available".
>
> At month end I see **which medicines are asked for repeatedly but not kept**.

<!-- -->

> **[NOTE]** This solves the "bringing it from outside" problem — without any extra typing.

---

### 6.10 — Reducing Mistakes and Working Fast

- Many medicine names are almost the same, only the strength differs — in the list show **strength and company name** beside it, strength in bold
- **Warn** when names are similar
- In search, **in-stock medicines first**, not-in-stock after
- The most sold items on top
- **Keyboard shortcuts** — type in search → Enter → quantity → Enter → next medicine
- **Most-used medicines in one click** — the 20 needed every day in a separate list
- **Simple screen for staff** — only search, quantity, save. Everything else hidden.

#### Barcode scanning

> **[IMPORTANT]** **Scanning a medicine box's barcode puts it straight onto the draft** — no typing, no searching. The biggest time-saver in a crowded shop, and it removes the risk of picking the wrong medicine with a similar name.

- Works at the **counter** (adds to the draft), in **stock entry** (adds to the purchase invoice), and in **stock count mode**
- Works with a USB/Bluetooth scanner and with the **phone camera**
- Each medicine can store **one or more barcodes** (different pack sizes / batches can carry different codes)
- An unknown barcode → the system asks which medicine it is (searched from the database) and **remembers it** next time

> **[DEV NOTE]** Scanning the same item again increases the quantity instead of adding a duplicate line.

---

### 6.11 — Memos and Communication

- Memo and invoice creation
- **Send memo via WhatsApp**
- **PC and mobile** — works on both

### 6.12 — The Wholesalers' Version

> **[IMPORTANT]** **Wholesalers get none of the features above.** All of them are only for my own medicine business.

| What they get | What they don't get |
|---|---|
| What they add is what stays | Source and percentage-based purchase price |
| Decreases as they sell | Bonus and cascading discounts |
| **Low-stock alert** | Profit/loss protection |
| See their own stock | Expiry management |
| — | Expired section |
| — | Offer-to-tier |
| — | Counter sale, drafts, cash reconciliation |

> **[DEV NOTE]** Who gets this simple version is also **decided by me manually**. I can see their stock (and profit); they cannot see each other's.

<!-- -->

> **[IMPORTANT]** **Other sectors (wood, clothing, electronics) do not have this stock system.** No purchase price, profit/loss or expiry needed there. Details in Chapter 15.

---

