// Partly-shown pass, the help board: a product card inside a comment (features 453, 454).
// Set-up: a shopper posted "Need a carpenter in Mirpur" and the owner published it (post 1).

const LINK = '/medicines/1';

export default [
    {
        n: 453, role: 'wholesale',
        did: 'On the help post, wrote a comment with a link to one of the shop\'s products and pressed Comment.',
        async run(t) {
            await t.go('/help/1');
            await t.page.locator('textarea').fill(`I can supply the wood fittings. Also see this: ${LINK}`);
            await t.shot('A comment with a product link pasted in');
            await t.click('Comment', { wait: 2200 });
            await t.wait(1500);
            await t.shot('After commenting: the link has become a product card');
            await t.see(/Napa 500 mg[\s\S]{0,80}View/, 'The pasted medicine link is shown as a card with the medicine name and a View button');
        },
    },
    {
        n: 454, role: 'guest',
        did: 'Opened the help post as a visitor and looked at the product card inside the comment.',
        async run(t) {
            await t.go('/help/1');
            await t.shot('The product card in a comment');
            await t.see(/Napa/, 'The card shows the product name');
            await t.see(/৳\d/, 'And its price');
            const view = t.page.getByRole('link', { name: /View/ }).first();
            t.check((await view.count()) > 0, 'And a View button that opens the product');
            t.note('This medicine has no picture in the database, so the card shows a grey tile where the photo goes.');
        },
    },
];
