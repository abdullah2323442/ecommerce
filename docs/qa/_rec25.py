from _w import w

w(604, "Built and tested live in the browser pane by hand. A wholesale seller opened Sales and bundles, put the 27 inch 4K monitor on a 20 percent sale for one day, and made a bundle called Desk setup of the monitor and the 4K action camera at 10 percent off. A signed-in buyer then opened the monitor, added the whole bundle to the market cart and read the cart.",
 ["The seller's page lists every product that buyers can see with a Put on sale button; the form asks for the percent (5 to 70) and when the sale ends (up to 14 days, a setting) and says so; the sale started and the product moved to End the sale with its end time",
  "The product page shows the old price crossed out (55,080 taka), the new price (44,064) and a badge with 20 percent off and the time the sale ends; the old price is also read aloud as Was",
  "The bundle card under the product lists both products at today's prices (the monitor at its sale price), what the buyer saves (7,568.40) and the new total (68,115.60), with Add all to the market cart",
  "In the cart each bundle line carries its own lower price and a line saying Desk setup: you save 4,406.40 and 3,162.00; the checkout is placed at exactly the price the cart showed",
  "A bundle only counts for each full set in the cart (two chairs and three tables is two sets), a product with colours or sizes cannot be in a bundle, another seller's products cannot be added, and the seller carries the discount while our commission stays the same in taka (automatic tests)"],
 [{"file": "f604_1.jpg", "caption": "The seller puts the monitor on a 20 percent sale", "role": "wholesale", "url": "/wholesale/sell/deals"},
  {"file": "f604_2.jpg", "caption": "The buyer sees the crossed-out price, the end time and the bundle", "role": "visitor", "url": "/market/71"},
  {"file": "f604_3.jpg", "caption": "In the cart the bundle saving shows on each line", "role": "paikari", "url": "/market-cart"}],
 ["Built this round from the spec (chapter 13 and the extras list). New tables listing_sales, bundles and bundle_items. A sale is a percent off the seller's price for the public price only; a wholesale price is not touched. Our commission is worked out on the lower price. Not built: a sale starting later than now, sales across many products at once, and a bundle that mixes products of different sellers. 19 new automatic tests."])

w(435, "Built and tested live in the browser pane by hand. The owner of Crispy House opened the restaurant settings, tried to set a longest distance of 5 km before saying where the restaurant is, then set its place and saved. The restaurant was switched to By distance (10 taka for each km, least charge 30). A buyer put a dish in the order and shared a position about 3 km away, then one about 6 km away.",
 ["The delivery settings have a new choice By distance and a block for what is charged for each kilometre, the longest distance in km (empty means no limit) and where the restaurant is, with a button that uses the position of the device",
  "Setting a longest distance or charging by the kilometre before the restaurant's place is set is refused with a clear reason next to the Save button",
  "On the menu page the order form asks the buyer to share a position once (it is not kept); at about 3.9 km by road it says so and the charge is 39 taka, with the total (329) updated",
  "At about 7.8 km, beyond the 5 km limit, the form says the buyer is further than the restaurant delivers and the Place the order button is disabled; the server refuses it too",
  "However close, the charge is never below the least charge; the owner's own area prices and the by-hand choice work as before, and a longest distance also holds for area prices"],
 [{"file": "f435_1.jpg", "caption": "Restaurant settings: charge for each kilometre, longest distance, where the restaurant is", "role": "vendor", "url": "/food/vendor"},
  {"file": "f435_2.jpg", "caption": "About 3.9 km away: the charge is worked out", "role": "paikari", "url": "/food/r/1"},
  {"file": "f435_3.jpg", "caption": "About 7.8 km away: too far, the order button is off", "role": "paikari", "url": "/food/r/1"}],
 ["Built this round from the spec (chapter 17). The distance is the straight line between the two places times a road allowance the owner can set in Settings (1.3 to start), because real road distance needs a map service that is not connected (item 436 stays not built). So the distance and the limit are estimates, and the screen says so. New columns on restaurants and food orders; the buyer's position is not stored, only the distance. 14 new automatic tests."])

