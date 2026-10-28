from _w import w
w(605,"Tested live in the browser pane by hand. A signed-in shopper put Napa in the cart and left. The reminder job was then run as if 30 hours had passed, and the shopper opened their messages.",
 ["The reminder is made once a day after the cart was last touched: the job told 1 person on its first run and 0 on the second (once only)",
  "The shopper's bell showed 1 and the message read \"You left items in your cart (1). They are waiting for you.\"",
  "Tapping the message opened the cart, with the item still there"],
 [{"file":"f605_1.jpg","caption":"The reminder in the shopper's messages","role":"buyer","url":"/inbox"},
  {"file":"f605_2.jpg","caption":"Tapping it opens the cart with the item","role":"buyer","url":"/cart"}],
 ["Done in the live browser for the shopper's side. The day of waiting was not waited for: the scheduled job was run by a command with the clock moved on 30 hours, as it would run by itself at night. The date on the message (23 Oct) is that moved clock. Only signed-in shoppers can be reminded; a guest cart is not."])
