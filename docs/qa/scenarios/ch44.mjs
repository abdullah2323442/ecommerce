// Partly-shown pass, the nightly checks. RUN AGAINST THE AGED SERVER (clock 35 days ahead):
//   QA_BASE=http://127.0.0.1:8002 node run.mjs ch44
// Set-up on the normal site before the days "passed": the buyer offered a price for the phone case, a shopper placed a food order nobody confirmed,
// the buyer wrote in the job chat and nobody answered, job C2026-00002 was marked ready, and MPO Kabir's Napa was on the market without a sale.
// Then the app's own scheduled checks were run once with the clock 35 days ahead (the same calls the nightly schedule makes).
import { ACCOUNTS } from '../runner/lib.mjs';

ACCOUNTS.mpo2 = 'mpo.kabir@siamsaqua.test';

export default [
    {
        n: 302, role: 'wholesale',
        did: 'The buyer named a price for the seller\'s phone case, and nobody answered. Thirty-five days later the seller\'s Price offers page and the buyer\'s messages were opened.',
        async run(t) {
            await t.go('/wholesale/sell/offers');
            await t.shot('The seller: the offer is gone');
            await t.see(/No offer is waiting\./, 'The unanswered offer is no longer waiting for the seller');
            await t.as('visitor');
            await t.go('/inbox');
            await t.shot('The buyer is told');
            await t.see(/Your price offer for "Matte silicone phone case" ran out\./, 'The buyer is told the offer ran out');
        },
    },
    {
        n: 422, role: 'visitor',
        did: 'A shopper\'s food order was never confirmed. Later the shopper\'s messages were opened.',
        async run(t) {
            await t.go('/inbox');
            await t.shot('The shopper is asked about the order nobody confirmed');
            await t.see(/Your order F\d+-\d+ has not been approved yet\. Do you want to cancel it\?/, 'The shopper is told the order has not been approved and is asked if they want to cancel it');
        },
    },
    {
        n: 482, role: 'visitor',
        did: 'A made-to-order job was ready for collection and nobody came. Later the buyer\'s messages were opened.',
        async run(t) {
            await t.go('/inbox');
            await t.shot('The buyer is reminded to collect');
            await t.see(/C2026-00002 has been ready for some days\. Please collect it\./, 'The buyer gets a reminder that the job has been ready for some days');
        },
    },
    {
        n: 408, role: 'admin',
        did: 'The buyer wrote in the job chat and nobody answered. Later the owner opened the chat.',
        async run(t) {
            await t.go('/admin/chats');
            await t.shot('The chat list');
            await t.see(/When can I collect the table\?[\s\S]{0,60}Not answered in time|Not answered in time[\s\S]{0,200}When can I collect the table\?/, 'A buyer\'s message nobody answered is marked "Not answered in time"');
            await t.go('/admin/chats/2');
            await t.shot('The same flag inside the chat');
            await t.see(/Not answered in time/, 'The flag shows inside the chat too');
        },
    },
    {
        n: 236, role: 'mpo2',
        did: 'MPO Kabir\'s product was on the market without a sale. Later the MPO\'s page was opened.',
        async run(t) {
            await t.go('/mpo');
            await t.shot('The MPO is asked to raise the percentage');
            await t.see(/Your product Napa 500 mg is not selling\. Would you like to raise the percentage yourself\?/, 'An MPO whose goods sit unsold is asked to raise the percentage');
        },
    },
    {
        n: 457, role: 'visitor',
        did: 'A help post stayed up with nobody answering. Thirty-five days later the nightly sweep ran, and the poster page was opened.',
        async run(t) {
            await t.go('/help/mine');
            await t.shot('The poster is asked if the post is still needed');
            await t.see(/Is this still needed\? If you do not answer, it will close\./, 'After 30 days the poster is asked "Is this still needed?" and told it will close if not answered');
            await t.see(/Yes, still needed/, 'And can answer yes to keep it open');
        },
    },
];
