import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';

const BASE = 'http://127.0.0.1:8000';
const OUT = 'D:/projects/ecommerce/docs/qa/shots';
fs.mkdirSync(OUT, { recursive: true });

const PASS = 'DevPass-2026!';
const ROLES = {
    admin: 'admin@siamsaqua.test',
    staff: 'staff@siamsaqua.test',
    paikari: 'paikari@siamsaqua.test',
    wholesale: 'wholesale@siamsaqua.test',
    visitor: 'visitor@siamsaqua.test',
    vendor: 'demo-crispy-house@aquabazar.test',
    guest: null,
};

// [key, role, path]
const SCREENS = [
    // Admin (back office)
    ['a_dash', 'admin', '/dashboard'], ['a_tiers', 'admin', '/admin/tiers'], ['a_customers', 'admin', '/admin/customers'],
    ['a_customer', 'admin', '/admin/customers/4'], ['a_stock', 'admin', '/admin/stock'], ['a_stock_src', 'admin', '/admin/stock/sources'],
    ['a_stock_recv', 'admin', '/admin/stock/receive'], ['a_stock_count', 'admin', '/admin/stock/counts'], ['a_stock_exp', 'admin', '/admin/stock/expiry'],
    ['a_stock_mism', 'admin', '/admin/stock/mismatches'], ['a_stock_rep', 'admin', '/admin/stock/reports'], ['a_stock_med', 'admin', '/admin/stock/medicines/1'],
    ['a_counter', 'admin', '/admin/counter'], ['a_counter_off', 'admin', '/admin/counter/offline'], ['a_offers', 'admin', '/admin/offers'],
    ['a_orders', 'admin', '/admin/orders'], ['a_invoices', 'admin', '/admin/invoices'], ['a_returns', 'admin', '/admin/returns'],
    ['a_presc', 'admin', '/admin/prescriptions'], ['a_preorders', 'admin', '/admin/preorders'],
    ['a_mpo', 'admin', '/admin/mpo'], ['a_mpo_list', 'admin', '/admin/mpo/listings'],
    ['a_catalog', 'admin', '/admin/catalog'], ['a_reports', 'admin', '/admin/reports'], ['a_vat', 'admin', '/admin/reports/vat'],
    ['a_activity', 'admin', '/admin/activity'], ['a_staff', 'admin', '/admin/staff'], ['a_settings', 'admin', '/admin/settings'],
    ['a_deleted', 'admin', '/admin/deleted'], ['a_day', 'admin', '/admin/day'], ['a_eod', 'admin', '/admin/end-of-day'],
    ['a_controlled', 'admin', '/admin/controlled'], ['a_market', 'admin', '/admin/market'], ['a_market_comm', 'admin', '/admin/market/commissions'],
    ['a_market_cases', 'admin', '/admin/market/cases'], ['a_market_rep', 'admin', '/admin/market/reports'], ['a_market_stmt', 'admin', '/admin/market/statements'],
    ['a_apps', 'admin', '/admin/applications'], ['a_custom', 'admin', '/admin/custom'], ['a_craftsmen', 'admin', '/admin/craftsmen'],
    ['a_food', 'admin', '/admin/food'], ['a_food_ing', 'admin', '/admin/food-ingredients'], ['a_gaming', 'admin', '/admin/gaming'],
    ['a_help', 'admin', '/admin/help'], ['a_chats', 'admin', '/admin/chats'], ['a_messages', 'admin', '/admin/messages'],
    ['a_questions', 'admin', '/admin/questions'], ['a_reviews', 'admin', '/admin/reviews'], ['a_support', 'admin', '/admin/support'],
    ['a_tokens', 'admin', '/admin/tokens'], ['a_weights', 'admin', '/admin/weights'], ['a_couriers', 'admin', '/admin/couriers'],
    ['a_deliveries', 'admin', '/admin/deliveries'], ['a_delivery_cash', 'admin', '/admin/delivery-cash'], ['a_delivery_rate', 'admin', '/admin/delivery-ratings'],
    ['a_payments', 'admin', '/admin/payments'], ['a_site', 'admin', '/admin/site'], ['a_storefront', 'admin', '/admin/storefront'],
    ['a_testimonials', 'admin', '/admin/testimonials'], ['a_behavior', 'admin', '/admin/behavior'], ['a_connections', 'admin', '/admin/connections'],
    ['a_approvals', 'admin', '/admin/approvals'], ['a_requests', 'admin', '/admin/requests'], ['a_quotes', 'admin', '/admin/quotes'],
    // Staff
    ['s_counter', 'staff', '/admin/counter'], ['s_dash', 'staff', '/dashboard'],
    // Paikari
    ['k_home', 'paikari', '/paikari'], ['k_returns', 'paikari', '/paikari/returns'],
    ['k_orders', 'paikari', '/my-orders'], ['k_preorders', 'paikari', '/preorders'], ['k_wallet', 'paikari', '/wallet'],
    ['k_inbox', 'paikari', '/inbox'], ['k_history', 'paikari', '/history'], ['k_support', 'paikari', '/support'],
    // Wholesale
    ['w_home', 'wholesale', '/wholesale'], ['w_account', 'wholesale', '/wholesale/account'], ['w_sell', 'wholesale', '/wholesale/sell'], ['w_sell_orders', 'wholesale', '/wholesale/sell/orders'], ['w_sell_offers', 'wholesale', '/wholesale/sell/offers'],
    ['w_sell_rep', 'wholesale', '/wholesale/sell/reports'], ['w_sell_stmt', 'wholesale', '/wholesale/sell/statements'], ['w_quotes', 'wholesale', '/wholesale/quotes'],
    ['w_returns', 'wholesale', '/wholesale/returns'], ['w_questions', 'wholesale', '/wholesale/sell/questions'],
    // Public buyer
    ['u_orders', 'visitor', '/my-orders'], ['u_market_orders', 'visitor', '/market-orders'], ['u_wishlist', 'visitor', '/wishlist'],
    ['u_cart', 'visitor', '/market-cart'], ['u_profiles', 'visitor', '/custom/profiles'], ['u_settings', 'visitor', '/settings/profile'],
    ['u_2fa', 'visitor', '/settings/two-factor'], ['u_appearance', 'visitor', '/settings/appearance'], ['u_food_orders', 'visitor', '/food-orders'],
    ['u_gaming_orders', 'visitor', '/gaming-orders'], ['u_help_mine', 'visitor', '/help/mine'],
    // Public (not signed in)
    ['p_home', 'guest', '/'], ['p_market', 'guest', '/market'], ['p_market_all', 'guest', '/market?all=1'], ['p_item', 'guest', '/market/78'],
    ['p_offers', 'visitor', '/market-offers'], ['p_medicines', 'guest', '/medicines'], ['p_med_search', 'guest', '/medicines?q=napa'],
    ['p_search', 'guest', '/search?q=napa'], ['p_food', 'guest', '/food'], ['p_gaming', 'guest', '/gaming'], ['p_help', 'guest', '/help'],
    ['p_faq', 'guest', '/faq'], ['p_terms', 'guest', '/terms'], ['p_privacy', 'guest', '/privacy'], ['p_return', 'guest', '/return-policy'],
    ['p_cart', 'guest', '/cart'], ['p_custom', 'visitor', '/custom'], ['p_companies', 'guest', '/companies'],
    ['p_login', 'guest', '/login'], ['p_register', 'guest', '/register'], ['p_support', 'visitor', '/support'],
];

