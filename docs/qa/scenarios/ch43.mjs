// Partly-shown pass, features that need time to pass. RUN THIS FILE AGAINST THE AGED SERVER:
//   QA_BASE=http://127.0.0.1:8002 node run.mjs ch43
// The aged server is the same copy of the site with its clock set 35 days ahead (docs/qa/runner/router.php, QA_HOURS=840).
// Nothing was edited in the data: the days simply went by.

export default [
    {
        n: 215, role: 'admin',
        did: 'Opened the owner\'s Offers page when the clock was 35 days later, after the month the offers were meant for had ended (they were set to end on 31 October).',
        async run(t) {
            await t.go('/admin/offers');
            await t.shot('Offers, 35 days later: the running offers have ended by themselves');
            await t.see(/No offer products running\./, 'The offer products that were running have ended with the month');
            await t.see(/No offers running\./, 'And so have the shop-tier offers');
        },
    },
    {
        n: 216, role: 'admin',
        did: 'On the same page, read what the system asks the owner once the month has passed.',
        async run(t) {
            await t.go('/admin/offers');
            await t.shot('The question after the month');
            await t.see(/The month is over\. Keep these offers on\?/, 'The system asks whether to keep the offers on');
            await t.see(/Run again this month/, 'The owner can run them again for the new month');
            await t.see(/Leave it off/, 'Or leave them off');
        },
    },
    {
        n: 508, role: 'admin',
        did: 'Opened the owner\'s Pre-orders page 35 days later, when the promised days for the accepted pre-orders had gone by.',
        async run(t) {
            await t.go('/admin/preorders');
            await t.shot('Pre-orders past their time');
            await t.see(/\d+ pre-orders are past their time\./, 'The page warns that pre-orders are past their promised time');
            await t.see(/Past its time/, 'Each late pre-order is marked');
            await t.see(/Did not come, cancel/, 'The owner can give a new time or cancel what did not come');
        },
    },
    {
        n: 117, role: 'admin',
        did: 'Opened the counter 35 days later and looked at how long the old open drafts say they have been open, and how they look next to a new one.',
        async run(t) {
            await t.go('/admin/counter');
            await t.shot('Old drafts at the counter');
            await t.see(/\d{1,3}(,\d{3})* min open/, 'Each draft shows how long it has been open');
            const styled = await t.page.evaluate(() => {
                const el = [...document.querySelectorAll('main *')].find((e) => e.children.length === 0 && /min open/.test(e.textContent ?? ''));
                return el ? getComputedStyle(el).color + '|' + el.className : '';
            });
            t.note(`The age text on an old draft is drawn as: ${styled}`);
            const body = await t.text();
            t.check(/Keep|return its goods|nobody touched|forgotten/i.test(body), 'An old draft is singled out and the owner is asked what to do with it', 'old drafts look the same as new ones');
        },
    },
];
