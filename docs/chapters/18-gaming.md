## 18 — Gaming Sector

> **[IMPORTANT]** **This sector is very simple.** It is **only diamond and game top-up** — exactly like **Codashop**. Nothing else is needed for now.

### What it has
- A list of games — Free Fire, PUBG, Mobile Legends
- Packages for each game — how many diamonds, how much money
- A field for the game ID / player ID
- Payment

### Steps
1. The buyer picks a game
2. Enters their game ID
3. The ID is verified — **the player's name is shown**, so money doesn't go to a wrong ID
4. Picks a top-up package
5. Payment — bKash, Nagad, Rocket, card
6. Top-up done, confirmation message

### How the top-up happens

> **[IMPORTANT]** The top-up is **automatic** — the way gaming pages do it. When the buyer pays, the diamonds go to their ID automatically.
>
> Direct agreements or deals with game companies — I'll **look into in the future**.

<!-- -->

> **[DEV NOTE]** If automatic top-up isn't available at the start, keep a **manual backup** — see the order in the admin panel and top up by hand.

### From my side
- Add and edit games
- Set packages and prices
- List of top-up orders — which succeeded, which failed
- Sales report

> **[NOTE]** Cash on delivery isn't possible for top-ups — **online payment only**.

### Complications and solutions

> **[PROBLEM]** Topped up to a wrong ID.

<!-- -->

> **[SOLUTION]** ID verification **shows the player's name**; the buyer confirms. After that, a mistake is the buyer's responsibility.

<!-- -->

> **[PROBLEM]** Money was taken, diamonds didn't arrive.

<!-- -->

> **[SOLUTION]** **Automatic refund** or retry. A separate list of failed top-ups.

<!-- -->

> **[PROBLEM]** The top-up was late.

<!-- -->

> **[SOLUTION]** After a set time, the buyer is told and can cancel for a refund.

---