const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
const only = process.argv[2];
const failed = [];

for (const [role, email] of Object.entries(ROLES)) {
    const list = SCREENS.filter((s) => s[1] === role && (!only || s[0].startsWith(only)));
    if (!list.length) continue;
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'en-US' });
    await ctx.addCookies([{ name: 'locale', value: 'en', url: BASE }]);
    const page = await ctx.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.click('button:text-is("EN")');
    await page.waitForTimeout(1500);

    if (email) {
        await page.fill('input[type=email]', email);
        await page.fill('input[type=password]', PASS);
        await page.click('button[type=submit]');
        await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 20000 }).catch(() => {});
        await page.waitForLoadState('networkidle');
        if (page.url().includes('/login')) {
            console.log(`LOGIN FAILED for ${role}`);
            await ctx.close();
            continue;
        }
    }

    for (const [key, , p] of list) {
        try {
            const resp = await page.goto(BASE + p, { waitUntil: 'networkidle', timeout: 45000 });
            await page.waitForTimeout(700);
            const status = resp?.status() ?? 0;
            await page.screenshot({ path: path.join(OUT, `${key}.jpg`), type: 'jpeg', quality: 70, fullPage: false });
            const ct = resp?.headers()['content-type'] ?? '';
            console.log(`${key} ${status} ${page.url().replace(BASE, '')}${ct.includes('json') ? ' NOT-A-PAGE-JSON' : ''}`);
            if (status >= 400) failed.push(`${key} ${status}`);
        } catch (e) {
            console.log(`${key} ERROR ${e.message.split('\n')[0]}`);
            failed.push(key);
        }
    }
    await ctx.close();
}
await browser.close();
console.log('FAILED:', failed.join(', ') || 'none');
