# PharmaTrack connection (items 55, 138 and 612)

PharmaTrack is the owner's pharmacy program (the project `pharmacy-stock-management-main`). This site and PharmaTrack talk in JSON over https. Each side proves
itself with a password sent as `Authorization: Bearer <password>`.

There are two directions, both optional:

| Direction | What it does | Built on |
|---|---|---|
| **This site to PharmaTrack** | A medicine staff mark "not available" on an order is added to the short list in PharmaTrack (spec ch 7, step 5) | PharmaTrack's `/integrations/ecommerce/short-list`, added to PharmaTrack for this |
| **PharmaTrack to this site** | PharmaTrack writes its shop sales, returns and stock counts here, so both programs use one stock (spec ch 6, "Master Stock Sync") | This site's `/webhooks/pharmatrack/...`; PharmaTrack now keeps a stock ledger and calls it |

## Setting it up (the short list)

1. In PharmaTrack, put a long random string in its `.env` as `ECOMMERCE_API_TOKEN=...` and run `php artisan config:clear`. Until it is set, PharmaTrack's endpoints stay closed.
2. On this site: **Admin > Connections > Your pharmacy software > PharmaTrack**. Fill in **PharmaTrack address** (https, a public website) and **Key from PharmaTrack**
   (the same string), keep **Add medicines we could not supply to the PharmaTrack short list** on `yes`, switch it on and set it to **Live**. Press **Test**.
3. That is all. From then on, every "not available" mark sends the medicine to PharmaTrack's short list. **Admin > PharmaTrack** lists each message and what came of it.

Nothing moves while the link is off or in test mode. A medicine is told once, however many times staff change their mind about the line.

## What this site calls (this site to PharmaTrack)

All paths are after the address the owner gave. Any `2xx` answer means accepted; anything else is kept as failed, tried again after 1 minute, 10 minutes and an hour,
and can be sent again by the owner from **Admin > PharmaTrack**.

### `GET /integrations/ecommerce/ping`

`{"ok": true}`. For the **Test** button.

### `POST /integrations/ecommerce/short-list`

```json
{ "ref": "line:512", "items": [ { "name": "Seclo", "strength": "20 mg", "form": "capsule", "company": "Square", "unit": "box", "quantity": 3 } ] }
```

PharmaTrack matches each item to a medicine by **name** (ignoring case) and **strength** (ignoring spaces and case), then by **company** when several share the name. Only one
clear match counts: a different strength, or a tie, is never guessed. A matched medicine goes on the short list and in company orders exactly like a hand-made add. An item
that matches nothing is added under the name it came with, as if typed. An item already on the list is left alone, so the same message twice does nothing the second time.

```json
{ "status": "ok", "ref": "line:512", "results": [ { "name": "Seclo", "result": "added|exists|revived", "matched": true, "medicine_id": 12, "short_list_item_id": 40 } ] }
```

A wrong or missing key is `401`. A bad message is `422` and adds nothing.

### `POST /integrations/ecommerce/online-sales`

