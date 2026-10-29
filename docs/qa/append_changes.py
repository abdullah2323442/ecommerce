"""Adds the chapter "Changes since 23 October" to the English and the Bangla QA reports.

Run from docs/qa:  python append_changes.py
It opens each finished .docx, adds the chapter at the end and saves it again, so it is safe to run once per report.
The chapter is skipped when the report already has it. Screenshots are the live ones in docs/qa/shots3/.
"""
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

ROOT = Path(__file__).parent
SHOTS = ROOT / "shots3"
NAVY = RGBColor(0x1F, 0x3A, 0x5F)

DIGITS = str.maketrans("0123456789", "০১২৩৪৫৬৭৮৯")

EN = {
    "file": "Siams-Aqua-QA-Report-Final.docx",
    "font": None,
    "num": lambda x: str(x),
    "title": "Changes since 23 October",
    "intro": (
        "This chapter lists what was built or fixed after the report above was written, and how each part was proved. "
        "Every item was tried by hand in the browser against the QA copy of the shop, and the whole automatic test suite "
        "(2,360 tests) passes. Anything that needs a payment company, an SMS or e-mail service, WhatsApp, a map, a courier or a cloud disk "
        "is left out on purpose: it waits until those accounts and keys exist."
    ),
    "sections": [
        {
            "h": "Warning before an offer runs out (item 207)",
            "p": [
                "When an offer is down to the alert level (a setting, two boxes by default), everyone who runs offers is told once how many boxes are left. "
                "The warning is also a line on the owner's dashboard that opens the offers page. When new stock lifts the offer above the level again, the warning is armed for next time. "
                "A check runs every five minutes.",
                "Tried live: the level was set to 20 boxes in Settings, the dashboard showed 'An offer is nearly sold out: Seclo 20 mg' in Bangla with a link to the offers page, "
                "and after the level went back to 2 the line was gone.",
            ],
            "shot": ("n1_offer_alert.jpg", "The owner's dashboard, 'Needs attention': the offer is nearly sold out."),
        },
        {
            "h": "Cards or leaflets in a package (item 347) and penalty steps (item 348)",
            "p": [
                "A buyer whose order was sent can now say 'there was a card or leaflet in the package' from the order page. It is a case of its own, counted apart from ordinary complaints on the seller's record. "
                "The seller answers, we get a copy, and nothing is sent back for it: we close the case, and use a penalty step if it is true.",
                "Tried live: a buyer sent the report, the seller saw it with only 'I solved it' and 'I cannot solve it' (no 'Take it back'), and the owner saw it with only 'Close the case'. "
                "A return case next to it still had 'Send it back to the seller'. A warning and then 'Switch selling off' were applied to the seller: both were recorded, the seller was told each time, and 'Lift it' switched selling on again. "
                "Holding payouts still holds nothing, because there are no online payouts yet.",
            ],
            "shot": ("n2_promo_report.jpg", "The buyer's order page: the report is waiting for the seller."),
            "shot2": ("n3_promo_admin.jpg", "The owner's cases page: the package report, with only 'Close the case'."),
        },
        {
            "h": "Product video (item 529)",
            "p": [
                "A seller can add one short MP4 or WebM clip (up to 20 MB) to a product, from the 'More photos' panel. It plays under the photos on the product page for whoever may see the product, "
                "and it goes when the clip or the product is removed. A new clip replaces the old one.",
                "Tried live: a clip was added from the seller's screen, and the product page played it. Still open for item 529: the advance delivery charge, which waits for online payment.",
            ],
            "shot": ("n4_product_video.jpg", "A product page with its video under the photos."),
        },
        {
            "h": "The counter without internet, tried with the connection cut (items 190, 191 and 593)",
            "p": [
                "The prices were copied, then the connection was cut in the browser. The page said 'No internet. Will send later.' and still found medicines from the saved copy. A strip of Napa was sold: "
                "the sale waited on the device ('1 waiting to be sent') and its memo printed with the temporary number T-AZK-0001, without any request to the shop.",
                "When the connection came back the sale was sent by itself, once, and the shop gave its own memo number, M2026-000047. The stock of that medicine had gone below zero, so the owner was told: "
                "'Offline sale M2026-000047 (T-AZK-0001): stock went below zero'.",
            ],
            "shot": ("n6_offline_synced.jpg", "After reconnecting: the sale is sent and has its real memo number."),
        },
        {
            "h": "The monthly backup test, on the dashboard (item 592)",
            "p": [
                "A backup was made and the monthly restore test was run on the QA database. The newest backup was loaded into a scratch database: 147 tables came back, and 25,934 medicines were restored against 25,934 in the live database. The scratch database was removed afterwards.",
                "The result is now kept and shown on the owner's dashboard: when the test last passed and how many tables came back. A failed test, or one that has not run for 40 days, shows as a warning under 'Needs attention'. "
                "The mysql program is found through the MYSQL_PATH setting.",
            ],
            "shot": ("n5_restore_test.jpg", "The dashboard: the restore test passed, 147 tables."),
        },
        {
            "h": "The detailed MPO profile (item 220)",
            "p": [
                "The owner's MPO page shows the photo, name, phone, territory and the owner's own note; the companies they work for and the date the account was made; products submitted, approved and sold; "
                "and the list of products they work with. A missed delivery is counted automatically and shown once there is one. This item was marked as partly built and is now checked in full.",
            ],
        },
        {
            "h": "Lighter pages on a phone (items 585 and 599 to 601)",
            "p": [
                "Measured on the market page at phone width. The shop pictures were sent at their full 2400 pixel size, 88 to 197 KB each. They now come as a 960 pixel copy made once and kept: 29 to 88 KB each.",
                "The Bangla dictionary (342 KB) used to be sent again on every move to another page. The browser now says which one it holds and the server leaves it out, so moving to another market page costs 23 KB. "
                "A fresh load, a login or a change of language still gets the whole dictionary; the language switch was tried both ways.",
                "Load test: 300 requests, 30 at a time, over the home page, the market, a product, the login page and a redirect. No request failed, none returned an error, none was throttled. "
                "One worker on this computer answers about five pages a second (about 0.4 seconds a page, without opcache), so a festival peak needs several PHP workers with opcache on the real server. "
                "That sizing could not be proved on this computer.",
            ],
        },
        {
            "h": "Faults found by trying it live, and fixed",
            "table": [
                ("Admin sector report", "Failed on MySQL ('not in GROUP BY'). It now groups by the sector name it selects."),
                ("Pickup order", "Choosing pickup silently did nothing. The form sent 'true' where the rule wanted '1'. Fixed, with a test."),
                ("Offline counter", "The page sent 434 sync requests and was throttled. The send function is now stable."),
                ("Memo numbers", "A manual number sorted above the digits and a number was used twice. The next number now skips taken ones."),
                ("'In stock' in Bangla", "The staff-only word filter hid 'In stock' from shoppers. Phrases meant for buyers are now let through."),
                ("Untranslated messages", "Discount limit, loss and not-enough-stock messages showed in English. They are translated, and the code no longer reads their wording."),
                ("Wholesale link for a guest", "A guest who opened /wholesale was sent to the login page, which showed that a trade section exists. They now go to the home page."),
                ("Drug licence", "A business that sells medicine must now give its drug licence as well as its trade licence."),
            ],
            "head": ("Where", "What was wrong, and the fix"),
        },
        {
            "h": "Checked and left as it is",
            "p": [
                "Item 528 (an offer and a token never combine). In the shop the token is refused on an offer price. In the market a product has one price and no offers, and a token is capped at our own commission, so the two cannot stack.",
            ],
        },
    ],
}

