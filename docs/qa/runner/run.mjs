// usage: node run.mjs ch01 [22 23 ...]   (runs docs/qa/scenarios/ch01.mjs, optionally only the listed features)
import path from 'node:path';
import { Sessions, runFile, startBrowser } from './lib.mjs';

const [chapter, ...nums] = process.argv.slice(2);
const only = nums.map(Number);
const browser = await startBrowser();
const sessions = new Sessions(browser);
try {
    await runFile(path.resolve(import.meta.dirname, '..', 'scenarios', `${chapter}.mjs`), only, sessions);
} finally {
    await sessions.close();
    await browser.close();
}
