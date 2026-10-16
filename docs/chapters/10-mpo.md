## 10 — MPO Sector

### What an MPO is and why this sector exists

MPO = **Medical Promotion Officer**. Pharma companies give their representatives monthly sales targets. If the target isn't met, their job or commission suffers.

Under this pressure many MPOs release their allotted products through alternative channels. This sector is exactly for that.

> **[IMPORTANT]** **The core idea**
>
> I am the **middleman** here. This is a completely secret sourcing channel.
>
> **In the buyer's eyes:** the product is sold from my account. The MPO is completely invisible.
>
> **In the MPO's eyes:** the order came from my account. The real buyer is invisible.
>
> **Both sides wear masks — only I in the middle see everything.**

### Why secrecy is so important

- **For the MPOs:** if their identity is exposed, they get professional trouble. Without secrecy nobody will use this channel.
- **For me:** if buyers knew, they'd later contact that MPO directly and cut me out.

### How the goods change hands

> **[IMPORTANT]** **Why am I the middleman? Because the goods pass through my hands.**
>
> 1. **I take the product from the MPO**
> 2. **Then I give it to the wholesaler or paikari buyer**
>
> The MPO never ships directly to the buyer. If they did, their identity would leak.

<!-- -->

> **[NOTE]** **It enters stock:** the goods reach my hands, so they enter stock. The source is "Through MPO", purchase price accordingly (6.1).

### Account creation

Only I can create MPO accounts. **There is no sign-up option** — the relationship is personal and confidential.

#### Detailed profile

- Name and photo, phone and contact
- **Company** (one or more)
- **Area / territory**
- **List of products they work with**
- Account creation date, how many products submitted, how many sold
- **Notes** — my own comments
- **How many times they failed to deliver** — counted

### Area and auto-grouping

> **[IMPORTANT]** When creating the account I set the **territory**. Accounts are **automatically grouped** by it.

- At a glance: how many MPOs in which area
- Which medicines are more available in which area
- Reports by territory

> **[DEV NOTE]** Grouping must be automatic. Changing a territory updates the grouping too.

### Which products they work with — selection

> **[IMPORTANT]** **This is the Bangladeshi reality; understand it well.**
>
> A company might have **10,000 medicines**. But one MPO is given **1,000**, another **900**.
>
> **An MPO never works with all of a company's products.**

1. When creating the account I set the company
2. They see **all of that company's medicine names**
3. **They choose themselves** which products they work with
4. After that they can submit **only those**

For easy selection — a search bar, select many at once, edit later. I can also see and change their list.

### Multiple companies

> **[IMPORTANT]** Normally an MPO gets access to **their own company's** medicines only. **But I can give access to another company too.**

- A **company list** on the profile
- **Add** or **remove** at any time
- Adding a new company brings its medicine list to them

> **[DEV NOTE]** Build two relationships as **one-to-many** in the database:
>
> 1. One MPO ↔ many companies
> 2. One MPO ↔ many selected products
>
> If not built this way from the start, many places will need changing later.

### MPOs don't see each other

Each MPO is completely isolated. They don't know who else exists, who submits what, or at what price others' products go.

**Why:** if they knew each other, they'd discuss prices among themselves, or all move elsewhere together.

### What an MPO can do

| Can | Cannot |
|---|---|
| Submit selected products | Set prices |
| Report stock status | Decide who sees it |
| Say how many days until it arrives | See other MPOs |
| **Offer a bonus** | Know who the real buyer is |
| Edit their own product list | Add a new company themselves |
| See and accept bids | Change their own territory |
| Turn on pre-order for their target products | — |

### Stock status

- **In stock** — available now
- **Pre-order** — not available, arrives after a set number of days

Two stock display modes — exact number or just "In stock".

> **[IMPORTANT]** MPO products **do not need an expiry date**.
>
> **Reason:** the goods pass through my hands (above). Once in my stock, expiry is handled by my stock system (6.3). Making the MPO do the same work twice would annoy them and increase mistakes.

### Bonus products

Bonuses are very common in the medicine business. For example, take 10, get 2 free.