BN = {
    "file": "Siams-Aqua-QA-Report-Bangla.docx",
    "font": "Nirmala UI",
    "num": lambda x: str(x).translate(DIGITS),
    "title": "২৩ অক্টোবরের পর যা বদলেছে",
    "intro": (
        "এই অধ্যায়ে ওপরের রিপোর্ট লেখার পর যা বানানো বা ঠিক করা হয়েছে, আর প্রতিটা কীভাবে প্রমাণ করা হয়েছে তা লেখা আছে। "
        "প্রতিটা জিনিস দোকানের QA কপিতে ব্রাউজারে হাতে চালিয়ে দেখা হয়েছে, আর পুরো স্বয়ংক্রিয় টেস্ট (২,৩৬০টি) পাস করেছে। "
        "যা পেমেন্ট কোম্পানি, SMS বা ই-মেইল সেবা, WhatsApp, মানচিত্র, কুরিয়ার বা ক্লাউড ডিস্কের ওপর নির্ভর করে তা ইচ্ছা করেই বাদ রাখা হয়েছে। "
        "ওই অ্যাকাউন্ট আর চাবি পাওয়া পর্যন্ত সেগুলো অপেক্ষায় থাকবে।"
    ),
    "sections": [
        {
            "h": "অফার শেষ হওয়ার আগে সতর্কতা (আইটেম ২০৭)",
            "p": [
                "কোনো অফার সতর্কতার সীমায় নামলে (সেটিং, ডিফল্ট দুই বাক্স) যাঁরা অফার চালান সবাই একবার জানতে পারেন কত বাক্স বাকি। "
                "মালিকের ড্যাশবোর্ডেও একটা লাইন আসে, যা অফার পেজ খোলে। নতুন মাল এসে অফার আবার সীমার ওপরে গেলে পরের বারের জন্য সতর্কতা আবার চালু হয়। "
                "প্রতি পাঁচ মিনিটে যাচাই চলে।",
                "ব্রাউজারে চালিয়ে দেখা: সেটিংসে সীমা ২০ বাক্স করা হলো, ড্যাশবোর্ডে বাংলায় 'একটি অফার প্রায় শেষ: Seclo 20 mg' দেখা গেল এবং লিঙ্কে অফার পেজ খুলল। "
                "সীমা ২-এ ফিরিয়ে দেওয়ার পর লাইনটা চলে গেল।",
            ],
            "shot": ("n1_offer_alert.jpg", "মালিকের ড্যাশবোর্ডের 'মনোযোগ দরকার': অফার প্রায় শেষ।"),
        },
        {
            "h": "প্যাকেজে কার্ড বা লিফলেট (আইটেম ৩৪৭) এবং শাস্তির ধাপ (আইটেম ৩৪৮)",
            "p": [
                "যে ক্রেতার অর্ডার পাঠানো হয়েছে, তিনি এখন অর্ডার পেজ থেকে 'প্যাকেজে কার্ড বা লিফলেট ছিল' জানাতে পারেন। এটা আলাদা ধরনের কেস, বিক্রেতার হিসাবে সাধারণ অভিযোগ থেকে আলাদা গোনা হয়। "
                "বিক্রেতা উত্তর দেন, আমরা কপি পাই। এর জন্য কিছু ফেরত যায় না। আমরা কেস বন্ধ করি, আর সত্যি হলে শাস্তির ধাপ ব্যবহার করি।",
                "ব্রাউজারে চালিয়ে দেখা: ক্রেতা অভিযোগ পাঠালেন। বিক্রেতার পর্দায় শুধু 'আমি মিটিয়ে দিয়েছি' ও 'আমি মেটাতে পারছি না' এল ('ফেরত নিন' নেই)। মালিকের পর্দায় শুধু 'বিষয়টি বন্ধ করুন'। "
                "পাশের ফেরতের কেসে 'বিক্রেতার কাছে ফেরত পাঠান' আগের মতোই থাকল। বিক্রেতার ওপর আগে সতর্কতা, তারপর 'বিক্রি বন্ধ করুন' প্রয়োগ করা হলো। "
                "দুটোই রেকর্ড হলো, প্রতিবার বিক্রেতাকে জানানো হলো, আর 'তুলে নিন' চাপলে বিক্রি আবার চালু হলো। 'পাওনা আটকান' এখনও কিছু আটকায় না, কারণ অনলাইন পেআউট এখনও নেই।",
            ],
            "shot": ("n2_promo_report.jpg", "ক্রেতার অর্ডার পেজ: অভিযোগ বিক্রেতার উত্তরের অপেক্ষায়।"),
            "shot2": ("n3_promo_admin.jpg", "মালিকের অভিযোগ পেজ: প্যাকেজের অভিযোগ, শুধু 'বিষয়টি বন্ধ করুন' সহ।"),
        },
        {
            "h": "পণ্যের ভিডিও (আইটেম ৫২৯)",
            "p": [
                "বিক্রেতা 'আরও ছবি' অংশ থেকে একটি পণ্যে একটি ছোট MP4 বা WebM ক্লিপ (সর্বোচ্চ ২০ MB) দিতে পারেন। পণ্য যিনি দেখতে পান তিনি পণ্য পেজে ছবির নিচে সেটা চালাতে পারেন। "
                "ক্লিপ বা পণ্য মুছলে ভিডিওও চলে যায়। নতুন ক্লিপ দিলে পুরনোটা বদলে যায়।",
                "ব্রাউজারে চালিয়ে দেখা: বিক্রেতার পর্দা থেকে ক্লিপ দেওয়া হলো, পণ্য পেজে সেটা চলল। আইটেম ৫২৯-এ বাকি আছে অগ্রিম ডেলিভারি চার্জ, যা অনলাইন পেমেন্টের অপেক্ষায়।",
            ],
            "shot": ("n4_product_video.jpg", "পণ্য পেজে ছবির নিচে ভিডিও।"),
        },
        {
            "h": "ইন্টারনেট কেটে দিয়ে কাউন্টার, চালিয়ে দেখা (আইটেম ১৯০, ১৯১ ও ৫৯৩)",
            "p": [
                "দাম কপি করা হলো, তারপর ব্রাউজারে ইন্টারনেট কেটে দেওয়া হলো। পাতায় লেখা এল 'ইন্টারনেট নেই। পরে পাঠানো হবে।', আর সংরক্ষিত কপি থেকে ওষুধ খোঁজা চলল। একটি পাতা Napa বিক্রি করা হলো: "
                "বিক্রিটা ডিভাইসে জমা থাকল ('১টি পাঠানোর অপেক্ষায়') আর মেমো অস্থায়ী নম্বর T-AZK-0001 নিয়ে প্রিন্ট হলো, দোকানে কোনো অনুরোধ না পাঠিয়ে।",
                "ইন্টারনেট ফিরলে বিক্রিটা নিজে নিজে একবারই গেল, আর দোকান নিজের মেমো নম্বর M2026-000047 দিল। ওই ওষুধের মজুত শূন্যের নিচে নেমে গিয়েছিল, তাই মালিককে জানানো হলো: "
                "'Offline sale M2026-000047 (T-AZK-0001): stock went below zero'।",
            ],
            "shot": ("n6_offline_synced.jpg", "ইন্টারনেট ফেরার পর: বিক্রি গেছে, আসল মেমো নম্বর এসেছে।"),
        },
        {
            "h": "মাসিক ব্যাকআপ পরীক্ষা, ড্যাশবোর্ডে (আইটেম ৫৯২)",
            "p": [
                "একটি ব্যাকআপ নিয়ে QA ডাটাবেসে মাসিক রিস্টোর টেস্ট চালানো হলো। সবচেয়ে নতুন ব্যাকআপ একটি অস্থায়ী ডাটাবেসে তোলা হলো: ১৪৭টি টেবিল ফিরে এল, আর ২৫,৯৩৪টি ওষুধ ফিরে এসে আসল ডাটাবেসের ২৫,৯৩৪টির সঙ্গে মিলল। শেষে অস্থায়ী ডাটাবেস মুছে ফেলা হলো।",
                "ফল এখন রেখে দেওয়া হয় এবং মালিকের ড্যাশবোর্ডে দেখায়: টেস্ট কখন পাস হয়েছিল, কয়টি টেবিল ফিরেছিল। টেস্ট ফেল করলে, বা ৪০ দিন না চললে, 'মনোযোগ দরকার'-এ সতর্কতা দেখায়। "
                "mysql প্রোগ্রাম MYSQL_PATH সেটিং দিয়ে খুঁজে নেওয়া হয়।",
            ],
            "shot": ("n5_restore_test.jpg", "ড্যাশবোর্ড: রিস্টোর টেস্ট পাস, ১৪৭টি টেবিল।"),
        },
        {
            "h": "এমপিওর বিস্তারিত প্রোফাইল (আইটেম ২২০)",
            "p": [
                "মালিকের এমপিও পাতায় আছে ছবি, নাম, ফোন, এলাকা আর মালিকের নিজের নোট; তিনি যে কোম্পানিগুলোর কাজ করেন ও অ্যাকাউন্ট খোলার তারিখ; জমা দেওয়া, অনুমোদিত ও বিক্রি হওয়া পণ্য; "
                "এবং তিনি যে পণ্য নিয়ে কাজ করেন তার তালিকা। মাল সময়মতো না পৌঁছালে সেটা নিজে নিজে গোনা হয় এবং একটা হলে দেখায়। আইটেমটি আংশিক চিহ্নিত ছিল, এখন পুরোটা যাচাই করা হয়েছে।",
            ],
        },
        {
            "h": "ফোনে হালকা পেজ (আইটেম ৫৮৫ এবং ৫৯৯ থেকে ৬০১)",
            "p": [
                "ফোনের মাপে মার্কেট পেজে মেপে দেখা। দোকানের ছবি তাদের পুরো ২৪০০ পিক্সেলের মাপে যাচ্ছিল, প্রতিটা ৮৮ থেকে ১৯৭ KB। এখন একবার বানিয়ে রাখা ৯৬০ পিক্সেলের কপি যায়, প্রতিটা ২৯ থেকে ৮৮ KB।",
                "বাংলা ডিকশনারি (৩৪২ KB) আগে প্রতিবার অন্য পেজে যাওয়ার সময় আবার পাঠানো হতো। এখন ব্রাউজার জানিয়ে দেয় কোনটা তার কাছে আছে, আর সার্ভার সেটা বাদ দেয়, তাই আরেক মার্কেট পেজে যেতে লাগে ২৩ KB। "
                "নতুন করে লোড, লগইন বা ভাষা বদলালে পুরো ডিকশনারি আসে। ভাষা বদলানো দুই দিকেই চালিয়ে দেখা হয়েছে।",
                "লোড টেস্ট: হোম, মার্কেট, একটা পণ্য, লগইন পেজ আর একটা রিডাইরেক্ট মিলিয়ে ৩০০ রিকোয়েস্ট, একসঙ্গে ৩০টা। কোনোটা ফেল করেনি, কোনো এরর আসেনি, কোনোটা থ্রটল হয়নি। "
                "এই কম্পিউটারে একটা ওয়ার্কার সেকেন্ডে প্রায় পাঁচটা পেজ দেয় (পেজপ্রতি প্রায় ০.৪ সেকেন্ড, opcache ছাড়া)। তাই উৎসবের ভিড়ে আসল সার্ভারে অনেক PHP ওয়ার্কার আর opcache লাগবে। "
                "এই মাপ এই কম্পিউটারে প্রমাণ করা যায়নি।",
            ],
        },
        {
            "h": "হাতে চালিয়ে যে ত্রুটি ধরা পড়েছে এবং ঠিক করা হয়েছে",
            "table": [
                ("অ্যাডমিনের সেক্টর রিপোর্ট", "MySQL-এ ফেল করছিল ('GROUP BY-তে নেই')। এখন যে নাম সিলেক্ট করা হয় সেই নামে গ্রুপ হয়।"),
                ("পিকআপ অর্ডার", "পিকআপ বেছে নিলে কিছুই হতো না। ফর্ম 'true' পাঠাত, নিয়ম চাইত '1'। ঠিক করা হয়েছে, টেস্টসহ।"),
                ("অফলাইন কাউন্টার", "পেজ ৪৩৪টা সিংক রিকোয়েস্ট পাঠিয়ে থ্রটল হচ্ছিল। এখন পাঠানোর ফাংশন স্থির।"),
                ("মেমো নম্বর", "হাতে দেওয়া নম্বর সংখ্যার ওপরে সাজাত এবং একটা নম্বর দুবার ব্যবহার হতো। এখন পরের নম্বর নেওয়া নম্বর বাদ দেয়।"),
                ("বাংলায় 'In stock'", "শুধু কর্মীর শব্দ আটকানোর ফিল্টার ক্রেতার কাছ থেকে 'In stock' লুকাত। ক্রেতার জন্য বাক্যগুলো এখন ছাড়া হয়।"),
                ("অনুবাদ না হওয়া বার্তা", "ছাড়ের সীমা, লোকসান ও মাল কম হওয়ার বার্তা ইংরেজিতে আসত। অনুবাদ হয়েছে, আর কোড এখন বার্তার শব্দ পড়ে না।"),
                ("অতিথির জন্য হোলসেল লিঙ্ক", "অতিথি /wholesale খুললে লগইন পেজে যেত, ফলে বোঝা যেত পাইকারি অংশ আছে। এখন হোম পেজে যায়।"),
                ("ড্রাগ লাইসেন্স", "যে ব্যবসা ওষুধ বেচে তাকে এখন ট্রেড লাইসেন্সের সঙ্গে ড্রাগ লাইসেন্সও দিতে হয়।"),
            ],
            "head": ("কোথায়", "কী ভুল ছিল, আর সমাধান"),
        },
        {
            "h": "যাচাই করে যেমন আছে তেমন রাখা হয়েছে",
            "p": [
                "আইটেম ৫২৮ (অফার আর টোকেন একসাথে নয়)। দোকানে অফারের দামে টোকেন নেওয়া হয় না। মার্কেটে একটা পণ্যের একটাই দাম, কোনো অফার নেই, আর টোকেন আমাদের নিজের কমিশনের বেশি হতে পারে না, তাই দুটো জমতে পারে না।",
            ],
        },
    ],
}


