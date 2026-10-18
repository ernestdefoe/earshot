/**
 * README screenshots on a stock forum (Flarum's default theme).
 *
 *   EARSHOT_BASE=http://forum.test node tools/shots.mjs <session.json> <out-dir> <shots.json>
 *
 * shots.json: [{ "name", "path", "wait", "as": "member"|null, "phone": bool, "play": selector?, "click": selector?, "max": px }]
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
import { readFileSync } from 'node:fs';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.EARSHOT_BASE || 'http://waypoint-shots.test';
const S = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const OUT = process.argv[3];
const SHOTS = JSON.parse(readFileSync(process.argv[4], 'utf8'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--autoplay-policy=no-user-gesture-required', '--mute-audio', '--hide-scrollbars'] });
for (const shot of SHOTS) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setViewport(shot.phone ? { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true } : { width: 1440, height: 1000, deviceScaleFactor: 2 });
  if (shot.as) await page.setCookie({ name: 'flarum_remember', value: S[shot.as], domain: new URL(BASE).hostname, path: '/', httpOnly: true });
  await page.goto(`${BASE}${shot.path}`, { waitUntil: 'networkidle2' });
  await page.waitForSelector(shot.wait, { timeout: 15000 });
  if (shot.play) {
    await page.$eval(shot.play, (e) => e.scrollIntoView({ block: 'center' }));
    await page.click(shot.play);
    await sleep(shot.wait2 || 6000);
  }
  if (shot.click) {
    await page.click(shot.click);
    await sleep(2500);
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await sleep(1200);
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.screenshot({ path: `${OUT}/${shot.name}.png`, clip: { x: 0, y: 0, width: shot.phone ? 390 : 1440, height: Math.min(height, shot.max || 1400) }, captureBeyondViewport: true });
  console.log(shot.name);
  await ctx.close();
}
await browser.close();
