import io
import re

# ---------------------------------------------------------------- checklist
p = 'D:/projects/ecommerce/docs/FEATURE-CHECKLIST.md'
s = io.open(p, encoding='utf-8', newline='').read()
nl = '\r\n' if '\r\n' in s else '\n'
s = s.replace('\r\n', '\n')


def row(n, name, old, new):
    global s
    pat = re.compile(r'^(\| %d \| .*? \| )%s( \|)$' % (n, re.escape(old)), re.M)
    assert pat.search(s), n
    s = pat.sub(lambda m: m.group(1) + new + m.group(2), s, count=1)


# the phase column comes before the mark, so match the mark at the end of the row
for n, new in [(17, '✅'), (18, '✅'), (436, '✅'), (577, '✅'), (608, '✅'), (609, '✅'), (613, '✅'), (612, '🟡')]:
    pat = re.compile(r'^(\| %d \| .*\| )☐( \|)$' % n, re.M)
    assert pat.search(s), n
    s = pat.sub(lambda m: m.group(1) + new + m.group(2), s, count=1)

s = s.replace('| All features | 581 | 24 | 8 | 613 |', '| All features | 588 | 25 | 0 | 613 |', 1)

old55 = "- 55, 107: PharmaTrack sync and phone-camera scanning are missing; the data and scanner (USB or Bluetooth) lookups work."
new55 = ("- 55, 612: the short list half of the PharmaTrack connection is built and was tried against the real PharmaTrack project (a medicine marked Not available goes to its short list, "
         "which needed a small receiving endpoint added to that project). One shared stock is not: PharmaTrack keeps no on-hand stock and records no shop sales yet, so this site's half is ready and documented (`docs/pharmatrack-sync.md`) and waits for it.\n"
         "- 107: phone-camera scanning is missing; the data and scanner (USB or Bluetooth) lookups work.")
assert old55 in s
s = s.replace(old55, new55, 1)
io.open(p, 'w', encoding='utf-8', newline='').write(s.replace('\n', nl))

# ---------------------------------------------------------------- overview
p = 'D:/projects/ecommerce/docs/overview.md'
s = io.open(p, encoding='utf-8', newline='').read()
nl = '\r\n' if '\r\n' in s else '\n'
s = s.replace('\r\n', '\n')

old = "so item 436 stays not built and the screens say it is an estimate."
assert old in s
s = s.replace(old, "so the screens say it is an estimate; real road distance from Google came later (Step 12j).", 1)

rows = [
    "| Step 12j: real road distance from Google (spec ch 17, item 436): with a Google Maps key pasted in **Connections** (Geocoding API and Distance Matrix API switched on in the Google Cloud project), the by-the-kilometre charge, the longest distance and the buyer's preview use the real road distance between the restaurant and the buyer (`RoadDistance`); the answer is kept for 7 days for the same two places. Without a key, or when Google refuses or is slow, the charge falls back to the earlier estimate (straight line times `food.road_factor`) and the screens say which it is; each order keeps whether its distance was by road (`food_orders.distance_by_road`). The restaurant's settings can find the restaurant's place from its written address (`Locator::forward`). The card's Try button checks one address and one distance. 11 tests (`RoadDistanceTest`, Google's answers simulated). Not verified live: a real Google call, as there was no key here | ✅ |",
    "| Step 12k: Facebook Pixel and Google Analytics (spec ch 27, item 608): two cards in **Connections** under Visitor statistics and ads, each asking for one id (Pixel ID of 10 to 20 digits; Measurement ID like G-ABC123XYZ4; anything else is refused so nothing odd can enter the page). Once on and Live, every public page loads the tags and the app reports page views, product views, adds to cart, searches and orders (`lib/track.ts`, `Tags`); Google's own page view is turned off because the app sends them. Staff, the owner and the admin screens are never counted; nothing is sent in test mode or while off. 12 tests (`TagsTest`). Not verified live: receipt of events by Meta or Google, which needs the owner's ids | ✅ |",
    "| Step 12l: bulk texts and affiliates (spec ch 27 extras, item 609): **Bulk texts** sends a campaign through the SMS service the owner connected, to people chosen by type, tier, area, days since their last order and whether they owe us, with a count of people, texts and the day's limit before sending; every text ends with a stop link that works without signing in and anyone who stops is never texted again; campaigns can be stopped and resumed; speed and the day's limit are settings (`BulkSms`, `SendCampaignSms`). **Affiliates** gives a promoter a link (`/a/CODE`) that leaves a code on the visitor for a number of days; a share of each finished order is earned, taken back on a return or cancellation, and paid out in full when the owner writes a payout down (cash, bKash, Nagad, Rocket or bank; the money moves outside the site). 23 tests (`BulkSmsTest`) and 21 (`AffiliateTest`). Not verified live: delivery through a real SMS gateway | ✅ |",
    "| Step 12m: the supplier app and phone notifications (spec ch 3, items 17 and 18): **/supplier** is an installable app of its own (its own manifest, start page and id) for a seller, a restaurant owner, an MPO and a craftsman, showing the orders waiting and one list of their own tools; a seller sees a way across in the shop, the supplier app has a way back, and one login serves both. Phone and browser notifications (Web Push): switch the card on in **Connections** and the site makes its own signing keys; a person says yes once on their device (in the inbox); the Messages screen has a Push tick per message; a dead subscription is forgotten. 9 tests (`SupplierAppTest`) and 18 (`PushTest`, fake sender). Not built: a Play Store or App Store listing. Not verified live: a notification arriving on a real device | ✅ |",
    "| Step 12n: the PharmaTrack connection (spec ch 6 and 7, items 55, 138 and 612), built against the real PharmaTrack project (`pharmacy-stock-management-main`): a medicine staff mark Not available on an order is sent once to PharmaTrack's short list (`PharmaTrack::announceMissed`, through the queue, never stopping the check). PharmaTrack got a small receiving endpoint for it (`/integrations/ecommerce/short-list`, closed until `ECOMMERCE_API_TOKEN` is set, matching by name, strength and company without guessing, the same message twice adds it once; 10 tests there, all 397 of its tests pass; uncommitted in that project). **Admin > PharmaTrack** lists every message in both directions in the language of whoever opens it, with a retry for failed ones. The other direction (PharmaTrack writing its shop sales and counts here, password-protected and safe against repeats) is built and documented in `docs/pharmatrack-sync.md`, but one shared stock waits for PharmaTrack to keep stock and record sales. 30 tests (`PharmaTrackTest`). Setup: set `ECOMMERCE_API_TOKEN` in PharmaTrack, then paste its address and the same key in Connections | 🟡 |",
    "| Step 12o: service booking and the prescription marketplace stub (spec ch 13 extras, items 613 and 577): a provider gives a service with times and places (**Sell > Services**); a buyer asks for a time without signing up and keeps a private link; the provider confirms or declines (the buyer is told, a signed-in buyer by message), a request nobody answers is declined by itself, the buyer can cancel before the cut-off, the day before a confirmed booking is remembered once; payment is made to the provider in person. 27 tests (`BookingTest`). The **Prescription marketplace** page explains what is coming and takes a phone number for a list of people who want it (**Admin > Prescription marketplace**); the tables for requests and answers are ready; 6 tests (`RxMarketTest`). The marketplace itself is not built | ✅ |",
]
at = next(i for i, l in enumerate(s.split('\n')) if l.startswith('| Step 12i: personal recommendations'))
lines = s.split('\n')
lines[at + 1:at + 1] = rows
s = '\n'.join(lines)
io.open(p, 'w', encoding='utf-8', newline='').write(s.replace('\n', nl))
print('ok')
