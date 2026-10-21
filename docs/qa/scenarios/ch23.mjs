// Payments, legal and technical (features 572-613)

export default [
    {
        n: 572, role: 'admin',
        did: 'Opened Online payments and the Connections page to see which payment methods are set up.',
        async run(t) {
            await t.go('/admin/payments');
            await t.see(/Online payment is not switched on\. Set it up in Connections/, 'Online payment is not switched on in the test copy');
            await t.shot('Online payments');
            await t.go('/admin/connections');
            await t.shot('Connections');
            const b = await t.text();
            t.check(/bKash/i.test(b) && /Nagad/i.test(b) && /Rocket/i.test(b), 'bKash, Nagad and Rocket can be connected', 'only text messages and other services are listed on the Connections page');
            t.skip('The checklist marks the payment methods as partly built. No payment was made.');
        },
    },
    {
        n: 573, role: 'guest',
        did: 'Looked at cash on delivery on a product and the advance option for a wholesale buyer.',
        async run(t) {
            await t.go('/market/78');
            await t.see(/You pay when it arrives/, 'A product can be paid for on arrival');
            await t.shot('Cash on delivery');
            await t.as('wholesale');
            await t.go('/wholesale/account');
            await t.see(/Pay in advance/, 'A wholesale buyer can be set to pay in advance');
            t.skip('The checklist marks this partly built: paying an advance through the site was not made.');
        },
    },
    { n: 574, role: 'admin', did: 'Looked at a memo for Paid or Due.', async run(t) { await t.go('/memo/213'); await t.see(/Paid/, 'The memo says Paid'); await t.shot('Paid'); } },
    {
        n: 575, role: 'guest',
        did: 'Opened the Terms of Service and the Privacy Policy.',
        async run(t) {
            await t.go('/terms');
            await t.see(/What this site is/, 'The Terms of Service page opens');
            await t.shot('Terms of Service');
            await t.go('/privacy');
            await t.shot('Privacy Policy');
            await t.see(/privacy|data|information/i, 'The Privacy Policy page opens');
        },
    },
    {
        n: 576, role: 'admin',
        did: 'Opened the Controlled register.',
        async run(t) {
            await t.go('/admin/controlled');
            await t.see(/Every sale of a controlled medicine, for the drug-licence inspector/, 'A register of every controlled-medicine sale');
            await t.see(/Print/, 'It can be printed');
            await t.see(/Download CSV/, 'And downloaded');
            await t.shot('Controlled register');
            t.skip('A controlled medicine was not sold, so the register itself was empty.');
        },
    },
    { n: 577, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('The prescription marketplace has not been built.'); } },
    { n: 578, role: 'guest', did: 'Looked at a product for a verified badge.', async run(t) { await t.go('/market/78'); await t.see(/Trusted seller/, 'A seller with a clean record wears a "Trusted seller" mark'); await t.shot('Trusted seller'); } },
    {
        n: 579, role: 'admin',
        did: 'Looked on the Site design page for a WhatsApp and Messenger setting.',
        async run(t) {
            await t.go('/admin/site');
            await t.see(/WhatsApp number, with country code/, 'The owner can set a WhatsApp number');
            await t.see(/Messenger page name/, 'And a Messenger page');
            await t.shot('WhatsApp and Messenger');
        },
    },
    { n: 580, role: 'guest', did: 'Opened the FAQ.', async run(t) { await t.go('/faq'); await t.see(/Buying/, 'A help centre with questions and answers'); await t.shot('FAQ'); } },
    {
        n: 581, role: 'guest',
        did: 'Pressed the Bangla and English buttons at the top.',
        async run(t) {
            await t.go('/');
            await t.click('বাংলা', { wait: 1500 });
            await t.shot('The site in Bangla');
            await t.see(/[ঀ-৿]/, 'The page is shown in Bangla');
            await t.click('EN', { wait: 1500 });
            await t.shot('And back in English');
            await t.see(/Market/, 'And back in English');
        },
    },
    {
        n: 582, role: 'guest',
        did: 'Switched to Bangla and looked at the name of a product.',
        async run(t) {
            await t.go('/market/78');
            await t.click('বাংলা', { wait: 1500 });
            await t.shot('A product page in Bangla: the name is left as it was written');
            await t.see(/Matte silicone phone case/, 'The product name is not translated');
            await t.click('EN', { wait: 1200 });
        },
    },
    {
        n: 583, role: 'guest',
        did: 'Opened the files that make the site installable as an app.',
        async run(t) {
            await t.go('/manifest.webmanifest');
            await t.shot('The app manifest');
            await t.see(/name|short_name|start_url/, 'The site has an app manifest, so it can be installed on a phone');
            await t.go('/offline');
            await t.shot('The offline page');
        },
    },
    {
        n: 584, role: 'guest',
        did: 'Opened the files that help search engines find the site.',
        async run(t) {
            await t.go('/robots.txt');
            await t.shot('robots.txt');
            await t.see(/User-agent/i, 'A robots.txt file is served');
            await t.go('/sitemap.xml');
            await t.shot('sitemap.xml');
            await t.see(/urlset|sitemap/i, 'A sitemap is served');
        },
    },
    {
        n: 585, role: 'guest',
        did: 'Opened the home page and a product page on a phone-sized screen.',
        async run(t) {
            await t.phone(true);
            await t.go('/');
            await t.shot('The home page on a phone');
            await t.go('/market/78');
            await t.shot('A product page on a phone');
            const overflow = await t.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
            t.check(!overflow, 'The page fits a phone screen without sideways scrolling');
            await t.phone(false);
            t.skip('Loading speed on a real phone connection was not measured.');
        },
    },
    {
        n: 586, role: 'guest',
        did: 'Pressed the larger "A" in the header to change the text size.',
        async run(t) {
            await t.go('/market/78');
            const root = () => t.page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
            const before = await root();
            await t.shot('Normal text size');
            for (const label of ['Normal text size', 'Large text', 'Largest text']) t.check((await t.page.getByRole('button', { name: label }).count()) > 0, `A "${label}" button exists`);
            await t.page.getByRole('button', { name: 'Largest text' }).first().click();
            await t.wait(800);
            const after = await root();
            await t.shot('After choosing "Largest text"');
            t.check(after > before, `Choosing the largest makes the whole page's text bigger (${before}px became ${after}px)`, `the text stayed ${before}px`);
        },
    },
    { n: 587, role: 'guest', did: 'Looked at the web version.', async run(t) { await t.go('/'); await t.shot('The web version'); t.skip('The checklist marks "everything works on the web" as partly built. Not every feature was compared with the app.'); } },
    {
        n: 588, role: 'admin',
        did: 'Looked for the "A new version is ready" message.',
        async run(t) {
            const res = await t.page.request.get('http://127.0.0.1:8001/sw.js');
            const body = await res.text();
            t.check(res.ok() && /\/\/ build: /.test(body), 'The site serves an installable app worker that carries its build number, which is how it knows a newer build exists', 'no worker with a build number was found');
            await t.go('/dashboard');
            await t.shot('The dashboard (the banner only appears once a newer build has been fetched)');
            t.skip('The message "A new version is ready" appears only after a second build is published and the installed app fetches it. Only one build existed, so the banner itself could not be shown.');
        },
    },
    { n: 589, role: 'wholesale', did: 'Looked at the seller listing form for choosing the main photo.', async run(t) { await t.go('/wholesale/sell'); await t.shot('The seller\'s listings'); const b = await t.text(); t.check(/main photo|first photo|cover/i.test(b), 'A seller can choose the main photo', 'no main-photo choice was found'); } },
    { n: 590, role: 'admin', did: 'Looked on the dashboard for the daily backup.', async run(t) { await t.go('/dashboard'); await t.shot('The dashboard: backup status'); await t.see(/Last backup/, 'The owner sees when the last backup was made'); await t.see(/The nightly backup is not running|Last backup/, 'And is warned when the nightly backup is not running'); } },
    { n: 591, role: 'admin', did: 'Looked for backups stored outside the server.', async run(t) { await t.go('/admin/connections'); await t.shot('Connections'); t.skip('The checklist marks off-server backup as partly built. Not found on a screen.'); } },
    { n: 592, role: 'admin', did: 'Looked for the monthly backup test.', async run(t) { await t.go('/admin/settings'); await t.shot('Settings'); t.skip('Not found on a screen.'); } },
    { n: 593, role: 'admin', did: 'Opened the counter without internet.', async run(t) { await t.go('/admin/counter/offline'); await t.shot('The counter without internet'); await t.see(/internet|offline|sync/i, 'A counter page that keeps working without internet'); t.skip('The checklist marks this as partly built. A real internet drop was not reproduced.'); } },
    { n: 594, role: 'admin', did: 'Looked for a bulk import of old data.', async run(t) { await t.go('/admin/settings'); await t.shot('Settings'); t.skip('No import screen was found. The medicine database was loaded in bulk before this test.'); } },
    {
        n: 595, role: 'admin',
        did: 'Made a retail sale and compared the time printed on the memo with the real time in Bangladesh.',
        async run(t) {
            await t.go('/admin/counter');
            await t.click('New retail sale', { wait: 1800 });
            await t.page.getByPlaceholder(/Medicine name/).fill('napa');
            await t.wait(1300);
            await t.click(/Box.{0,4}300/, { wait: 1200 });
            await t.click('Finish sale', { wait: 2500 });
            const body = await t.text();
            const m = body.match(/(\d{1,2}) (\w{3}) (\d{4}), (\d{2}):(\d{2})/);
            const dhaka = new Date().toLocaleString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Dhaka' });
            await t.shot('The memo time');
            const shown = m ? `${m[4]}:${m[5]}` : null;
            t.note(`The memo says ${shown}. The time in Dhaka is ${dhaka}. Times on screen follow the viewer's own clock. Rules that run on the server, such as the night hours, use the server's clock, which is set to UTC (six hours behind Bangladesh): see feature 157.`);
            const diff = shown ? Math.abs(Number(shown.slice(0, 2)) - Number(dhaka.slice(0, 2))) : 99;
            t.check(diff <= 1, 'The system clock shows Bangladesh time', `the memo shows ${shown} while Dhaka time is ${dhaka}: the system clock is set to UTC, six hours behind`);
        },
    },
    { n: 596, role: 'admin', did: 'Looked for the monthly report date in settings.', async run(t) { await t.go('/admin/settings'); await t.see(/Month-end window for tier tasks/, 'A month-end window is set in Settings'); await t.shot('Month-end window'); t.skip('The checklist marks the monthly report date as partly built. A date for the monthly report itself was not found.'); } },
    { n: 597, role: 'admin', did: 'Opened "What people look for".', async run(t) { await t.go('/admin/behavior'); await t.see(/What people look for/, 'The site records what visitors search and view'); await t.see(/Visitors, step by step/, 'From search to cart'); await t.shot('Behaviour data'); } },
    { n: 598, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Old data stored separately has not been built yet.'); } },
    { n: 599, role: 'admin', did: 'Looked for image compression.', async run(t) { await t.go('/admin/site'); await t.shot('Site design'); await t.see(/up to 2 MB/, 'Uploads are limited in size'); t.skip('Automatic compression of uploaded images was not measured.'); } },
    { n: 600, role: 'guest', did: 'Looked for a low-quality image that loads first.', async run(t) { await t.go('/market/78'); await t.shot('A product page'); t.skip('Progressive image loading was not measured.'); } },
    { n: 601, role: 'admin', did: 'Looked for festival peak handling.', async run(t) { await t.go('/admin/settings'); await t.shot('Settings'); t.skip('The checklist marks this as partly built. A load test was not run.'); } },
    { n: 602, role: 'admin', did: 'Looked at the notice shown on every page.', async run(t) { await t.go('/admin/settings'); await t.see(/Notice shown on every page \(for example, an update tonight at 2 am\)/, 'The owner can post a notice on every page, for example about a night update'); await t.shot('A notice for updates at night'); } },
    { n: 603, role: 'paikari', did: 'Looked at the wallet for reward points and referral.', async run(t) { await t.go('/wallet'); await t.shot('Wallet'); const b = await t.text(); t.check(/reward|points|referral|refer/i.test(b), 'The wallet shows reward points or a referral code', 'only Aqua Tokens and return credit are shown'); } },
    { n: 604, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Bundle deals and flash sales have not been built yet.'); } },
    { n: 605, role: 'visitor', did: 'Looked for an abandoned cart reminder.', async run(t) { await t.go('/market-cart'); await t.shot('The cart'); t.skip('Waiting for a reminder to arrive was not possible.'); } },
    { n: 606, role: 'visitor', did: 'Looked at the wishlist for a price-drop alert.', async run(t) { await t.go('/wishlist'); await t.shot('The wishlist'); t.skip('A price drop and its alert were not reproduced.'); } },
    {
        n: 607, role: 'visitor',
        did: 'Opened a support ticket as a shopper, then looked at it on the owner\'s Support page.',
        async run(t) {
            await t.go('/support/new');
            await t.page.getByLabel('Mobile number', { exact: true }).fill('01711111111');
            await t.fill('Title', 'My order is late');
            await t.fill('Tell us what happened', 'The phone case has not arrived.');
            await t.shot('A support ticket filled in');
            await t.click('Send', { wait: 2500 });
            await t.shot('The ticket is sent');
            await t.as('admin');
            await t.go('/admin/support');
            await t.shot('The owner\'s Support tickets');
            await t.see(/My order is late/, 'The owner sees the ticket');
        },
    },
    { n: 608, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Facebook Pixel and Analytics have not been built yet.'); } },
    { n: 609, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Affiliate and bulk text messages have not been built yet.'); } },
    { n: 610, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('A credit ledger (baki khata) has not been built yet.'); } },
    { n: 611, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Company and supplier dues have not been built yet.'); } },
    { n: 612, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('A full PharmaTrack sync has not been built yet.'); } },
    { n: 613, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Service and coaching booking has not been built yet.'); } },
];
