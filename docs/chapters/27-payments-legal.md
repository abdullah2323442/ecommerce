## 27 — Payments, Notifications and Legal

### Payments
- **bKash**, **Nagad**, **Rocket**, **bank transfer**, **card**
- **Cash on delivery** (COD) — paying when it's in your hand
- **Advance payment**

| Sector | Rule |
|---|---|
| **Public** | All methods. COD by per-product toggle. |
| **Wholesale** | COD and advance — set per customer |
| **Paikari** | Bank, bKash, COD, advance. Return credit is deducted. |
| **Food** | Online and COD. Advance on big orders. |
| **Gaming** | **Online only** — COD impossible for top-ups |
| **Custom order** | Advance + the rest on delivery |

### Notifications
- **Push** — order status, new offers, wishlist item back in stock, new messages
- **SMS** — order confirmation, OTP, delivery updates, packed notice
- **Email** — download links, memos, account matters

> **[NOTE]** In Bangladesh SMS is far more effective than email. Many shop owners don't check email, but everyone sees SMS.

### Legal
- **I have a drug license.** MPOs sell under my license too.
- **Wholesalers' licenses** are collected before accounts are given
- **Prescriptions** go only to me, sub-admins and staff
- **Terms of Service** and **Privacy Policy** pages are mandatory
- If the food sector needs a license, I'll get it

#### Controlled-medicine sale register

> **[IMPORTANT]** Some medicines require a **legal record of every sale** — needed for drug-license inspections.
>
> When a **controlled** medicine is sold, the system requires: **buyer's name, phone/address, prescription number, doctor's name, quantity, date, staff name**. The sale can't be finalized without them.

- A separate **register** screen lists every controlled sale, filterable by date and medicine
- **Print / PDF export** of the register for inspectors
- Only admin and sub-admin can edit; edits are logged

#### Future prescription marketplace (Future)
In the future many pharmacies (wholesalers or paikari) will be connected; the public will upload prescriptions and order wherever they like.

> **[DEV NOTE]** Don't build it now, but keep **the database structure and a basic stub/placeholder UI** ready.

### Marketing and general
- **Building trust** — verified badge, return policy page, reviews with photos, real address
- **WhatsApp / Messenger button** — more effective than email
- **FAQ / Help center**
- **Bangla and English toggle** (product names are never auto-translated)
- **Web-app sync** — a cart item on web appears in the app, same account
- **SEO**, **mobile-friendly and fast loading**
- **Text-size option** — so older people can read
- Reward points, referral, bundle deals, flash sale, abandoned-cart reminder, price-drop alert (Phase 2)
- Facebook Pixel, Analytics, affiliate, bulk SMS (Future) — not needed now, kept for the future

---

