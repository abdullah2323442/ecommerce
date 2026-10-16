## 07 — Paikari Sector

For small shops and pharmacies that buy medium quantities. Price is lower than retail, higher than wholesale.

> **[IMPORTANT]** **This sector is entirely for my own pharmacy business.** No wholesaler or other seller can list products here.
>
> It is mainly **admin-driven** — paikari buyers won't always log in and order themselves. Many will order by phone, and I will enter it myself.

Paikari buyers are not wholesalers — but based on their sales, I can upgrade them to wholesaler later.

### Stock is not shown — and why

> **[IMPORTANT]** In paikari **no stock information is shown**. Not "in stock", not "out of stock", not "5 left" — nothing.
>
> **Two reasons:**
>
> **1. Technical** — not all my pharmacy's goods are in the system yet. Stock for those isn't even known.
>
> **2. Business — and this is the real one.**
>
> If paikari buyers see that something is low, they won't want to order. Someone wanting 10 who sees 3 won't order, even though if they had ordered 10 I could have brought the rest from elsewhere.
>
> So **even after all goods are in the system, I will never show stock to buyers.** This is a permanent decision. (After Master Stock Sync, I and my staff will see stock on our screens — buyers never will.)

<!-- -->

> **[NOTE]** **Another benefit:** when orders come for medicines I don't have, I learn they are in demand. Showing stock would lose that information.

<!-- -->

> **[NOTE]** **Exception:** offer products can show stock — but even there I will usually show **only "In stock"** (Chapter 09).

### Search and order

- Search the medicine name
- Enter how many — **pieces, strips or boxes**
- The order reaches me and the staff **instantly**

### Short list — a free feature

> **[NOTE]** A feature just like PharmaTrack's short list. It is their **free benefit** — they can build lists even without ordering from me.

- **Order directly** from the list
- The list shows **their own prices**
- Offer rates and MPO products are also visible

**Why this is great:** it's free, but it makes shop owners come in regularly. Coming every day to build their list, they see prices and offers. Whenever they need something, I'm the first one they think of.

### PharmaTrack connection

> **[IMPORTANT]** Medicines in an order that can't be supplied are **automatically added to PharmaTrack's short list** — so they can be bought later, without writing them by hand.

### Fulfillment — start to finish

#### Step 1 — The buyer orders
They can't see stock, so they order everything they need. The order goes to me and staff instantly.

#### Step 2 — Initial MRP memo
- Clicking a button generates an **MRP-based memo PDF**
- This is preliminary, for the buyer's idea
- It is later replaced by the final memo

> **[NOTE]** **Exception:** offer products get no MRP memo — directly at the offer rate.

#### Step 3 — The order becomes a draft, staff verify

> **[IMPORTANT]** **Important:** the moment an order arrives it becomes a **draft invoice**. "Three-option verification" and "draft" are not separate processes — they are the same thing.

Staff choose one of three options for each medicine:

| No. | Option | Meaning |
|---|---|---|
| **1** | Fully available | Everything requested can be given |
| **2** | Partially available | Type how many there are (100 requested, 60 available) |
| **3** | Not available | Cannot be given at all |

##### Where the number comes from

| State | How |
|---|---|
| **Now** | Stock isn't in the system, so staff **go to the store room, look, and type it** |
| **After the toggle is on** | Stock is shown on the screen ("7 pieces available"). **Just click "Available"** and it fills in. |

> **[IMPORTANT]** Whatever is put on the draft is **deducted from stock immediately** — for items that are in the system. Details in 6.5 and 6.6.

<!-- -->

> **[DEV NOTE]** Removing or cancelling from the draft **returns it to stock**. Everything stays in history.

##### Offer items on the staff list

> **[IMPORTANT]** When a buyer orders offer products, they go to the staff **automatically, just like a normal order** — staff simply pick the goods.
>
> But on the staff screen the offer item is **marked** — the item name is shown **in a different color** and/or with an **"OFFER" label** — so staff instantly know it is an offer product.
>
> For offer items no number needs to be typed — it comes from stock.

##### Staff concurrency

> **[IMPORTANT]** Several staff can work on the same order.
>
> - When a staff member enters, it shows **which staff member is working**
> - Other staff can still enter
> - If two people click the same medicine, **whoever clicks first wins**
> - Both screens update **live and instantly**

#### Step 4 — The buyer sees it instantly
- The moment staff choose an option, the buyer sees it
- A list is generated automatically — what they got, what they didn't, what was partial

**Why instantly:** if a medicine isn't available, they can look elsewhere right away. Learning hours later hurts their business.

#### Step 5 — "Not available" items go to PharmaTrack
Automatically added to the short list. A list of medicines in demand but not in stock builds itself.

#### Step 6 — Real-time status
- **Delivery today or not**
- **Picking in progress**
- **Packed**
- **Sent for delivery**

#### Step 7 — Final memo

> **[NOTE]** Stock was already deducted when items were put on the draft. Here only the price and memo are finalized.

