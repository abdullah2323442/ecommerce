# PART 6 — CLOSING

---

## 28 — Technical Notes

### Note 1 — Server-side visibility checks

> **[IMPORTANT]** "Who sees what" must **not** be done by hiding things on the frontend. Every request is verified on the server.
>
> Business secrets are involved — who buys at what price, MPO identities, who bid how much. If leaked, the whole model collapses.

### Note 2 — Real-time updates
Paikari fulfillment, drafts, bidding, messages, food orders, custom-order progress, staff concurrency — real time everywhere. Never ask the user to refresh.

### Note 3 — Central lists
Three central lists — **medicines**, **food ingredients**, **other products**. One mechanism in three places — I maintain the list, sellers pick from it, and request additions for anything new.

### Note 4 — Medicine data collection
A big job; schedule it **separately**. **Start on day one.**

### Note 5 — MPO data structure
One MPO ↔ many companies, and ↔ many selected products. One-to-many from the start.

### Note 6 — The sector-builder system

> **[IMPORTANT]** Don't write separate code for each sector. Build **a system for creating sectors** — I can add new sectors myself.

### Note 7 — Backups
- **Automatic database backup every night**, at least 30 days of copies
- **Stored outside the server**
- **Tested once a month** — does the backup actually work

### Note 8 — When the internet drops
If staff lose internet while working in the store room, work must not be lost. Each item is saved the moment it's added. Re-sync when the internet returns. The counter works fully offline (Chapter 08).

### Note 9 — Importing old data
Before launch, existing shops' data must be entered — rates, tiers, addresses. A **bulk import** option helps.

### Note 10 — System time

> **[IMPORTANT]** If the time is wrong, expiry, the 2–3 day returns, the offer month — everything goes wrong. **Keep it correct to Bangladesh time**, and it must not be changeable by hand.

### Note 11 — Performance
- Old data stored separately — searchable, but not burdening daily work
- Images **auto-compressed** on upload
- **Low-quality image first**, the good one after — for slow internet
- Able to handle many orders at once during festivals
- Server updates **at night or on closed days**, announced in advance

### Note 12 — Room for the future
Phase 2 and Future features needn't be built now, but the structure must allow adding them later without breaking what exists.

> **[NOTE]** **The credit ledger (baki khata)** isn't needed now. I'll build it separately later and connect it to this.
>
> The ledger will also hold **what I owe each company/supplier** (dues and due dates). Not part of this build.

### Note 13 — Store user behavior data from day one
What people search, view, buy and skip — store it from the start. Ranking, suggestions and seasonal forecasts depend on it.

---

