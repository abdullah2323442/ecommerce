from _w import w
w(32,"Tested live in the browser pane by hand, in two real sales. (1) A shopper put 5 boxes of Napa 500 mg in the cart, used their Aqua Token at checkout, and the owner finished the order at the counter. (2) A shop (Karim Pharmacy) returned a box for credit, then ordered ten boxes; the owner finished that order.",
 ["Price, then quantity: Napa is 300 a box, 5 or more are 270 a box, so 5 boxes were 1,350 (checkout showed it before the order was sent)",
  "Then the token: the finished memo shows Subtotal 1,350, Aqua Token minus 100, Total 1,250. The token is only spent when the order is finished, not at checkout",
  "Then the credit: on the shop's memo (offer price 247.50 a box, 10 boxes = 2,475) the line Return product minus 255 came off, and the total was 2,220",
  "A token and an offer price do not go together; checkout says so and the shop's offer order took no token",
  "Found and fixed: on a finished memo the Total and the Paid/Due line ignored the credit and the token (it said Total 2,475 and Due 255 though 2,220 had been paid). The memo now shows an Aqua Token line and the real total"],
 [{"file":"f32_1.jpg","caption":"Quantity price, then the token: 1,350 - 100 = 1,250","role":"admin","url":"/admin/counter/333"},
  {"file":"f32_2.jpg","caption":"Shop memo: offer lines, then Return product (credit) minus 255","role":"admin","url":"/admin/counter/330"}],
 ["Done by hand in the live browser. The chain is in this order everywhere: price, quantity, token, then credit. It was shown on two memos rather than one because a shop's order does not take a token (only the public checkout offers it), and a public buyer had no return credit to spend. Set-up by command: Napa and a controlled medicine were restocked, and the returned box was approved on the owner's Returns screen."])
w(525,"Tested live in the browser pane by hand. The owner gave a 100 taka Aqua Token to a shopper on the Aqua Tokens screen (find the person, amount, reason, Give a token). The shopper used it at checkout and the owner finished the order.",
 ["Owner gives a token: the Aqua Tokens screen lists it under Given lately as Ready to use, 100 taka, with the reason",
  "The shopper's checkout offers \"Use my Aqua Token (100 taka off)\", ticked, with the note that it comes off when the order is finished",
  "After the order was finished the memo shows Aqua Token minus 100 and the token is spent (it cannot be used twice)"],
 [{"file":"f525_1.jpg","caption":"Owner: giving a token and the list of tokens given","role":"admin","url":"/admin/tokens"},
  {"file":"f525_2.jpg","caption":"The token taken off the finished memo","role":"admin","url":"/admin/counter/333"}],
 ["Only the first level (our token, given by the owner) was carried through. The second level (a seller's own token on their own products) was seen on its screen earlier but not given and used on a market order, so this stays partly shown."],"skipped")