- Calculated at **the buyer's own price** — not MRP
- When I upload it, the preliminary MRP memo is replaced by the **final memo**
- **Memo order:** the **offer part first**, then the **regular part**, and at the end **both add up into one total** (see Chapter 08 and 22)

#### Step 7-A — Editing prices before sending

> **[IMPORTANT]** **Before** the final memo is shown, I can edit each line's price by hand, then upload.
>
> **Why essential:** medicines are bought at different prices at different times. The tier rate won't always give the right profit.

#### Step 8 — The buyer can add more
Even after seeing the final memo they can add more products. New products go through the same process from Step 3.

#### Step 9 — Collection or delivery
1. **Come and collect themselves**
2. **Send someone to collect**
3. **Delivery needed**

In the pharmacy business local shop owners often collect themselves or send someone. Assuming delivery for everyone adds unnecessary cost.

#### Step 9-A — Delivery charge entered by hand

> **[IMPORTANT]** Paikari delivery has **no fixed courier rate**. Goods are sent by auto-rickshaw/toto or with someone nearby — the cost differs each time.
>
> So **I enter whatever it cost by hand** — 50 taka, 60 taka, whatever. It is added to the memo.

#### Step 9-B — Free delivery progress

For each paikari buyer I set a threshold — e.g., 6,000 taka. Buying above it makes delivery free.

> **[IMPORTANT]** While ordering, their screen shows — **"4,000 taka more for free delivery"**.
>
> This encourages them to buy more.

<!-- -->

> **[PROBLEM]** The threshold has been passed, but I typed a charge anyway.

<!-- -->

> **[SOLUTION]** When entering a charge past the threshold, warn — "Free delivery applies for this shop".

#### Step 10 — Payment
- Bank account, bKash, cash on delivery, advance
- **"Paid / Due"** mark on the invoice

### Placing orders on their behalf

Many paikari buyers are used to ordering by phone. I can go into their account and enter the order myself, **at their own prices**.

> **[DEV NOTE]** To avoid forgetting phone orders, the counter has a **quick note** field — jot it under the customer's name, and make the draft from it later.

### Frequency-based suggestions

> **[IMPORTANT]** The items each paikari shop **takes most often** are suggested automatically while they order.
>
> No separate "re-order" button — it blends into the normal ordering flow.

### Habit-based reminders

> **[IMPORTANT]** The system learns **the time each shop usually orders**, and sends a notification at exactly that time.
>
> A different time for each shop.

<!-- -->

> **[DEV NOTE]** Keep an option to turn this notification off.

### Night-order note

> **[IMPORTANT]** A message goes to them: **"Place your orders at night — we'll have everything packed the same night."**
>
> Orders placed at night can be prepared that night, and delivery is ready in the morning.

<!-- -->

> **[NOTE]** **But this is not compulsory.** There is no order cut-off — **orders are accepted at any time**.

### Orders on holidays / when the shop is closed

> **[IMPORTANT]** Orders are always accepted. On a **holiday or closed day**, the buyer sees: **"Your order is received — it will be processed on the next working day."**
>
> (Night orders on a normal working day are packed the same night as above — this message is only for holidays.)

<!-- -->

> **[DEV NOTE]** Keep a holiday/closed-days setting in the admin panel; the message shows automatically on those days.

### Cancelling orders

| When | What happens |
|---|---|
| **Before staff start picking** | The buyer can **cancel by themselves** |
| **After staff start picking** | The buyer sends a **cancellation request**. The staff panel shows — "This shop wants to cancel" |
| **After purchase is complete** | Goes to the **refund option** (Chapter 12) |

### Goods packed but not collected

- The whole thing is **refunded**
- Stock **goes back**

> **[IMPORTANT]** For those who do this repeatedly, **the system counts and flags them automatically**. Then I decide — suspend or not.

<!-- -->

> **[DEV NOTE]** **Keep the "how many times before flagging" number in settings**, so I can change it myself.

### Monthly sales and tier upgrade

> **[IMPORTANT]** At month end each paikari buyer's profile shows **how much business they gave this month** — online and counter combined.
>
> **Why show it:** if someone sees only 500 taka of business, they'll feel a bit embarrassed and try to do better next month.

- This is a **separate toggle per customer** — I decide whom to show
- Not during the month, **only at month end**

#### Tier upgrade — like a task

> **[IMPORTANT]** At month end I show **how much more they would have needed to buy to reach the next tier**. It works **like a task** — "Buy 2 lakh taka this month and you'll be upgraded."

- When the task is completed, **it comes to me**
- **I check and upgrade** — not automatic
- On upgrade, a **congratulations message** is sent

> **[NOTE]** No live progress is shown. Otherwise people would force-buy just to become wholesalers.

### Prescription upload

> **[IMPORTANT]** **Prescriptions go only to me, sub-admins and staff** — that is, only my pharmacy. It is sensitive medical information.

<!-- -->

> **[PROBLEM]** A prescription came in, nobody noticed in the rush.

<!-- -->

> **[SOLUTION]** Show a count of unseen prescriptions; notify after a set time.

---

