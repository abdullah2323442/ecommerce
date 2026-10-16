## 26 — Staff and Security

### Staff roles

| Role | What they can do |
|---|---|
| **Super admin** (me) | Everything, no limits |
| **Sub-admin** | Almost everything, limited on prices and account settings. Approves Help posts. **Sees sales and profit.** |
| **Order manager** | Orders, status, fulfillment, messages. **Cannot:** prices or account settings |
| **Wholesale manager** | Approving applications, tiers, rates, categories |
| **Product manager** | Add and edit products, update stock |

> **[NOTE]** This is the starting structure. The role system must allow **creating new roles** and turning each permission on/off individually.

### What staff don't see

> **[IMPORTANT]** **Sales and profit** — only admin and sub-admin see them.
>
> But they **do see loss warnings** — otherwise they can't work.

### Security
- **IP block** — against spam and fake accounts
- **Account suspension** — permanent or **for a set period** (e.g., 2–3 days; reactivates automatically when it ends)
- A field to **note the reason**
- **2FA** — admin, staff, wholesale, paikari
- Account type verified on the server for every request
- Record of suspicious logins
- Temporary lock after repeated wrong passwords

**Why time-limited suspension:** not every problem needs a permanent ban. Someone late paying — a few days' suspension sends the message.

### Complications and solutions

> **[PROBLEM]** Everyone shares one account. Then "who did it" records mean nothing.

<!-- -->

> **[SOLUTION]** **A separate account for every staff member is mandatory.** A limit on how many devices can be logged in at once.

<!-- -->

> **[PROBLEM]** A staff member left; the account is still open.

<!-- -->

> **[SOLUTION]** It can be closed, but **their memos and records remain**. Deleting would break old accounts.

<!-- -->

> **[PROBLEM]** The admin account falls into someone's hands.

<!-- -->

> **[SOLUTION]** Big changes (prices, stock, deleting accounts) need **separate confirmation**. Plus 2FA.

<!-- -->

> **[PROBLEM]** Who can see customer data?

<!-- -->

> **[SOLUTION]** Limits by role, and **a log of who viewed whose data and when**.

---