When an online order is finished, this site sends (lines carry the medicine's name, strength and company, and the quantity and price in pieces as well as the ids):

```json
{ "ref": "invoice:M2026-000777", "sold_at": "2026-10-25T10:30:00+06:00",
  "lines": [ { "medicine_id": 12, "barcodes": ["8901234567890"], "units": 2, "unit_price": 95 } ] }
```

Only goods that were really supplied are sent. PharmaTrack answers this now (see its document).

## One stock, both ways (Master Stock Sync)

PharmaTrack now keeps a stock ledger and counter sales (its own document: `docs/ecommerce-sync.md` in that project). **Quantities travel as pieces** (`"unit": "piece"`); a stock unit here (a box, or a loose item) is converted with this site's own pieces per box, and a figure within 0.05 of a whole piece is that piece, so the two programs do not drift by a fraction of a piece.

- **PharmaTrack to this site:** a shop sale (`/webhooks/pharmatrack/sales`), a return, receipt or loss (`/adjustments`, `units` signed) and a stock count (`/counts`, an absolute figure). A line names its medicine by `medicine_id`, `barcode` or **`name`** (+ `strength`, `company`), and a name that matches nothing or more than one medicine is reported and never guessed. A positive adjustment needs the medicine to have a purchase here already (it needs a cost); otherwise that line is reported in `failed`.
- **This site to PharmaTrack:** every finished online order (`POST {address}/integrations/ecommerce/online-sales`) and every receipt, customer return and admin correction (`.../adjustments`), in pieces, by name. What PharmaTrack itself just told us is never told back. Both are on while **Tell PharmaTrack about finished online orders and every stock change** is `yes` (the default) and the link is on and Live.
- The stock page (`GET /webhooks/pharmatrack/stock`) lists `company` and `pieces_per_unit` for each medicine, and takes `ids=1,2,3` to ask for chosen medicines only.
- Messages sent to PharmaTrack go through the queue, so **a queue worker must be running** (`php artisan queue:work`).

## What PharmaTrack calls: the stock and the older line formats

Switch the link on and set it to **Live**; saving makes the password. **Admin > PharmaTrack** shows the addresses and the button **Make the password**
(shown once; copy it into PharmaTrack). Base address: `https://<this site>/webhooks/pharmatrack`. Wrong or missing password: `401 {"message":"Unauthorized."}`.

Quantities are in this site's stock units: **boxes** for a medicine sold by the box, **pieces** otherwise (`unit` in the stock list says which).
A line names its medicine by `medicine_id` (this site's id) or by `barcode` (any barcode of that medicine).

### `GET /stock?page=1&per_page=500&updated_since=2026-10-01T00:00:00Z`

```json
{ "data": [ { "medicine_id": 12, "barcodes": ["8901234567890"], "brand": "Napa", "strength": "500 mg", "form": "tablet",
              "unit": "box", "on_hand": 8, "mrp_unit": 100 } ],
  "next_page": 2 }
```

`updated_since` returns only medicines whose stock moved since then. `next_page` is `null` on the last page.

### `POST /sales`: a sale made in PharmaTrack

```json
{ "ref": "PT-1001", "sold_at": "2026-10-25T10:30:00+06:00",
  "lines": [ { "barcode": "8901234567890", "units": 2, "unit_price": 95 }, { "medicine_id": 14, "units": 1 } ] }
```

Takes the goods off this stock. A sale that already happened is **never refused**: if this stock holds less than was sold, what there is is taken and the rest
is reported in `short`, and the owner is told. A price below cost is recorded as a loss, not refused.

```json
{ "status": "ok|partial|duplicate", "applied": [ { "medicine_id": 12, "units": 2 } ],
  "short": [ { "medicine_id": 14, "brand": "Seclo", "sold": 6, "here": 4 } ], "unknown": ["0000000000000"] }
```

### `POST /counts`: a stock count made in PharmaTrack

```json
{ "ref": "COUNT-1", "lines": [ { "barcode": "8901234567890", "on_hand": 7 }, { "medicine_id": 14, "on_hand": 0 } ] }
```

Sets the on-hand figure for each medicine, as a correction with the reason "Count from PharmaTrack".

### `GET /ping`

`{"ok": true}`. For checking the password.

### References and repeats

Every `ref` (up to 80 characters) is applied **once**. Sending the same `ref` again answers `{"status":"duplicate"}` and changes nothing, so a message that
timed out can be sent again safely. A message that **failed** (for example, no owner account) is applied when it is sent again. A bad message is `422 {"message": "..."}`
and changes nothing.

## Safety

- Passwords are stored encrypted and never shown again; a new one stops the old one at once. PharmaTrack compares its key in constant time and stays closed while none is set.
- The PharmaTrack address must be https and a public website (never this machine or a private network).
- Every message, in either direction, is written down on **Admin > PharmaTrack** with what it did, in the language of whoever opens the screen.