1. On upload, an option — **"Give bonus"** or **"No bonus"**
2. **"No bonus" is the default.** Bonuses aren't always given.
3. If turned on, they write the ratio — e.g., **10+2**
4. Buyers see — "Take 10+2, get the bonus"
5. **The bonus is shown separately on the memo too**

> **[EXAMPLE]** **How it appears on the memo:**
>
> Napa 500 mg — Quantity: 10 boxes
>
> Napa 500 mg — **Bonus: 2 boxes** (value 0)
>
> **Total received: 12 boxes**

<!-- -->

> **[IMPORTANT]** The bonus **shows from my account** — because to everyone outside, the MPO is me.

<!-- -->

> **[DEV NOTE]** The bonus has no price but is deducted from stock. The ratio must be flexible — 10+2, 20+5, 100+15, anything.

### My review and approval

When an MPO submits a product it doesn't go straight to market — it comes to me first. I decide:

1. **Approve or not**
2. **Which markets it shows in** — wholesale, paikari, public (each separately)
3. **At what price in each**
4. **How stock status is displayed**
5. **Whether to show the bonus**
6. **Whether it's in stock or pre-order, and how many days until it arrives**

### Name format

> **[IMPORTANT]** MPO products appear to buyers like this:
>
> **Anonymous 1 (my wholesale store name)**
>
> or **Unknown 1 (my wholesale store name)**
>
> (In English.)

- Several MPOs get different numbers — Anonymous 1, 2, 3
- During bidding it is clear which listing is being discussed
- Because my name is in brackets, buyers understand it comes from my platform — wholesalers can pre-order it without knowing it's an MPO

### Identity secrecy

| Who | Can see the identity? |
|---|---|
| **Me** | **Yes** — identity, company, territory, everything |
| Wholesaler | No |
| Paikari | No |
| Public | No |
| Other MPOs | No |
| Staff | By role |

### How orders appear

- The buyer sees they're buying from me
- The MPO sees **my account** placed the order
- This record is **proof** for the MPO — to show in their own company's target report

> **[NOTE]** Real money with MPOs is settled **outside** the platform. No payment tracking or commission module needed — only the order record.

### Blind bidding

On MPO products, instead of buying at the set price, wholesalers can propose their own price. Like an auction, but everyone is masked.

- The wholesaler offers as a percentage — "This price doesn't work, I can take it at 15%"
- Several wholesalers can bid on the same product

#### Who sees what

| Who | Sees |
|---|---|
| **Me** | Everything — which shop offered how much, with identity |
| **MPO** | The bids, but without names — "Anonymous 1: 10%", "Anonymous 2: 15%" |
| **Wholesalers** | Never see each other's bids, only their own |

**Why wholesalers can't see:** they'd undercut each other, or coordinate and all offer the same low price.

#### Accepting a bid

> **[IMPORTANT]** **The power to accept the final bid belongs to the MPO, not me.**
>
> 1. Wholesalers bid
> 2. The MPO sees the anonymous list
> 3. The MPO accepts one bid
> 4. The order is confirmed for that wholesaler
> 5. Both sides see my account

<!-- -->

> **[PROBLEM]** Nobody's bid is accepted; the product is hanging.

<!-- -->

> **[SOLUTION]** The MPO gets a notification — **"Your product isn't selling. Would you like to increase the % yourself?"**
>
> They can raise the percentage themselves if they wish.

<!-- -->

> **[PROBLEM]** The MPO accepted a bid but didn't deliver.

<!-- -->

> **[SOLUTION]** If goods don't arrive in the set time, the order is cancelled and the wholesaler informed. The MPO's profile counts **how many times this happened**.

<!-- -->

> **[PROBLEM]** The MPO quit their job; their products are on the market.

<!-- -->

> **[SOLUTION]** Closing the account removes the products. Ongoing orders must be completed.

<!-- -->

> **[PROBLEM]** Two MPOs list the same medicine at the same price.

<!-- -->

> **[SOLUTION]** No problem, both stay. My panel shows them side by side to compare.

### License

> **[NOTE]** MPOs sell **under my license**, from my account. They don't need their own license.

---

