from _w import w
w(155,"Tested live in the browser pane by hand. Karim Pharmacy has 29 online orders in the last three months, so the shop's page shows its habit. The reminder job was then run at three different times of day and the shop opened its messages.",
 ["The shop's order page shows \"Order reminder - We remind you around 10:20, when you usually order.\" with a Turn off button (worked out from the shop's own orders)",
  "Run at 15:00 (not the shop's time) the job sent nothing; run at 10:25 it sent 1 reminder; run again at 10:28 it sent nothing (once a day)",
  "The shop's messages show \"This is about the time you usually order. Place your orders at night and we will have everything packed the same night.\" with the new date"],
 [{"file":"f155_1.jpg","caption":"Shop: the reminder time worked out from its own orders","role":"shop","url":"/paikari"},
  {"file":"f155_2.jpg","caption":"Shop: the reminder in its messages","role":"shop","url":"/inbox"}],
 ["Done in the live browser for the shop's side. The job that runs every ten minutes was started by a command with the time of day set, instead of waiting for 10:20. Other shops have no orders yet so have no habit and are not reminded."])
