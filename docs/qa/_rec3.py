from _w import w
w(452,"Tested live in the browser pane by hand. The owner published a post from Karim Pharmacy: \"Moderator needed for our Facebook page\" (kind: Need a worker) with a contact line. A different signed-in person (a student) opened it, read how to reach the poster, and left a comment saying they can do it.",
 ["The post shows its title, the words, the 'how to reach them' line (\"Call 01700000003 after 6 pm\") and the notice that the board is only a place to meet",
  "A signed-in stranger could comment (\"I can do this. I am a student and free every evening after 6 pm. Please call me.\") and the comment appeared at once with its date and time",
  "A guest sees the post but is told to log in before they can see how to reach the poster and before commenting"],
 [{"file":"f452_1.jpg","caption":"A student reads the moderator post and how to reach the poster","role":"wholesaler","url":"/help/2"},
  {"file":"f452_2.jpg","caption":"Their comment is on the post","role":"shop","url":"/help/2"},
  {"file":"f452_3.jpg","caption":"A guest is asked to log in to see how to reach them","role":"guest","url":"/help/2"}],
 ["Done by hand in the live browser. While testing, a comment that was refused showed its message at the very top of the page, away from the comment box. Fixed: the refusal now shows right above the Comment button."])
w(455,"Tested live in the browser pane by hand. A comment with two product links was left on a help post: one to Napa and one to a medicine that does not exist. The same post was then opened by a guest, by a shop (tier C) and by a wholesaler who has no medicine category.",
 ["A guest sees a Napa card at the retail price of 300 taka per box",
  "A shop in price tier C sees the same card at its own price, 240 taka per box",
  "A wholesaler whose account has no medicine category sees no Napa card at all, because medicine is not shown to them",
  "The link to a medicine that does not exist shows \"This product is no longer available.\" for everyone",
  "Found and fixed: a pasted link to our own site with a port (as on a test server) was refused without any visible message; the port is now ignored when checking the address"],
 [{"file":"f455_1.jpg","caption":"Wholesaler without a medicine category: no Napa card, only the gone product note","role":"wholesaler","url":"/help/2"},
  {"file":"f455_2.jpg","caption":"Guest: Napa card at the retail price (300)","role":"guest","url":"/help/2"},
  {"file":"f455_3.jpg","caption":"Shop in tier C: the same card at its own price (240)","role":"shop","url":"/help/2"}],
 ["Done by hand in the live browser. New automatic test in HelpBoardTest for the link with a port; the comment refusal now uses its own message place."])
w(458,"Tested live in the browser pane by hand. Karim Pharmacy published two help posts in one day and then tried a third.",
 ["The first two posts were accepted",
  "The third was refused with \"That is enough posts for today. Please try again tomorrow.\""],
 [{"file":"f458_1.jpg","caption":"The third post of the day is refused","role":"shop","url":"/help/new"}],
 ["Done by hand in the live browser."])
