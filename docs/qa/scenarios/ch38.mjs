// Built after the first fix round: sending a finished memo as PDF, by WhatsApp or by email (feature 515).
import { addNapa, ensureSync, finishSale, newRetail } from './_counter.mjs';

export default [
    {
        n: 515, role: 'admin',
        did: 'Finished a sale, then looked at what the memo page offers for sending it. Pressed "Send by WhatsApp" and looked at the address it opened.',
        async run(t) {
            await ensureSync(t, true);
            await newRetail(t);
            await addNapa(t);
            await finishSale(t);
            await t.shot('The finished memo: print, PDF, WhatsApp and email');
            await t.see(/Print memo/, 'The memo can be printed');
            await t.see(/Save as PDF/, 'The memo can be saved as a PDF');
            await t.see(/Send by WhatsApp/, 'The memo can be sent by WhatsApp');
            await t.see(/Send by email/, 'The memo can be sent by email');
            const popup = t.page.waitForEvent('popup', { timeout: 8000 }).catch(() => null);
            await t.page.getByRole('button', { name: 'Send by WhatsApp' }).click();
            const w = await popup;
            const url = w ? w.url() : '';
            t.check(/wa\.me|whatsapp/.test(url) || (w && (await w.evaluate(() => location.href).catch(() => '')).includes('wa.me')), 'WhatsApp opens with the message already written', `no WhatsApp window opened (got "${url}")`);
            if (w) await w.close().catch(() => {});
        },
    },
];
