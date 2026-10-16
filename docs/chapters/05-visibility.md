## 05 — Visibility

> **[IMPORTANT]** This is the most important security topic of my platform. It's not only personal data — **business secrets** are involved.

### Who can go where

| Type | Public | Paikari | Wholesale | Others |
|---|---|---|---|---|
| Public | Yes | No | No | Food, Gaming, Help |
| Paikari | Yes | Yes | **No (invisible)** | Yes |
| Wholesaler | Yes | No | Yes | Yes |
| MPO | No | No | No | Own section only |
| Craftsman | No | No | No | Own jobs only |

### Category-based wholesale access

Wholesale has many categories. A medicine wholesaler sees only medicine; a wood wholesaler sees only wood. One never even knows the other's category exists. If someone does both businesses, I give access to both categories.

### The strictest rule

> **[IMPORTANT]** A paikari user must not see **any trace** of the wholesale section:
>
> - No lock icon
> - No greyed-out menu
> - No "access denied" page
> - No hint at all
>
> The section must be completely **invisible**.
>
> **Why:** if a paikari buyer learns that wholesale prices are lower, they will be unhappy or pressure me. The business relationship gets damaged.
>
> Only when I upgrade their tier to wholesaler will it appear for them.

### Deep links

Every sector and product can generate its own link. Clicking it opens that page directly.

> **[IMPORTANT]** But if **someone without permission clicks** — they are quietly sent to the **public homepage**.
>
> No error, no login prompt, no "access denied" page. They will not even realize something was there.

### Seller identity

> **[IMPORTANT]** **By default, every product shows my website's name.** Buyers do not know who the real seller is.
>
> **Why:** if buyers know, they will later contact the seller directly and skip me.

But each seller has a **toggle** — if I turn it on, their name is shown. This is a marketing benefit that I will arrange through a deal with them.

> **[NOTE]** **Linked to this:** only sellers whose name display is turned on get a **shop page**, and only their products get the **pickup point** option. For everyone else, those options simply do not appear.

### Complications and solutions

> **[PROBLEM]** Someone types an address directly in the browser to get into another section.

<!-- -->

> **[SOLUTION]** Hiding links in the menu is not enough. **Every request must be verified on the server.** Hiding on the frontend is not security — it is decoration.

<!-- -->

> **[PROBLEM]** Someone tries to view another person's order by changing the number in the link.

<!-- -->

> **[SOLUTION]** Orders, memos, chats — every single one is checked on the server to confirm it belongs to the requester.

<!-- -->

> **[PROBLEM]** How much can staff see?

<!-- -->

> **[SOLUTION]** Staff **do not see sales or profit** — only admin and sub-admins do.
>
> But staff **do see loss warnings** — otherwise they cannot work.

<!-- -->

> **[PROBLEM]** If craftsmen see prices, they could take the work outside.

<!-- -->

> **[SOLUTION]** Craftsmen **see** — measurements, job description, photos, chat, deadline, their own wages.
>
> They **do not see** — the buyer's price, my profit, the buyer's phone number, other craftsmen's jobs.

---