w(434, "Checked together with item 435. The restaurant's delivery charge can now be written three ways: by area (one price inside, another outside), by the kilometre, or written by hand on each order.",
 ["The settings offer all three ways: By area, By distance and I write it on each order",
  "By area was already built; By distance and the longest distance and least charge now work as described under item 435",
  "Each way is tested with the order placed and the charge kept on the order"],
 [{"file": "f435_1.jpg", "caption": "The three ways of charging, with the kilometre block", "role": "vendor", "url": "/food/vendor"}],
 ["The by-the-kilometre way uses an estimated road distance (see item 435). Item 436, real road distance, still needs a map service."])

w(594, "Built and tested live in the browser pane by hand. The owner opened Customers, then Bring in shops from a file, and sent a file of six lines: a shop with an address and a rate on a second line, a shop with an ambiguous medicine name, a bad phone number, an unknown tier and a price that is not for one piece. The owner read the preview and brought in the shop that was ready.",
 ["Customers has a link Bring in shops from a file; the page explains the columns, offers a file to start from and lists the rules",
  "The preview shows every line before anything is saved: which are new shops, which update a shop, which are rates, and which are left out with the reason in words (bad phone number, no tier called Z, more than one medicine called Napa 500 mg with the companies to choose from, a price that is not for one piece)",
  "Bringing it in made the shop with its tier (B), area, shop name, address in the address book and the 12 percent rate; the lines with a problem were left out and the rest went in",
  "A number already on the system updates that shop's tier, area, address and rates and never its name, type or password; a staff account's number is refused; the same file cannot be brought in twice; only those who manage customers can use it (automatic tests)",
  "A new account has no password until the shop sets its own, and a placeholder email until then; a file saved from Excel with a byte order mark or a lost leading zero still works"],
 [{"file": "f594_1.jpg", "caption": "Choose the file, with the columns and the rules", "role": "admin", "url": "/admin/customers/import"},
  {"file": "f594_2.jpg", "caption": "The preview: what each line would do and why a line is left out", "role": "admin", "url": "/admin/customers/import"},
  {"file": "f594_3.jpg", "caption": "Done: one new shop, one rate, four lines left out", "role": "admin", "url": "/admin/customers/import"}],
 ["The checklist already ticked this item, but there was no import in the system; it is built now. Up to 2,000 lines and 1 MB in one file; the CSV must be UTF-8. 16 new automatic tests."])

w(598, "Built and tested live in the browser pane by hand. Four old records (three staff activity lines and one sign-in record, 13 to 20 months old) were added to the test data. The owner opened the new Archive screen, pressed Move old records now, and searched for napa.",
 ["The Archive screen says how many months old a record must be before it moves (12 to start, a setting; 0 for never), that nothing is deleted, and that orders, memos, money and stock are never moved",
  "Move old records now moved the four old records and said so; the counts show 3 staff activity lines and 1 sign-in; the busy tables no longer hold them",
  "Searching for napa found the one activity line that mentioned it, with its date; a search takes every word, a kind of record and a date range, and a percent sign is only a character",
  "At night (03:00 Bangladesh time) the same move runs by itself; running it twice never copies a record twice; only records a person has already read leave the notices; only the people who may read the activity log can open it (automatic tests)"],
 [{"file": "f598_1.jpg", "caption": "Four old records moved to the archive", "role": "admin", "url": "/admin/archive"},
  {"file": "f598_2.jpg", "caption": "Searching the archive for a word", "role": "admin", "url": "/admin/archive?q=napa"}],
 ["Built this round from the spec (chapter 28, note 11). What moves is only what daily work no longer reads: the staff activity log, sign-in records and notices already read. Behaviour data, orders and money stay where they are. New table archived_records keeps each record whole. 10 new automatic tests."])

w(284, "Built and tested live in the browser pane by hand. A signed-in buyer with market orders opened the market home.",
 ["At the very top of the market home a Picked for you row shows eight products, each with its photo, name and price, scrolling sideways on a phone",
  "The products come from what this buyer ordered or saved: the same categories first, then the same kind of business and the same sellers, with products that sold more in the last 90 days a little ahead",
  "What they already bought, their own products, sold-out products, hidden products and cancelled orders are left out; one buyer's history is never used for another; a buyer with no history sees nothing there",
  "It shows only on the plain market page, not inside a shop, a category or a search, and a sale price shows on the card like anywhere else (automatic tests)"],
 [{"file": "f284_1.jpg", "caption": "Picked for you, at the top of the market", "role": "visitor", "url": "/market"}],
 ["Built this round from the spec (chapter 13). It is the plain, explainable kind, worked out from order and saved-product history; no learning model and no outside service. Not built: a switch for a buyer to turn it off, and recommendations in the pharmacy or food sections. 8 new automatic tests."])