def set_font(run, font):
    if font:
        run.font.name = font
        run._element.rPr.rFonts.set(qn("w:eastAsia"), font)
        run._element.rPr.rFonts.set(qn("w:cs"), font)


def para(doc, text, font, bold=False, size=None, style=None, color=None):
    p = doc.add_paragraph(style=style)
    r = p.add_run(text)
    r.bold = bold
    if size:
        r.font.size = Pt(size)
    if color:
        r.font.color.rgb = color
    set_font(r, font)
    return p


def build(lang):
    path = ROOT / lang["file"]
    doc = Document(str(path))

    if any(p.text.strip() == lang["title"] for p in doc.paragraphs):
        print(f"{lang['file']}: already has the chapter, skipped")
        return

    font = lang["font"]
    doc.add_page_break()
    h = doc.add_heading(lang["title"], level=1)
    for r in h.runs:
        set_font(r, font)
    para(doc, lang["intro"], font)

    for sec in lang["sections"]:
        h2 = doc.add_heading(sec["h"], level=2)
        for r in h2.runs:
            set_font(r, font)

        for text in sec.get("p", []):
            para(doc, text, font)

        shots = [sec[k] for k in ("shot", "shot2") if k in sec]
        if shots:
            table = doc.add_table(rows=2, cols=len(shots))
            table.alignment = 1
            for i, (name, caption) in enumerate(shots):
                cell = table.rows[0].cells[i]
                cell.paragraphs[0].alignment = WD_ALIGN_PARAGRAPH.CENTER
                cell.paragraphs[0].add_run().add_picture(str(SHOTS / name), width=Cm(6.6 if len(shots) > 1 else 7.5))
                cap = table.rows[1].cells[i].paragraphs[0]
                cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
                r = cap.add_run(caption)
                r.italic = True
                r.font.size = Pt(9)
                set_font(r, font)

        if "table" in sec:
            head = sec["head"]
            table = doc.add_table(rows=1, cols=2)
            table.style = "Table Grid"
            for i, text in enumerate(head):
                c = table.rows[0].cells[i]
                c.paragraphs[0].text = ""
                r = c.paragraphs[0].add_run(text)
                r.bold = True
                set_font(r, font)
            for a, b in sec["table"]:
                cells = table.add_row().cells
                for i, text in enumerate((a, b)):
                    r = cells[i].paragraphs[0].add_run(text)
                    r.font.size = Pt(10)
                    set_font(r, font)
            for row in table.rows:
                row.cells[0].width = Cm(4.2)
                row.cells[1].width = Cm(12.3)

    doc.save(str(path))
    print(f"{lang['file']}: chapter added")


if __name__ == "__main__":
    build(EN)
    build(BN)
