// Public market, part 1 (features 281-304): browsing, shops, product pages, reviews, requests

export default [
    {
        n: 281, role: 'guest',
        did: 'Opened the home page as a stranger and looked at the row of sector icons and names.',
        async run(t) {
            await t.go('/');
            await t.shot('The home page with the sector row');
            for (const s of ['Market', 'Electronics', 'Clothing', 'Jewellery', 'Pharmacy', 'Food', 'Game top-up']) await t.see(s, `The ${s} sector is on the top row`);
        },
    },
    {
        n: 282, role: 'guest',
        did: 'Opened the full product list and looked at the sort choices.',
        async run(t) {
            await t.go('/market?all=1');
            await t.shot('The full list with its sort choices');
            await t.see(/Nearest and newest/, 'The first sort choice puts the nearest shops, and the newest items, first');
        },
    },
    {
        n: 283, role: 'guest',
        did: 'Searched, filtered by price and changed the sort order on the market list.',
        async run(t) {
            await t.go('/market?all=1');
            await t.see(/Price, low to high/, 'Sort: price, low to high');
            await t.see(/Price, high to low/, 'Sort: price, high to low');
            await t.see(/Most ordered/i, 'Sort: most ordered');
            await t.shot('Price filters and sorting');
            await t.go('/market?q=soundbar');
            await t.shot('Searching for "soundbar"');
            await t.see(/Soundbar/i, 'Search finds the Soundbar');
        },
    },
    { n: 284, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Personal recommendations have not been built yet.'); } },
    {
        n: 285, role: 'guest',
        did: 'Looked at the budget buttons that let a shopper start from what they want to spend.',
        async run(t) {
            await t.go('/market?all=1');
            await t.shot('Budget ranges as buttons');
            await t.see(/৳[\d,]+\.\d\d to ৳[\d,]+\.\d\d/, 'Price ranges are offered as buttons');
        },
    },
    {
        n: 286, role: 'guest',
        did: 'Opened a shop\'s page and looked for the premium section.',
        async run(t) {
            await t.go('/market?sector=electronics');
            await t.shot('The Electronics shop: its premium picks');
            await t.see(/Premium/i, 'The shop has a Premium section that fills itself with the dearest items');
        },
    },
    { n: 287, role: 'admin', did: 'Looked for items moving in and out of premium when their price changes.', async run(t) { await t.go('/admin/market'); await t.shot('The owner\'s Market page'); t.skip('Changing a price and watching an item move in or out of Premium was not carried through.'); } },
    {
        n: 288, role: 'guest',
        did: 'Looked at the price ranges and compared them with the prices of the products.',
        async run(t) {
            await t.go('/market?all=1');
            const ranges = await t.page.getByRole('button', { name: /৳[\d,]+\.\d\d to|and above|ও তার বেশি/ }).allInnerTexts();
            t.note(`Ranges shown: ${ranges.join(' | ')}`);
            t.check(ranges.length > 0 && ranges.length < 8, `Only a handful of ranges that match real prices are shown (${ranges.length})`);
            await t.shot('Only price ranges that have products');
        },
    },
    {
        n: 289, role: 'admin',
        did: 'Opened the Catalogue page, which joins listings of the same product from different sellers.',
        async run(t) {
            await t.go('/admin/catalog');
            await t.shot('The catalogue: one product, many sellers');
            const body = await t.text();
            t.check(/catalog/i.test(body), 'The owner has a catalogue of products that several sellers can list under');
            t.skip('Only one seller exists in this copy, so a product page with two sellers side by side could not be shown.');
        },
    },
    {
        n: 291, role: 'admin',
        did: 'Opened the Product catalog, where each product has one name for every seller.',
        async run(t) {
            await t.go('/admin/catalog');
            await t.see(/One name for one product, so the same product from many sellers is one page with many prices/, 'The catalog keeps one name per product');
            await t.shot('The Product catalog');
        },
    },
    {
        n: 292, role: 'guest',
        did: 'Opened the seller\'s own page from a product.',
        async run(t) {
            await t.go('/market/78');
            await t.click(/Rahim Traders/, { wait: 2000 });
            await t.shot('The seller\'s own page');
            await t.see(/Rahim Traders/, 'The shop has a page of its own');
        },
    },
    {
        n: 293, role: 'visitor',
        did: 'On the seller\'s page, looked for a Follow button.',
        async run(t) {
            await t.go('/market/78');
            await t.click(/Rahim Traders/, { wait: 2000 });
            await t.shot('The shop\'s page for a signed-in shopper');
            const body = await t.text();
            t.check(/follow/i.test(body), 'The shop\'s page has a Follow button', 'no Follow button was found');
        },
    },
    {
        n: 294, role: 'guest',
        did: 'Looked at the product photo and pressed it to look closer.',
        async run(t) {
            await t.go('/market/78');
            await t.shot('The product photo');
            await t.page.getByRole('button', { name: 'Look closer' }).first().click();
            await t.wait(800);
            await t.shot('The photo opened large');
            const open = await t.page.locator('[role=dialog] img, .fixed img').count();
            t.check(open > 0, 'Pressing the photo opens it large, so the buyer can look closer');
            t.skip('Only one photo exists on this product, so the row of thumbnails to look through was not shown. The seller adds more with "More photos".');
        },
    },
    {
        n: 295, role: 'guest',
        did: 'Opened a clothing product and looked for size and colour choices.',
        async run(t) {
            await t.go('/market?sector=clothing');
            await t.shot('The Clothing shop');
            const href = await t.page.locator('a[href*="/market/"]').first().getAttribute('href');
            if (href) await t.go(href);
            await t.shot('A clothing product page');
            const body = await t.text();
            t.check(/size|colour|color/i.test(body), 'A clothing product offers size and colour choices', 'no size or colour choice was found on the first clothing product');
        },
    },
    {
        n: 296, role: 'guest',
        did: 'Looked at the reviews section of a product.',
        async run(t) {
            await t.go('/market/78');
            await t.see(/No review yet\. Only people who bought it can write one/, 'The reviews section says reviews come only from buyers');
            await t.shot('The reviews section');
            t.skip('Writing a review with photos needs a delivered order. Not carried through.');
        },
    },
    {
        n: 297, role: 'guest',
        did: 'Read who may write a review.',
        async run(t) {
            await t.go('/market/78');
            await t.see(/Only people who bought it can write one/, 'Only people who bought the product may review it');
            await t.shot('Only buyers can review');
        },
    },
    {
        n: 298, role: 'guest',
        did: 'Looked for the Share button on a product.',
        async run(t) {
            await t.go('/market/78');
            await t.see(/Share/, 'A product has a Share button');
            await t.shot('Share');
        },
    },
    {
        n: 299, role: 'guest',
        did: 'Looked on a product page for a price history.',
        async run(t) {
            await t.go('/market/78');
            await t.shot('A product page');
            const body = await t.text();
            t.check(/price history|was ৳|price changed/i.test(body), 'The page shows how the price has moved', 'no price history was found');
        },
    },
    {
        n: 300, role: 'guest',
        did: 'Looked on a product page for "frequently bought together".',
        async run(t) {
            await t.go('/market/78');
            await t.shot('A product page');
            const body = await t.text();
            t.check(/bought together|often bought/i.test(body), 'The page suggests items often bought together', 'no such section was found');
        },
    },
    {
        n: 301, role: 'visitor',
        did: 'Pressed "Not buying? Tell us why" on a product.',
        async run(t) {
            await t.go('/market/78');
            await t.click(/Not buying\? Tell us why/, { wait: 1200 });
            await t.shot('Tell us why you are not buying, and offer a price');
            await t.see(/price|why|reason/i, 'A form asks why, and lets the shopper offer a price');
        },
    },
    { n: 302, role: 'admin', did: 'Looked for price offers lapsing after 24 hours.', async run(t) { await t.go('/admin/requests'); await t.shot('Requests'); t.skip('Waiting 24 hours to watch an offer lapse was not possible.'); } },
    {
        n: 303, role: 'visitor',
        did: 'Searched for something the market does not sell and looked for the "I was looking for this" button.',
        async run(t) {
            await t.go('/medicines?q=zzzunobtainium');
            await t.shot('A medicine search with no result');
            await t.see(/I was looking for this/i, 'A medicine search with no result offers an "I was looking for this" button');
            await t.click('I was looking for this', { wait: 1500 });
            await t.shot('After pressing it');
            await t.see(/thank|noted|asked|we will|sent/i, 'Pressing it tells the owner what the shopper could not find');
            t.note('The button is on the medicine search and the home page search. The market list for other goods shows only "Nothing here yet."');
        },
    },
    {
        n: 304, role: 'admin',
        did: 'Opened the owner\'s requests page, where "looking for" requests are grouped.',
        async run(t) {
            await t.go('/admin/requests');
            await t.shot('Requests grouped by what shoppers asked for');
            await t.see(/request|asked|looking/i, 'The owner sees what shoppers asked for');
        },
    },
];
