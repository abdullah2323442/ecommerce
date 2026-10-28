from _w import w
w(350,"Tested live in the browser pane by hand. The owner opened Market complaints, took the serious complaint on order M2026-000179 (a scratched phone case, paid on delivery) and pressed Send it back to the seller. Then the seller and the buyer opened their own pages.",
 ["The order became \"Sent back to you\" on the seller's page, with the owner's note, and no new delivery cost was made",
  "The seller's record changed to Sold: 240 - Sent back: 1 (1 orders) - Complaints: 2",
  "The goods went back on the seller's shelf: the listing stock went from 3 to 4",
  "The buyer's page says \"This order was sent back to the seller.\" and the case reads Sent back with the owner's note"],
 [{"file":"f350_1.jpg","caption":"Seller: record with 1 order sent back","role":"wholesaler","url":"/wholesale/sell/orders"},
  {"file":"f350_2.jpg","caption":"Seller: the order is Sent back to you, with our note","role":"wholesaler","url":"/wholesale/sell/orders"},
  {"file":"f350_3.jpg","caption":"Buyer: the order was sent back to the seller; nothing to pay","role":"buyer","url":"/market/orders/..."}],
 ["Done by hand in the live browser. Found and fixed: a returned or cancelled order still told the buyer \"You pay when it arrives.\", and an online order told them the same. It now says \"Nothing to pay for this order.\" for returned and cancelled orders and says nothing about paying on arrival for online orders.",
  "The goods were not physically posted back; that cannot be shown in a browser. What is shown is what the system records and tells each side."])
w(349,"Tested live in the browser pane by hand. The cash-on-delivery side: a delivered order that was paid for on arrival was sent back to the seller by the owner, and the buyer's page was read afterwards.",
 ["Cash on delivery: nothing had been paid online, so nothing is refunded and the buyer's page says \"Nothing to pay for this order.\" (this was wrong before and was fixed)",
  "The seller's record counts it as sent back, so no commission is taken for it"],
 [{"file":"f349_1.jpg","caption":"Buyer: returned cash-on-delivery order, nothing to pay","role":"buyer","url":"/market/orders/..."}],
 ["Only the cash-on-delivery half was shown. The online half (refund from the held money) needs a real card or wallet payment, and the test gateway only makes practice payments that never touch an order's money, so it was not shown. The code does mark a really paid order Refund due and tells the office; this is covered by automatic tests only."],"skipped")
