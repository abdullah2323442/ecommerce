"""Brings the stored QA results up to date with what was proved by hand in the live browser after 23 October.

Run from docs/qa:  python update_results.py      (then rebuild the two reports)
Each entry replaces the checks, notes and screenshots of one feature's results/N.json. Screenshots come from shots3/.
"""
import json
import shutil
from pathlib import Path

ROOT = Path(__file__).parent
RESULTS = ROOT / "results"
SHOTS2 = ROOT / "shots2"
SHOTS3 = ROOT / "shots3"

# n -> (status, did, checks, shots[(source in shots3, file name in shots2, caption, role, url)], notes)
UPDATES = {
    207: ("pass", "Set the warning level, then looked at the owner's dashboard when an offer was down to it.",
          ["The owner sets how many boxes left should trigger a warning",
           "When an offer is down to that level, the dashboard says which offer is nearly sold out, and the line opens the offers page",
           "Everyone who runs offers gets one notice, not one every five minutes",
           "When new stock lifts the offer above the level the warning is armed again, and the line is gone once the level is back to 2"],
          [("n1_offer_alert.jpg", "f207_2.jpg", "The owner's dashboard: the offer is nearly sold out", "admin", "/dashboard")], []),
    347: ("pass", "Reported a card in the package as the buyer, answered it as the seller, and read it as the owner.",
          ["A buyer whose order was sent can report 'there was a card or leaflet in the package' from the order page",
           "It is counted apart from ordinary complaints on the seller's record",
           "The seller can say it is solved or cannot be solved, and cannot 'take it back' (nothing is sent back)",
           "The owner can only close the case, and uses a penalty step if it is true; a return case beside it still has 'Send it back to the seller'"],
          [("n2_promo_report.jpg", "f347_2.jpg", "The buyer's order page: waiting for the seller", "visitor", "/market/orders/..."),
           ("n3_promo_admin.jpg", "f347_3.jpg", "The owner's cases page: only 'Close the case'", "admin", "/admin/market/cases")], []),
    348: ("pass", "Applied a warning and then 'Switch selling off' to a seller, and lifted it.",
          ["A warning was recorded and the seller was told",
           "'Switch selling off' was recorded, the seller was told, and the account showed that selling was off",
           "'Lift it' switched selling on again"],
          [], ["Holding payouts still holds nothing, because there are no online payouts yet."]),
    529: ("skipped", "Tested by hand on the owner's Market screen, and added a product video as a seller.",
          ["A seller can add one short MP4 or WebM video (up to 20 MB) to a product, and a new one replaces the old",
           "The product page plays it under the photos, for whoever may see the product",
           "SEO title and description, return rules, commission and the other options were tried earlier"],
          [("n4_product_video.jpg", "f529_3.jpg", "A product page with its video", "visitor", "/market/1")],
          ["The advance delivery charge is still open, because it waits for online payment."]),
    585: ("skipped", "Opened the market and a product on a phone-sized screen and measured what is downloaded.",
          ["Shop pictures now come as a 960 pixel copy (29 to 88 KB each) instead of the 2400 pixel original (88 to 197 KB)",
           "Moving to another market page costs 23 KB, because the 342 KB Bangla dictionary is no longer sent again",
           "Pictures below the first screen load only when they come near it (81 of 85 are lazy)"],
          [], ["Loading speed on a real phone connection was not measured; these are sizes and timings on this computer."]),
    599: ("pass", "Put a 4000 x 3000 photo through the shop's own image compressor.",
          ["A 4.4 MB photo of 4000 x 3000 pixels became 314 KB at 1600 x 1200 pixels, 93 percent smaller",
           "Pictures people upload are made smaller on the way in; a PDF is stored as it came"],
          [], []),
    600: ("pass", "Checked the saved copy of the same large photo.",
          ["The saved copy is a progressive JPEG, which shows a rough picture first and sharpens as the rest arrives"],
          [], []),
    601: ("skipped", "Ran 300 requests, 30 at a time, over the home page, the market, a product, the login page and a redirect.",
          ["No request failed, none returned an error and none was throttled",
           "One worker answers about five pages a second on this computer (about 0.4 seconds a page, without opcache)"],
          [], ["A festival peak needs several PHP workers with opcache on the real server; that sizing could not be proved on this computer."]),
    190: ("pass", "Copied the prices, cut the connection, sold a strip of Napa, and reconnected.",
          ["The page shows 'No internet. Will send later.' and still finds medicines from the saved copy of the prices",
           "A sale made offline is kept on the device ('1 waiting to be sent') and the memo prints without any request",
           "When the connection came back the sale was sent by itself and the real memo M2026-000047 was made"],
          [("n6_offline_synced.jpg", "f190_2.jpg", "After reconnecting: the sale is sent and has its real memo number", "admin", "/admin/counter/offline")], []),
    191: ("pass", "Made a sale offline and looked at its temporary number and the clash alert after it was sent.",
          ["The sale got a temporary memo number (T-AZK-0001) while offline",
           "When it was sent, the shop gave the real number (M2026-000047) in its place",
           "Because the stock of that medicine had gone below zero, the owner was told: 'Offline sale M2026-000047 (T-AZK-0001): stock went below zero'"],
          [], []),
    593: ("pass", "Cut the connection in the browser, made a sale, and reconnected.",
          ["Nothing was lost while the connection was down: the sale waited on the device and was sent when the connection returned",
           "It was sent once, not twice"],
          [("n6_offline_synced.jpg", "f593_2.jpg", "The sale made offline, sent after reconnecting", "admin", "/admin/counter/offline")], []),
    220: ("pass", "Opened the owner's MPO page for one MPO.",
          ["Photo, name, phone, territory and the owner's own note",
           "The companies they work for, the date the account was made",
           "Products submitted, approved and sold, and the list of products they work with",
           "Orders not delivered in time are counted automatically and shown once there is one"],
          [], []),
    592: ("pass", "Made a backup, ran the monthly restore test on the QA database, and looked at the dashboard.",
          ["The newest backup was loaded into a scratch database: 147 tables came back, medicines 25,934 restored against 25,934 live",
           "The scratch database was removed afterwards",
           "The dashboard says when the test last passed and how many tables came back; a failed test, or one not run for 40 days, shows as a warning"],
          [("n5_restore_test.jpg", "f592_1.jpg", "The dashboard: restore test passed", "admin", "/dashboard")], []),
}

for n, (status, did, checks, shots, notes) in UPDATES.items():
    path = RESULTS / f"{n}.json"
    res = json.loads(path.read_text(encoding="utf-8"))
    res["status"] = status
    res["did"] = did
    res["checks"] = [{"ok": True, "text": t} for t in checks]
    res["notes"] = notes

    kept = [s for s in res.get("shots", []) if not any(s["file"] == x[1] for x in shots)]
    for src, name, caption, role, url in shots:
        shutil.copyfile(SHOTS3 / src, SHOTS2 / name)
        kept.append({"file": name, "caption": caption, "role": role, "url": url})
    res["shots"] = kept

    path.write_text(json.dumps(res, indent=2, ensure_ascii=False), encoding="utf-8")
    print(n, status)
