## 11 — Medicine Database

### MedEx-style search

A complete medicine database (a separate sector, like MedEx). Anyone can search a medicine and see details.

With each medicine:

- **Brand name** (Napa), **generic name** (Paracetamol)
- **Company name**
- **Strength and form** — tablet, syrup, injection
- **MRP**
- **Uses and necessary information**
- **Alternative medicines with the same generic**

> **[IMPORTANT]** **Every medicine** available in the Bangladeshi market must be in this database, with generics.
>
> This isn't only for showing information — generic suggestions, MPO product selection, stock entry — all depend on it.

### Generic alternative suggestions

1. Each medicine is tagged with its generic — Napa → Paracetamol, Ace → Paracetamol
2. Searching a brand shows that medicine
3. Alongside, **offer or discounted products with the same generic pop up below** (more than one → they continue in a row)

#### Why this matters

Paikari buyers — small shop owners — move these medicines themselves. They'll take whichever is cheaper or more discounted.

Showing generic alternatives lets them choose the most profitable option, and my sales grow too. Medicine piling up in stock sells faster.

### Names must come from one place

> **[IMPORTANT]** **Nobody can type a medicine name by hand.** Everyone must **search the database and pick**.
>
> They can also add by company — open Beacon, see all its medicines, pick.

**Why:** typing by hand would create one medicine in twenty spellings — "নাপা" (Napa in Bengali script), "Napa", "napa 500". Then search and generic matching collapse.

> **[NOTE]** The company list is also preset; it can't be typed. Only I add new companies.

<!-- -->

> **[DEV NOTE]** **Decide the data source first:** where will the generic data for all Bangladeshi medicines come from — manual entry or an existing database — decide **before** starting.
>
> This is a big data-collection job; schedule it **separately**. Even if coding is finished, without data nothing can launch.
>
> **Start on day one**, alongside coding.

<!-- -->

### Cold-chain medicines

> **[IMPORTANT]** Some medicines must be kept cold — **insulin, vaccines, some injections**. Each medicine can carry a **"Keep refrigerated"** flag.

- The flag shows on the product page, on the staff picking list and on the memo
- Flagged items **cannot be sent by normal courier** — only my own delivery or counter pickup, with a note to pack with ice
- If a flagged item is in a courier order, checkout warns and offers pickup/own delivery instead

### Controlled-medicine flag

Medicines that legally need a sale register (see Chapter 27) carry a **"Controlled"** flag. Selling one asks for the extra details automatically.

<!-- -->


> **[PROBLEM]** Searching with a misspelling finds nothing.

<!-- -->

> **[SOLUTION]** Show results for close spellings — typing "নাপা" (Napa in Bengali script) also finds "Napa".

<!-- -->

> **[PROBLEM]** A medicine was discontinued or renamed.

<!-- -->

> **[SOLUTION]** Mark it **"Inactive"** — it no longer appears for new orders, but stays in old invoices and history.

<!-- -->

> **[PROBLEM]** Some medicines need a prescription.

<!-- -->

> **[SOLUTION]** Those medicines carry a **"Prescription required"** mark.

---