w(603, "Built and tested live in the browser pane by hand. The owner switched reward points on (2 points for every 100 taka, 100 points worth 10 taka, least 20). A buyer's two market orders were marked delivered; the buyer opened the wallet and turned 30 points into a token.",
 ["The Wallet has a new Reward points card with the balance in large type, how points are earned and what they are worth, and the history of each purchase that earned points",
  "Two delivered orders of 1,200 taka of goods earned 24 points each, delivery not counted; turning 30 points into a token made a token of 3 taka that shows in the buyer's tokens, and the balance went to 18",
  "A cancelled, returned or not-received order takes its points back once, a return takes back the points for that money, and points already turned into a token stay spent; points never go below nothing",
  "A buyer cannot turn in more points than they have, or fewer than the least; with the programme off there is no card; two taps cannot spend the same points twice (automatic tests)"],
 [{"file": "f603_1.jpg", "caption": "Reward points: balance, the rule and what earned them", "role": "visitor", "url": "/wallet"},
  {"file": "f603_2.jpg", "caption": "After turning 30 points into a 3 taka token", "role": "visitor", "url": "/wallet"}],
 ["Built this round from the spec (chapter 27, the extras). Points are earned on shop memos and market orders; the token is the same Aqua Token that works in every section. The referral reward was built before. 10 new automatic tests."])

w(353, "Built and tested live in the browser pane by hand. The owner switched the seller deposit on in Settings (5,000 taka asked), opened Seller deposits, chose Rahim Traders and took in 3,000 taka with a note. The seller then opened the Sell page.",
 ["Seller deposits lists every seller with what is asked, what is held and a Short mark with the amount; the owner can set a different amount for one seller",
  "Take it in, Give back and Deduct each keep a row with who did it and the reason; a deduction needs a reason that the seller is told; nothing larger than what is held can be given back or deducted",
  "The seller's Sell page says how much is asked (5,000), how much is held (3,000) and how much more is needed (2,000) before new products can be sent in",
  "While a seller is short, sending in a new product is refused with that reason; with the option off (the starting setting) nobody is asked for anything (automatic tests)"],
 [{"file": "f353_1.jpg", "caption": "The owner took in 3,000 taka of 5,000 asked", "role": "admin", "url": "/admin/market/deposits?seller=5"},
  {"file": "f353_2.jpg", "caption": "The seller is told what is short", "role": "wholesale", "url": "/wholesale/sell"}],
 ["Built this round from the spec (chapter 14, security deposit, kept off until trust is built). It holds the number only; the money itself is taken and given back outside the system and the owner writes it down here. New tables seller_deposits and deposit_entries. 8 new automatic tests."])

w(596, "Built and tested live in the browser pane by hand. The month start day was set to 10 in Settings and the owner opened the monthly report.",
 ["The monthly report names the real period: 10 October 2026 to 9 November 2026, instead of a calendar month",
  "A month that has ended keeps its figures as they were that night; a memo corrected later shows in a Corrected memos block rather than silently changing last month",
  "The start day is a number from 1 to 28 in Settings; the export to Excel uses the same period"],
 [{"file": "f596_1.jpg", "caption": "The report for 10 October to 9 November", "role": "admin", "url": "/admin/reports"}],
 ["Built this round from the spec (chapter 25). The report page and the Excel file use one period service so they cannot disagree. 8 new automatic tests together with item 551."])

w(551, "Built and tested live in the browser pane by hand. The owner opened the monthly report and saw when it was prepared, then pressed Prepare it again now.",
 ["The report says when it was prepared (22 October, 9:22 PM) and offers Prepare it again now; the heavy figures are worked out ahead and kept, so the page opens at once",
  "Every night at 02:00 Bangladesh time the current and the previous month are prepared; a month that has ended is served from the kept copy",
  "Tonight's summary and the comparison with last month and the same month last year are on the same page, with no figure shown for a month that has no data"],
 [{"file": "f551_1.jpg", "caption": "Tonight's summary and this month against the last", "role": "admin", "url": "/admin/reports"}],
 ["Built this round from the spec (chapter 25: big reports prepared overnight). New table report_snapshots. 8 automatic tests cover the snapshot, the nightly job and the period."])
