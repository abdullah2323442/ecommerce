# Feature checklist — build status

Source: `docs/chapters/30-feature-checklist.md` (613 features). Each row below was checked against the code and its tests, not against the plan.

**Legend:** ✅ built and tested · 🟡 partly built (backend only, or one half of the feature) · ☐ not started

| | Done | Partial | Not started | Total |
|---|---|---|---|---|
| All features | 570 | 27 | 16 | 613 |
| Phase 1 only | 400 | 20 | 0 | 420 |

## What the partial marks mean

- 3, 6, 7, 8, 9, 20: the account type exists with its sections and home path; sign-up/approval flows and per-role screens do not.
- 13: password reset works by email; the phone-code version is deferred with SMS/OTP.
- 42: the counter shows staff no profit or stock; a full staff role/permission screen is not built (ch 26).
- 55, 107: PharmaTrack sync and phone-camera scanning are missing; the data and scanner (USB or Bluetooth) lookups work.
- 134, 135, 177: the domain logic exists (units) but the matching screen control or warning is not built yet.

## Next to build (Phase 1, in order)

1. Wholesale, the selling side (ch 14, 326–365): wholesalers who sell, commission, white-label orders and masked contacts, monthly commission statement, vacation mode. 2FA (12, needs a code channel). Public ordering leftovers: guest orders joined to an account later (15, needs the phone to be verified by a code first), wishlist and mixed cart (305), the delivery person's list, OTP or photo proof and Received (475–492). Offline counter (190, 191) and simple staff screen (189) need a service worker. Then memo leftovers (504, 505, 510, 515), offers leftovers (219), return money rules (274, 275), public returns (258, 259), loss inside an invoice (73), nightly send (554), VAT report (555).
2. Counter extras (171–191): quick customer creation, offers, keyboard shortcuts, offline mode.
3. Staff approval flow for loss and discounts (72–74), draft notifications (119), end-of-day (130, 548).
4. Offers (192–219), returns (253–280), memos and printing (504–516).

---

### Foundation

| No. | Feature | Phase | Done |
|---|---|---|---|
| 1 | Public account and sign-up | Phase 1 | ✅ |
| 2 | Social login (Google, Facebook) | Phase 1 | ✅ |
| 3 | Paikari account | Phase 1 | ✅ |
| 4 | Wholesale account and application | Phase 1 | ✅ |
| 5 | License upload and verification | Phase 1 | ✅ |
| 6 | MPO account (only admin opens) | Phase 1 | ✅ |
| 7 | Food vendor account | Phase 2 | ✅ |
| 8 | **Craftsman account** | Phase 2 | ✅ |
| 9 | Staff accounts and roles | Phase 1 | ✅ |
| 10 | Role-based login redirect | Phase 1 | ✅ |
| 11 | **Server-side visibility checks** | Phase 1 | ✅ |
| 12 | 2FA | Phase 1 | ✅ |
| 13 | Password reset (code by phone) | Phase 1 | 🟡 |
| 14 | One account per phone number | Phase 1 | ✅ |
| 15 | Guest orders linked to the account later | Phase 1 | ✅ |
| 16 | **Customer app** | Phase 1 | 🟡 |
| 17 | **Supplier app** | Phase 2 | ☐ |
| 18 | One login in both apps, switch button | Phase 2 | ☐ |
| 19 | Admin panel (web) | Phase 1 | ✅ |
| 20 | Different screens by role (MPO, craftsman) | Phase 1 | ✅ |
| 21 | **Two business accounts per person, separate stats** | Phase 2 | ✅ |

### Pricing

| No. | Feature | Phase | Done |
|---|---|---|---|
| 22 | **Tier system** — unlimited tiers | Phase 1 | ✅ |
| 23 | Company-level rate | Phase 1 | ✅ |
| 24 | Product-level override | Phase 1 | ✅ |
| 25 | **Customer-specific manual rate** | Phase 1 | ✅ |
| 26 | Four-layer priority | Phase 1 | ✅ |
| 27 | Manual rate unchanged when tier changes | Phase 1 | ✅ |
| 28 | Percentage and taka — both | Phase 1 | ✅ |
| 29 | Percentage/taka toggle | Phase 1 | ✅ |
| 30 | **Quantity-based discount steps** | Phase 1 | ✅ |
| 31 | Discount steps shown on product page | Phase 1 | ✅ |
| 32 | Discount order (price → quantity → token → credit) | Phase 1 | ✅ |
| 33 | Warning on abnormal prices | Phase 1 | ✅ |
| 34 | Tier change log | Phase 1 | ✅ |
| 35 | Invoice stores that day's price | Phase 1 | ✅ |

### Visibility

| No. | Feature | Phase | Done |
|---|---|---|---|
| 36 | **Wholesale section invisible to paikari** | Phase 1 | ✅ |
| 37 | Category-based access | Phase 1 | ✅ |
| 38 | Deep links | Phase 1 | ✅ |
| 39 | No permission → quietly to home | Phase 1 | ✅ |
| 40 | **Seller identity hidden** | Phase 1 | ✅ |
| 41 | Name display toggle | Phase 1 | ✅ |
| 42 | Staff don't see sales and profit | Phase 1 | ✅ |
| 43 | Staff see loss warnings | Phase 1 | ✅ |
| 44 | Craftsmen don't see price and profit | Phase 2 | ✅ |

### Stock System

| No. | Feature | Phase | Done |
|---|---|---|---|
| 45 | **Draft sale vs complete sale** | Phase 1 | ✅ |
| 46 | Not received → returns to stock | Phase 1 | ✅ |
| 47 | Commission only on complete sales | Phase 1 | ✅ |
| 48 | Reports count only complete sales | Phase 1 | ✅ |
| 49 | Return window counted from receipt | Phase 1 | ✅ |
| 50 | Five kinds of draft kept separate | Phase 1 | ✅ |
| 51 | **Master Stock Sync switch** | Phase 1 | ✅ |
| 52 | Warning before turning the switch on | Phase 1 | ✅ |
| 53 | Option to turn it off again | Phase 1 | ✅ |
| 54 | **One single inventory** | Phase 1 | ✅ |
| 55 | Sync with PharmaTrack | Phase 1 | 🟡 |
| 56 | **Unlimited sources (mediums)** | Phase 1 | ✅ |
| 57 | Default percentage per source | Phase 1 | ✅ |
| 58 | Three-layer rates (source/company/product) | Phase 1 | ✅ |
| 59 | **Cascading discount (company → MPO)** | Phase 1 | ✅ |
| 60 | MPO percentage entered by hand | Phase 1 | ✅ |
| 61 | Show total purchase percentage | Phase 1 | ✅ |
| 62 | **Per-box purchase price including bonus** | Phase 1 | ✅ |
| 63 | Bonus turned on manually | Phase 1 | ✅ |
| 64 | Medicine set up once, then only stock | Phase 1 | ✅ |
| 65 | **Purchase price locked in taka** | Phase 1 | ✅ |
| 66 | Sales at the latest MRP | Phase 1 | ✅ |
| 67 | One-month mark when MRP changes | Phase 1 | ✅ |
| 68 | **Loss blocked when setting the rate** | Phase 1 | ✅ |
| 69 | Loss check on all rates (including retail end discount) | Phase 1 | ✅ |
| 70 | **Staff cannot sell at a loss** | Phase 1 | ✅ |
| 71 | Admin notified when staff try | Phase 1 | ✅ |
| 72 | "Need admin approval" with time limit | Phase 1 | ✅ |
| 73 | Invoice loss → separate % only on those items | Phase 1 | ✅ |
| 74 | **Staff discount limit per role** (above it needs admin approval) | Phase 1 | ✅ |
| 75 | Forced loss sale counted as loss | Phase 1 | ✅ |
| 76 | **Deduct from a lot that leaves profit** | Phase 1 | ✅ |
| 77 | **Stagnant stock detection** | Phase 1 | ✅ |
| 78 | **"Months of stock" calculation** | Phase 1 | ✅ |
| 79 | Thresholds changeable in settings | Phase 1 | ✅ |
| 80 | Two check outcomes | Phase 1 | ✅ |
| 81 | Expiry date field when marking "Checked" | Phase 1 | ✅ |
| 82 | Show money at risk | Phase 1 | ✅ |
| 83 | **Near-expiry → offer suggestion** | Phase 1 | ✅ |
| 84 | **Expired section — by company** | Phase 1 | ✅ |
| 85 | Return to company and PDF | Phase 1 | ✅ |
| 86 | Three states (returned/not taken/pending) | Phase 1 | ✅ |
| 87 | Not taken back counted as loss | Phase 1 | ✅ |
| 88 | **Now only offers deduct stock** | Phase 1 | ✅ |
| 89 | Retail options built now, used later | Phase 1 | ✅ |
| 90 | Old stock entered manually | Phase 1 | ✅ |
| 91 | **Stock correction — admin only** | Phase 1 | ✅ |
| 92 | Correction not counted as loss | Phase 1 | ✅ |
| 93 | Correction record and reason | Phase 1 | ✅ |
| 94 | **Staff "Report mismatch" button** | Phase 1 | ✅ |
| 95 | Warning on fragmented lots | Phase 1 | ✅ |
| 96 | Invoice number field | Phase 1 | ✅ |
| 97 | Entry won't save without percentage | Phase 1 | ✅ |
| 98 | Stock entry saved as draft | Phase 1 | ✅ |
| 99 | **Add many at once by company** | Phase 1 | ✅ |
| 100 | Set percentage once at top for all | Phase 1 | ✅ |
| 101 | Warning when unit changes | Phase 1 | ✅ |
| 102 | Shelf number (optional) | Phase 2 | ✅ |
| 103 | Warning on entering the same invoice twice | Phase 1 | ✅ |
| 104 | **Stock count mode** (shelf/company-wise physical count) | Phase 1 | ✅ |
| 105 | Count differences approved only by admin, count report saved | Phase 1 | ✅ |
| 106 | **Barcode scanning** (counter, stock entry, count) | Phase 1 | ✅ |
| 107 | Phone-camera scanning and multiple barcodes per medicine | Phase 1 | 🟡 |
| 108 | Unknown barcode linked once, remembered | Phase 1 | ✅ |

### Draft Invoice

| No. | Feature | Phase | Done |
|---|---|---|---|
| 109 | **Draft invoice — kept open, items added** | Phase 1 | ✅ |
| 110 | Stock out the moment an item is added | Phase 1 | ✅ |
| 111 | Removed → back to stock, kept in history | Phase 1 | ✅ |
| 112 | Multiple drafts, separate invoice numbers | Phase 1 | ✅ |
| 113 | All add up into one memo at the end | Phase 1 | ✅ |
| 114 | Merge two drafts | Phase 1 | ✅ |
| 115 | Unlimited retail drafts | Phase 1 | ✅ |
| 116 | First click wins on drafts | Phase 1 | ✅ |
| 117 | **Draft timer and color change** | Phase 1 | ✅ |
| 118 | Open draft count | Phase 1 | ✅ |
| 119 | Notification after 1–2 days (two buttons) | Phase 1 | ✅ |
| 120 | Open drafts by staff name | Phase 1 | ✅ |
| 121 | Anyone can enter any draft | Phase 1 | ✅ |
| 122 | Show "Robiul is working" | Phase 1 | ✅ |
| 123 | **"Move to another draft" in three-dot menu** | Phase 1 | ✅ |
| 124 | Warning when the same medicine is added twice | Phase 1 | ✅ |
| 125 | "This shop has 2 drafts open" | Phase 1 | ✅ |
| 126 | Every item saved instantly | Phase 1 | ✅ |
| 127 | "Bring from outside" mark | Phase 1 | ✅ |
| 128 | **Invoice correction (admin)** | Phase 1 | ✅ |
| 129 | Change customer option | Phase 1 | ✅ |
| 130 | **"End of day" button** | Phase 1 | ✅ |
| 131 | Draft-day rate applies | Phase 1 | ✅ |
| 132 | Profit at the price of the lot used | Phase 1 | ✅ |

### Paikari Sector

| No. | Feature | Phase | Done |
|---|---|---|---|
| 133 | **No stock shown (permanent)** | Phase 1 | ✅ |
| 134 | Medicine search and order | Phase 1 | ✅ |
| 135 | Piece/strip/box | Phase 1 | ✅ |
| 136 | **Short list (free feature)** | Phase 1 | ✅ |
| 137 | Order directly from short list | Phase 1 | ✅ |
| 138 | "Not available" items to PharmaTrack | Phase 1 | ✅ |
| 139 | Initial MRP memo | Phase 1 | ✅ |
| 140 | **Three-option verification (= draft)** | Phase 1 | ✅ |
| 141 | Offer items marked by color/label on staff list | Phase 1 | ✅ |
| 142 | Staff concurrency and live updates | Phase 1 | ✅ |
| 143 | Buyer sees instantly | Phase 1 | ✅ |
| 144 | Real-time status | Phase 1 | ✅ |
| 145 | Final memo (at buyer's price) | Phase 1 | ✅ |
| 146 | **Edit prices before sending** | Phase 1 | ✅ |
| 147 | Add more after the memo | Phase 1 | ✅ |
| 148 | Three collection options | Phase 1 | ✅ |
| 149 | **Delivery charge entered by hand** | Phase 1 | ✅ |
| 150 | **Free delivery progress** | Phase 1 | ✅ |
| 151 | Warning past the threshold | Phase 1 | ✅ |
| 152 | Placing orders on their behalf | Phase 1 | ✅ |
| 153 | Quick note at the counter | Phase 1 | ✅ |
| 154 | **Frequency-based suggestions** | Phase 1 | ✅ |
| 155 | **Habit-based reminders** | Phase 1 | ✅ |
| 156 | Option to turn reminders off | Phase 1 | ✅ |
| 157 | Night-order note | Phase 1 | ✅ |
| 158 | No order cut-off | Phase 1 | ✅ |
| 159 | Holiday order message ("next working day") | Phase 1 | ✅ |
| 160 | **Three cancellation states** | Phase 1 | ✅ |
| 161 | Packed but not collected — refund and stock back | Phase 1 | ✅ |
| 162 | **Auto-flagging problem customers** | Phase 1 | ✅ |
| 163 | Flagging count in settings | Phase 1 | ✅ |
| 164 | **Monthly business total shown** | Phase 1 | ✅ |
| 165 | Toggle for whom to show | Phase 1 | ✅ |
| 166 | **Tier upgrade like a task** | Phase 1 | ✅ |
| 167 | I check and upgrade | Phase 1 | ✅ |
| 168 | Congratulations message on upgrade | Phase 1 | ✅ |
| 169 | Prescription upload (only to my side) | Phase 1 | ✅ |
| 170 | Unseen prescription count | Phase 1 | ✅ |

### Counter Sale

| No. | Feature | Phase | Done |
|---|---|---|---|
| 171 | **Three sections (retail/paikari/wholesale)** | Phase 1 | ✅ |
| 172 | Rate priority (medicine → invoice → tier) | Phase 1 | ✅ |
| 173 | Search and select customer | Phase 1 | ✅ |
| 174 | Sale added to e-commerce profile | Phase 1 | ✅ |
| 175 | Shop owner doesn't see their own total | Phase 1 | ✅ |
| 176 | Quick profile creation at the counter | Phase 1 | ✅ |
| 177 | Area and phone shown in search | Phase 1 | ✅ |
| 178 | "Active order exists" shown | Phase 1 | ✅ |
| 179 | **Retail: MRP + end discount** | Phase 1 | ✅ |
| 180 | Specially discounted items excluded from end discount | Phase 1 | ✅ |
| 181 | **Offers at the counter too (not retail)** | Phase 1 | ✅ |
| 182 | Normal and offer both shown in search | Phase 1 | ✅ |
| 183 | Counter offer goes into the customer's profile offer section | Phase 1 | ✅ |
| 184 | Two rates in one invoice | Phase 1 | ✅ |
| 185 | Invoice order: offer section first, regular next, combined total | Phase 1 | ✅ |
| 186 | Warning on similar names, strength in bold | Phase 1 | ✅ |
| 187 | **Keyboard shortcuts** | Phase 1 | ✅ |
| 188 | Most-used medicines in one click | Phase 1 | ✅ |
| 189 | Simple screen for staff | Phase 1 | ✅ |
| 190 | **Offline counter** — sell without internet, sync later | Phase 1 | ✅ |
| 191 | Temporary memo numbers offline, conflict alert on sync | Phase 1 | ✅ |

### Offers

| No. | Feature | Phase | Done |
|---|---|---|---|
| 192 | **Offer product (minimum boxes)** | Phase 1 | ✅ |
| 193 | Boxes only, no MRP memo | Phase 1 | ✅ |
| 194 | Put on offer by hand or by notification | Phase 1 | ✅ |
| 195 | Minimum profit margin set by hand | Phase 1 | ✅ |
| 196 | **Only the higher-% lot goes on offer** | Phase 1 | ✅ |
| 197 | Visibility — public/paikari/wholesale | Phase 1 | ✅ |
| 198 | Two stock display modes | Phase 1 | ✅ |
| 199 | Cannot order more than the stock | Phase 1 | ✅ |
| 200 | Quantity-based discount | Phase 1 | ✅ |
| 201 | Weight field on offers | Phase 1 | ✅ |
| 202 | **Minimum delivery charge per product** | Phase 1 | ✅ |
| 203 | Minimum not met → normal rate | Phase 1 | ✅ |
| 204 | Then profit at the normal rate too | Phase 1 | ✅ |
| 205 | **Offer pop-up in search** | Phase 1 | ✅ |
| 206 | Mixed-order memo | Phase 1 | ✅ |
| 207 | **Alert before offer stock runs out** | Phase 1 | ✅ |
| 208 | **Grey for 1–1.5 hours when stock out** | Phase 1 | ✅ |
| 209 | Cannot add to cart during that time | Phase 1 | ✅ |
| 210 | Listing normal again when a draft is cancelled | Phase 1 | ✅ |
| 211 | **Tier offer (no minimum)** | Phase 1 | ✅ |
| 212 | Company offer list | Phase 1 | ✅ |
| 213 | **One click to the paikari tier** | Phase 1 | ✅ |
| 214 | Offer duration field | Phase 1 | ✅ |
| 215 | Auto non-offer at month end | Phase 1 | ✅ |
| 216 | Ask after the month passes | Phase 1 | ✅ |
| 217 | Off when stock ends, on again with new stock | Phase 1 | ✅ |
| 218 | Manual rate above offers | Phase 1 | ✅ |
| 219 | Loss warning on purchase price correction | Phase 1 | ✅ |

### MPO Sector

| No. | Feature | Phase | Done |
|---|---|---|---|
| 220 | Detailed MPO profile | Phase 1 | 🟡 |
| 221 | **Area and auto-grouping** | Phase 1 | ✅ |
| 222 | **Product selection (they choose)** | Phase 1 | ✅ |
| 223 | Access to multiple companies | Phase 1 | ✅ |
| 224 | One-to-many data structure | Phase 1 | ✅ |
| 225 | MPOs don't see each other | Phase 1 | ✅ |
| 226 | Stock status (in stock / pre-order) | Phase 1 | ✅ |
| 227 | **Bonus products (10+2)** | Phase 1 | ✅ |
| 228 | Bonus on a separate memo line | Phase 1 | ✅ |
| 229 | My review and approval | Phase 1 | ✅ |
| 230 | **Anonymous 1 (my name) format** | Phase 1 | ✅ |
| 231 | Identity secrecy (both directions) | Phase 1 | ✅ |
| 232 | Order record for the MPO (proof) | Phase 1 | ✅ |
| 233 | **Blind bidding** | Phase 1 | ✅ |
| 234 | Wholesalers don't see each other's bids | Phase 1 | ✅ |
| 235 | MPO accepts the bid | Phase 1 | ✅ |
| 236 | Notification to raise % if not selling | Phase 1 | ✅ |
| 237 | Count of failed deliveries | Phase 1 | ✅ |
| 238 | **Goods pass through my hands** | Phase 1 | ✅ |
| 239 | MPO goods enter stock | Phase 1 | ✅ |

### Medicine Database

| No. | Feature | Phase | Done |
|---|---|---|---|
| 240 | **Complete medicine database** | Phase 1 | ✅ |
| 241 | Brand, generic, company, strength, MRP | Phase 1 | ✅ |
| 242 | **Generic alternative suggestions** | Phase 1 | ✅ |
| 243 | Names only from the database | Phase 1 | ✅ |
| 244 | Company list preset | Phase 1 | ✅ |
| 245 | Search results for misspellings | Phase 1 | ✅ |
| 246 | Alternative names linked | Phase 1 | ✅ |
| 247 | "Inactive" mark | Phase 1 | ✅ |
| 248 | "Prescription required" mark | Phase 1 | ✅ |
| 249 | **Cold-chain ("Keep refrigerated") flag** | Phase 1 | ✅ |
| 250 | Cold-chain items blocked from normal courier | Phase 1 | ✅ |
| 251 | **Controlled-medicine flag** | Phase 1 | ✅ |
| 252 | In-stock medicines first in search | Phase 1 | ✅ |

### Returns

| No. | Feature | Phase | Done |
|---|---|---|---|
| 253 | **Returns only for paikari** | Phase 1 | ✅ |
| 254 | 2–3 day limit, memo required | Phase 1 | ✅ |
| 255 | **Exchange, or 50% if cash** | Phase 1 | ✅ |
| 256 | "Returnable" mark | Phase 1 | ✅ |
| 257 | Search by date | Phase 1 | ✅ |
| 258 | Public: apply → approve → call | Phase 1 | ✅ |
| 259 | Login required to return | Phase 1 | ✅ |
| 260 | Reason or voice note required | Phase 1 | ✅ |
| 261 | **Partial returns** | Phase 1 | ✅ |
| 262 | At the purchase price | Phase 1 | ✅ |
| 263 | Credit and minus on next memo | Phase 1 | ✅ |
| 264 | Warning on credit after 1–2 months | Phase 1 | ✅ |
| 265 | **Return goes back to its own lot** | Phase 1 | ✅ |
| 266 | Lot reopens if finished | Phase 1 | ✅ |
| 267 | Returned goods resold | Phase 1 | ✅ |
| 268 | **Retail refunds — no profile** | Phase 1 | ✅ |
| 269 | Three kinds of search (memo/date/medicine name) | Phase 1 | ✅ |
| 270 | Return without invoice (rare) | Phase 1 | ✅ |
| 271 | Optional "Will receive return money" field | Phase 1 | ✅ |
| 272 | Return policy on retail memos | Phase 1 | ✅ |
| 273 | Offer returns at 50% of offer rate | Phase 1 | ✅ |
| 274 | **Delivery charge — after/before sending** | Phase 1 | ✅ |
| 275 | 50% on product advance | Phase 1 | ✅ |
| 276 | Return analysis (customer and product) | Phase 1 | ✅ |
| 277 | Warnings when mistakes are found | Phase 1 | ✅ |
| 278 | Auto warning for repeated mistakes | Phase 1 | ✅ |
| 279 | "Previously returned" mark | Phase 1 | ✅ |
| 280 | "Reprint" on memos | Phase 1 | ✅ |

### Public Market

| No. | Feature | Phase | Done |
|---|---|---|---|
| 281 | Sector icons on homepage | Phase 1 | ✅ |
| 282 | Own-area items first | Phase 1 | ✅ |
| 283 | Categories, search, filters, sorting | Phase 1 | ✅ |
| 284 | Personal recommendations | Phase 2 | ☐ |
| 285 | **Sector by budget** | Phase 1 | ✅ |
| 286 | **Premium sector (automatic)** | Phase 1 | ✅ |
| 287 | Moves in/out of premium when price changes | Phase 1 | ✅ |
| 288 | Only existing price ranges shown | Phase 1 | ✅ |
| 289 | **One product, many sellers** | Phase 2 | ✅ |
| 290 | Order by price + charge + rating | Phase 2 | ✅ |
| 291 | Central product catalog | Phase 2 | ✅ |
| 292 | **Shop's own page** | Phase 2 | ✅ |
| 293 | Follow a shop | Phase 2 | ✅ |
| 294 | Multiple photos, zoom | Phase 1 | ✅ |
| 295 | Variants (size, color) | Phase 1 | ✅ |
| 296 | Reviews and ratings, with photos | Phase 1 | ✅ |
| 297 | Only verified buyers review | Phase 1 | ✅ |
| 298 | Social share | Phase 1 | ✅ |
| 299 | Price history | Phase 2 | ✅ |
| 300 | Frequently bought together | Phase 2 | ✅ |
| 301 | **"Why didn't you buy" and price offers** | Phase 2 | ✅ |
| 302 | Offer cancelled after 24 hours | Phase 2 | ✅ |
| 303 | **"I was looking for this" button** | Phase 1 | ✅ |
| 304 | Grouped "looking for" requests in admin | Phase 1 | ✅ |
| 305 | Mixed cart, wishlist | Phase 1 | ✅ |
| 306 | Multi-address book | Phase 1 | ✅ |
| 307 | **Guest checkout (name/phone/address)** | Phase 1 | ✅ |
| 308 | COD toggle per product | Phase 1 | ✅ |
| 309 | COD hidden if any item lacks COD | Phase 1 | ✅ |
| 310 | **Daraz-style multi-seller split** | Phase 2 | ✅ |
| 311 | Separate courier per seller | Phase 2 | ✅ |
| 312 | Shipping tracking | Phase 1 | ✅ |
| 313 | Order history and profile | Phase 1 | ✅ |
| 314 | **"Buy again" reminder** | Phase 2 | ✅ |
| 315 | Edit and cancel within a set time | Phase 1 | ✅ |
| 316 | Change address (before packing) | Phase 1 | ✅ |
| 317 | "For whom" address field | Phase 1 | ✅ |
| 318 | Old orders auto-cancelled | Phase 1 | ✅ |
| 319 | Digital products and download limits | Phase 1 | ✅ |
| 320 | **All Companies sector** | Phase 1 | ✅ |
| 321 | Brand filter | Phase 1 | ✅ |

### Wholesale and Commission

| No. | Feature | Phase | Done |
|---|---|---|---|
| 322 | Category-based access | Phase 1 | ✅ |
| 323 | MOQ | Phase 1 | ✅ |
| 324 | Payment settings per customer | Phase 1 | ✅ |
| 325 | Wholesale dashboard (with own grade) | Phase 1 | ✅ |
| 326 | Pre-order time window | Phase 1 | ✅ |
| 327 | Bulk order upload | Phase 2 | ✅ |
| 328 | Custom quotation | Phase 2 | ✅ |
| 329 | **Wholesalers sell too** | Phase 2 | ✅ |
| 330 | Cannot sell in paikari | Phase 2 | ✅ |
| 331 | **Commission added on top of price** | Phase 2 | ✅ |
| 332 | **Separate commission per product** | Phase 2 | ✅ |
| 333 | Percentage and taka | Phase 2 | ✅ |
| 334 | Who owes how much, how many sold | Phase 2 | ✅ |
| 335 | **Commission reduction request and note** | Phase 2 | ✅ |
| 336 | My approval before publishing | Phase 2 | ✅ |
| 337 | Pending product count | Phase 2 | ✅ |
| 338 | Products without photos flagged | Phase 2 | ✅ |
| 339 | **Monthly commission statement and reply** | Phase 2 | ✅ |
| 340 | Suspended if no reply | Phase 2 | ✅ |
| 341 | Commission removed on cancellation | Phase 2 | ✅ |
| 342 | **Money outside the platform** | Phase 2 | ✅ |
| 343 | **White-label dashboard** | Phase 2 | ✅ |
| 344 | Only phone and address, name hidden | Phase 2 | ✅ |
| 345 | **Phone and address masked as \*\*\* after sale** | Phase 2 | ✅ |
| 346 | Notifications from my site | Phase 2 | ✅ |
| 347 | No promotional material in packages | Phase 2 | 🟡 |
| 348 | Penalty steps | Phase 2 | 🟡 |
| 349 | **Return money — COD and online separate** | Phase 2 | ✅ |
| 350 | Goods go straight to the seller | Phase 2 | ✅ |
| 351 | Online payment held | Phase 2 | 🟡 |
| 352 | Gradual trust | Phase 2 | ✅ |
| 353 | Security deposit | Future | ☐ |
| 354 | **Complaints seen by both** | Phase 2 | ✅ |
| 355 | Separate place for serious complaints | Phase 2 | ✅ |
| 356 | Returns go to the seller's profile | Phase 2 | ✅ |
| 357 | **Reports for sellers** | Phase 2 | ✅ |
| 358 | Viewed but not bought | Phase 2 | ✅ |
| 359 | **Supplier rank** | Phase 2 | ✅ |
| 360 | "Not received" option | Phase 2 | ✅ |
| 361 | Block buying own product | Phase 2 | ✅ |
| 362 | **Vacation mode** | Phase 2 | ✅ |
| 363 | Can't delete a product with active orders | Phase 2 | ✅ |
| 364 | Report copied products | Phase 2 | ✅ |
| 365 | Unseen order count and SMS | Phase 2 | 🟡 |

### Multi-Sector and Custom Orders

| No. | Feature | Phase | Done |
|---|---|---|---|
| 366 | **Sector-builder system** | Phase 2 | ✅ |
| 367 | Own fields and unit per sector | Phase 2 | ✅ |
| 368 | Fractional quantities | Phase 2 | ✅ |
| 369 | Chat/pre-order toggle per sector | Phase 2 | ✅ |
| 370 | **Color-size grid** | Phase 2 | ✅ |
| 371 | Only that color greys out when sold out | Phase 2 | ✅ |
| 372 | Separate price and photo per color/size | Phase 2 | ✅ |
| 373 | Sizes on wooden items | Phase 2 | ✅ |
| 374 | Basic stock for sellers (best/worst sellers, own purchase price) | Phase 2 | ✅ |
| 375 | One product in many categories, one stock | Phase 2 | ✅ |
| 376 | Prohibited category list | Phase 2 | ✅ |
| 377 | Wood/clothing returns through the same Return section | Phase 2 | ✅ |
| 378 | **Custom orders — wood and clothing** | Phase 2 | ✅ |
| 379 | Everyone can order | Phase 2 | ✅ |
| 380 | **Measurement form (A to Z)** | Phase 2 | ✅ |
| 381 | Price changes with wood type | Phase 2 | ✅ |
| 382 | Fabric type and details | Phase 2 | ✅ |
| 383 | Photo of the space | Phase 2 | ✅ |
| 384 | **Saved measurement profiles (by name)** | Phase 2 | ✅ |
| 385 | Several profiles in one order | Phase 2 | ✅ |
| 386 | Measurement date and reminder when old | Phase 2 | ✅ |
| 387 | Previous orders list per profile | Phase 2 | ✅ |
| 388 | Measurements finalized with both sides' consent | Phase 2 | ✅ |
| 389 | Trial step (clothing) | Phase 2 | ✅ |
| 390 | **One craftsman per order** | Phase 2 | ✅ |
| 391 | Shift and share option | Phase 2 | ✅ |
| 392 | Shift record | Phase 2 | ✅ |
| 393 | **Wages and progress by percentage** | Phase 2 | ✅ |
| 394 | Not complete below 100% | Phase 2 | ✅ |
| 395 | Photo mandatory with percentage | Phase 2 | ✅ |
| 396 | Buyer doesn't see craftsman names | Phase 2 | ✅ |
| 397 | I assign jobs | Phase 2 | ✅ |
| 398 | Active job count | Phase 2 | ✅ |
| 399 | No job sent before wages agreed | Phase 2 | ✅ |
| 400 | Craftsman owed/paid accounting | Phase 2 | ✅ |
| 401 | Send "Materials needed" | Phase 2 | ✅ |
| 402 | **Chat (details in Chapter 23)** | Phase 2 | ✅ |
| 403 | "Send measurements" button in chat | Phase 2 | ✅ |
| 404 | "Send offer" button in chat | Phase 2 | ✅ |
| 405 | Numbers and links blocked | Phase 2 | ✅ |
| 406 | I can see every chat | Phase 2 | ✅ |
| 407 | Measurements and offers as separate cards | Phase 2 | ✅ |
| 408 | Notification if the craftsman doesn't reply | Phase 2 | ✅ |
| 409 | Advance percentage, goes to the craftsman | Phase 2 | ✅ |
| 410 | **No returns on custom orders** | Phase 2 | ✅ |
| 411 | "Work not done right" complaint | Phase 2 | ✅ |
| 412 | Cancellation rules before/after work starts | Phase 2 | ✅ |
| 413 | Urgent order and extra charge | Phase 2 | ✅ |
| 414 | Wood deducted from stock | Phase 2 | ✅ |
| 415 | No commission on custom orders | Phase 2 | ✅ |

### Food, Gaming, Help

| No. | Feature | Phase | Done |
|---|---|---|---|
| 416 | Location-based restaurants | Phase 2 | ✅ |
| 417 | Menu management | Phase 2 | ✅ |
| 418 | Per-item "Sold out today" toggle | Phase 2 | ✅ |
| 419 | Opening hours and auto-close | Phase 2 | ✅ |
| 420 | **Cooking time given when confirming** | Phase 2 | ✅ |
| 421 | Timer on the buyer's screen | Phase 2 | ✅ |
| 422 | Notification if not confirmed | Phase 2 | ✅ |
| 423 | Home delivery / eat there | Phase 2 | ✅ |
| 424 | **Custom food** | Phase 2 | ✅ |
| 425 | **Central ingredient list** | Phase 2 | ✅ |
| 426 | Restaurant ticks what it has | Phase 2 | ✅ |
| 427 | Restaurant sets its own prices | Phase 2 | ✅ |
| 428 | Request to add to the list | Phase 2 | ✅ |
| 429 | "Sold out today" on ingredients | Phase 2 | ✅ |
| 430 | Maximum ingredient limit | Phase 2 | ✅ |
| 431 | Rules for combinations that don't work | Phase 2 | ✅ |
| 432 | Save favorite combinations | Phase 2 | ✅ |
| 433 | Kitchen print | Phase 2 | ✅ |
| 434 | **Three delivery-charge options** | Phase 2 | 🟡 |
| 435 | Maximum distance and minimum charge | Phase 2 | ☐ |
| 436 | Calculated by road distance | Phase 2 | ☐ |
| 437 | Advance payment | Phase 2 | ✅ |
| 438 | Memo in the restaurant's name | Phase 2 | ✅ |
| 439 | Own or platform delivery | Phase 2 | ✅ |
| 440 | Commission per restaurant | Phase 2 | ✅ |
| 441 | Restaurant dashboard | Phase 2 | ✅ |
| 442 | Complaints with photos | Phase 2 | ✅ |
| 443 | Two orders on one delivery | Phase 2 | ✅ |
| 444 | Game and package list | Phase 2 | ✅ |
| 445 | **Game ID verification (shows name)** | Phase 2 | ✅ |
| 446 | **Automatic top-up** | Phase 2 | 🟡 |
| 447 | Manual top-up backup | Phase 2 | ✅ |
| 448 | Auto refund on failed top-up | Phase 2 | ✅ |
| 449 | Online payment only | Phase 2 | ✅ |
| 450 | Help posts and approval | Phase 2 | ✅ |
| 451 | **Job opportunities for unemployed young people** | Phase 2 | ✅ |
| 452 | Page owners finding moderators | Phase 2 | ✅ |
| 453 | Product card in comments | Phase 2 | ✅ |
| 454 | Card shows photo, name, price, "View" button | Phase 2 | ✅ |
| 455 | Card follows visibility rules | Phase 2 | ✅ |
| 456 | No photos allowed | Phase 2 | ✅ |
| 457 | "Still needed?" after 30 days | Phase 2 | ✅ |
| 458 | Post limit | Phase 2 | ✅ |
| 459 | "Transactions not our responsibility" stated clearly | Phase 2 | ✅ |

### Delivery and Courier

| No. | Feature | Phase | Done |
|---|---|---|---|
| 460 | **Automatic location** | Phase 1 | ✅ |
| 461 | Map pin | Phase 1 | 🟡 |
| 462 | "How to get there" field | Phase 1 | ✅ |
| 463 | **Three-layer delivery charge order** | Phase 1 | ✅ |
| 464 | Mandatory product charge highest | Phase 1 | ✅ |
| 465 | **Courier list in profile** | Phase 1 | ✅ |
| 466 | Unlimited couriers | Phase 1 | ✅ |
| 467 | Charges entered by hand (no API) | Phase 1 | ✅ |
| 468 | **Zone + first kg + additional kg** | Phase 1 | ✅ |
| 469 | COD charge percentage | Phase 1 | ✅ |
| 470 | Weight rounding rule | Phase 1 | ✅ |
| 471 | All couriers side by side at checkout | Phase 1 | ✅ |
| 472 | Flat charge when no weight | Phase 1 | ✅ |
| 473 | Reminders to add weight | Phase 1 | ✅ |
| 474 | Courier rate check after 3 months | Phase 1 | ✅ |
| 475 | **Delivery charge draft (add extra)** | Phase 1 | ✅ |
| 476 | "Product on the way" shown | Phase 1 | ✅ |
| 477 | **50-50 courier charge in wholesale** | Phase 2 | ✅ |
| 478 | By weight and carton | Phase 2 | ✅ |
| 479 | **Advance delivery charge** | Phase 1 | ✅ |
| 480 | **Pickup point** | Phase 2 | ✅ |
| 481 | Only for name-visible sellers | Phase 2 | ✅ |
| 482 | Reminder after 3 days | Phase 2 | ✅ |
| 483 | **Delivery person's list** | Phase 2 | ✅ |
| 484 | Grouped by area | Phase 2 | ✅ |
| 485 | "Arrived" mark and notification | Phase 2 | ✅ |
| 486 | Delivery person's cash accounting | Phase 2 | ✅ |
| 487 | **Delivery confirmation by OTP or photo** | Phase 1 | ✅ |
| 488 | OTP turns draft sale into complete sale automatically | Phase 1 | ✅ |
| 489 | "Received" tap / auto-confirm for courier deliveries | Phase 1 | 🟡 |
| 490 | **Delivery and service rating** | Phase 2 | ✅ |
| 491 | Ratings per delivery person and staff, low-rating alert | Phase 2 | ✅ |
| 492 | Courier integration and tracking | Phase 1 | ✅ |
| 493 | Delivery zones | Phase 1 | ✅ |
| 494 | "Delivery by arrangement" | Phase 2 | ✅ |

### Pre-Order, Memos, Chat, Wallet

| No. | Feature | Phase | Done |
|---|---|---|---|
| 495 | **Pre-order is one system** | Phase 1 | ✅ |
| 496 | Order goes to whoever turned it on | Phase 1 | ✅ |
| 497 | Medicine by company/product | Phase 1 | ✅ |
| 498 | Other sectors by size and color | Phase 2 | ✅ |
| 499 | Limited options on pre-orders | Phase 2 | ✅ |
| 500 | **Accumulated pre-orders shown together** | Phase 1 | ✅ |
| 501 | Not linked to stock | Phase 1 | ✅ |
| 502 | Told after goods arrive | Phase 1 | ✅ |
| 503 | Reserve or general stock | Phase 1 | ✅ |
| 504 | **Draft memo (editable)** | Phase 1 | ✅ |
| 505 | Buyer also sees on "Receive" | Phase 1 | ✅ |
| 506 | Advance field and refund rules | Phase 1 | ✅ |
| 507 | Sorry note if goods don't come | Phase 1 | ✅ |
| 508 | Red and notification when time passes | Phase 1 | ✅ |
| 509 | Short supply → earlier orders first | Phase 1 | ✅ |
| 510 | **Nice memos in every sector** | Phase 1 | ✅ |
| 511 | Every step on its own memo line | Phase 1 | ✅ |
| 512 | Year in memo number | Phase 1 | ✅ |
| 513 | **Thermal receipt printer format (58/80 mm)** | Phase 1 | ✅ |
| 514 | Correction → new number, old cancelled | Phase 1 | ✅ |
| 515 | PDF, email, **WhatsApp** | Phase 1 | 🟡 |
| 516 | Print-friendly format | Phase 1 | ✅ |
| 517 | **Automatic status messages** | Phase 1 | 🟡 |
| 518 | Four channels (inbox/push/SMS/email) | Phase 1 | 🟡 |
| 519 | Controlled from admin | Phase 1 | ✅ |
| 520 | **Notes and voice notes** | Phase 1 | ✅ |
| 521 | Time and photo with orders | Phase 1 | ✅ |
| 522 | **Q&A** | Phase 1 | ✅ |
| 523 | Unanswered → comes to me | Phase 2 | ✅ |
| 524 | **Wallet — two parts** | Phase 2 | ✅ |
| 525 | **Aqua Token — two levels** | Phase 2 | ✅ |
| 526 | Seller token only on their products | Phase 2 | ✅ |
| 527 | Token back on cancellation | Phase 2 | ✅ |
| 528 | Offer and token don't combine | Phase 2 | ✅ |

### Admin, Staff, Security

| No. | Feature | Phase | Done |
|---|---|---|---|
| 529 | Product management (all options) | Phase 1 | 🟡 |
| 530 | **All controls on the customer profile** | Phase 1 | ✅ |
| 531 | All transactions on one page | Phase 1 | ✅ |
| 532 | Application review and license verification | Phase 1 | ✅ |
| 533 | Order management | Phase 1 | ✅ |
| 534 | **Sector management** | Phase 2 | ✅ |
| 535 | Central list management | Phase 1 | ✅ |
| 536 | Holiday / closed days setting | Phase 1 | ✅ |
| 537 | Sales reports (separate per sector) | Phase 1 | ✅ |
| 538 | **Monthly ranking report** | Phase 1 | ✅ |
| 539 | Boxes per day, ideal stock level | Phase 1 | ✅ |
| 540 | Which medicine earns most | Phase 1 | ✅ |
| 541 | **Shop expenses and net profit** | Phase 1 | ✅ |
| 542 | Recurring expenses (rent, salary) | Phase 1 | ✅ |
| 543 | **Seasonal forecast** | Phase 2 | ✅ |
| 544 | **Inactive customer list** | Phase 2 | ✅ |
| 545 | Comparison with last year | Phase 2 | ✅ |
| 546 | **Two-way reorder suggestion** | Phase 1 | ✅ |
| 547 | What finished today | Phase 1 | ✅ |
| 548 | **End-of-day cash reconciliation** | Phase 1 | ✅ |
| 549 | Staff name on every memo | Phase 1 | ✅ |
| 550 | **Asked for but not available** | Phase 1 | ✅ |
| 551 | Overnight report generation | Phase 2 | ☐ |
| 552 | Day-by-day breakdown | Phase 1 | ✅ |
| 553 | Data export (CSV/Excel) | Phase 1 | ✅ |
| 554 | **Nightly summary by WhatsApp/SMS** | Phase 1 | 🟡 |
| 555 | **VAT / Mushak report** for the accountant | Phase 2 | ✅ |
| 556 | VAT fields in database from the start | Phase 1 | ✅ |
| 557 | **Activity log** | Phase 1 | ✅ |
| 558 | Staff roles and permissions | Phase 1 | ✅ |
| 559 | Create new roles | Phase 1 | ✅ |
| 560 | **Separate account for every staff member** | Phase 1 | ✅ |
| 561 | Device login limit | Phase 1 | ✅ |
| 562 | Close staff account, records stay | Phase 1 | ✅ |
| 563 | Separate confirmation for big changes | Phase 1 | ✅ |
| 564 | Log of who viewed whose data | Phase 1 | ✅ |
| 565 | Temporary powers for sub-admin | Phase 2 | ✅ |
| 566 | Notifications by importance | Phase 1 | ✅ |
| 567 | **30-day "Deleted" bin** | Phase 1 | ✅ |
| 568 | IP block | Phase 1 | ✅ |
| 569 | Time-limited suspension | Phase 1 | ✅ |
| 570 | Suspicious login records | Phase 1 | ✅ |
| 571 | Lock after repeated wrong passwords | Phase 1 | ✅ |

### Payments, Legal, Technical

| No. | Feature | Phase | Done |
|---|---|---|---|
| 572 | bKash, Nagad, Rocket, bank, card | Phase 1 | 🟡 |
| 573 | COD and advance | Phase 1 | 🟡 |
| 574 | "Paid / Due" on invoices | Phase 1 | ✅ |
| 575 | Terms of Service and Privacy Policy | Phase 1 | ✅ |
| 576 | **Controlled-medicine sale register** (required details, printable) | Phase 1 | ✅ |
| 577 | Prescription marketplace stub | Future | ☐ |
| 578 | Verified badge and trust signals | Phase 1 | ✅ |
| 579 | WhatsApp / Messenger button | Phase 1 | ✅ |
| 580 | FAQ / Help center | Phase 1 | ✅ |
| 581 | Bangla and English toggle | Phase 1 | ✅ |
| 582 | Product names never auto-translated | Phase 1 | ✅ |
| 583 | Web-app sync | Phase 1 | ✅ |
| 584 | SEO | Phase 1 | ✅ |
| 585 | Mobile-friendly and fast loading | Phase 1 | 🟡 |
| 586 | **Text-size option** | Phase 1 | ✅ |
| 587 | Everything works on the web too | Phase 1 | 🟡 |
| 588 | App update message | Phase 1 | ✅ |
| 589 | Main photo selection | Phase 1 | ✅ |
| 590 | **Daily backup, 30 days** | Phase 1 | ✅ |
| 591 | Backup stored outside the server | Phase 1 | 🟡 |
| 592 | Monthly backup test | Phase 1 | ✅ |
| 593 | Work not lost when internet drops | Phase 1 | 🟡 |
| 594 | Bulk import of old data | Phase 1 | ✅ |
| 595 | **Correct system time** | Phase 1 | ✅ |
| 596 | Monthly report date in settings | Phase 1 | 🟡 |
| 597 | Store user behavior data from day one | Phase 1 | ✅ |
| 598 | Old data stored separately | Phase 2 | ☐ |
| 599 | Auto image compression | Phase 1 | ✅ |
| 600 | Low-quality image first | Phase 1 | ✅ |
| 601 | Handle festival order peaks | Phase 1 | 🟡 |
| 602 | Updates at night or on closed days | Phase 1 | ✅ |
| 603 | Reward points and referral | Phase 2 | 🟡 |
| 604 | Bundle deals and flash sale | Phase 2 | ☐ |
| 605 | Abandoned cart reminder | Phase 2 | ✅ |
| 606 | Price-drop alert | Phase 2 | ✅ |
| 607 | Support tickets | Phase 2 | ✅ |
| 608 | Facebook Pixel and Analytics | Future | ☐ |
| 609 | Affiliate and bulk SMS | Future | ☐ |
| 610 | Credit ledger (baki khata) | Future | ☐ |
| 611 | Supplier/company dues (in the ledger book) | Future | ☐ |
| 612 | Full PharmaTrack sync | Future | ☐ |
| 613 | Service / coaching booking | Future | ☐ |

