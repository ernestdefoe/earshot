/**
 * The player end to end, in real Chrome: play from a show page, keep playing
 * across an in-app navigation to the episode's discussion, timestamp chips in
 * a reply, and the place saved for the member.
 *
 *   EARSHOT_BASE=http://forum.test node tools/e2e.mjs <session.json> <out-dir>
 *
 * session.json: {"member": "<remember token>", "show": "<slug>", "disc": <discussion id with a reply mentioning 1:30>}
 */
import puppeteer from '../../convoro2/node_modules/puppeteer-core/lib/puppeteer/puppeteer-core.js';
import { readFileSync } from 'node:fs';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const BASE = process.env.EARSHOT_BASE || 'http://waypoint-shots.test';
const S = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const OUT = process.argv[3] || '.';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fails = [];
const check = (ok, what) => {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${what}`);
  if (!ok) fails.push(what);
};

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--autoplay-policy=no-user-gesture-required', '--mute-audio'] });
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && !m.text().startsWith('Failed to load resource') && errors.push(m.text()));
page.on('response', (r) => r.status() >= 400 && !r.url().endsWith('/favicon.ico') && errors.push(`${r.status()} ${r.url()}`));
await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
await page.setCookie({ name: 'flarum_remember', value: S.member, domain: new URL(BASE).hostname, path: '/', httpOnly: true });

const stamp = () => page.evaluate(() => (app.earshot.player ? app.earshot.player.stamp() : null));
const seconds = (s) => (s ? s.split(':').reduce((a, b) => a * 60 + +b, 0) : -1);

// The forum must boot at all: an initializer that throws takes every page down.
await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
check(!!(await page.$('.DiscussionList, .IndexPage')), 'the forum boots with Earshot enabled');

await page.goto(`${BASE}/podcasts/${S.show}`, { waitUntil: 'networkidle2' });
await page.waitForSelector('.EarshotEpisode-play');
await page.screenshot({ path: `${OUT}/show.png` });

await page.click('.EarshotEpisode-play');
await page.waitForSelector('.EarshotDock', { timeout: 10000 });
await sleep(5000);
const t1 = seconds(await stamp());
check(t1 >= 2, `audio plays from the show page (at ${t1}s)`);

// In-app navigation to the episode's discussion: the player must keep going.
// Scrolled to the middle first: at the bottom of the window the dock covers it.
await page.$eval('.EarshotEpisode--current .EarshotEpisode-title', (e) => e.scrollIntoView({ block: 'center' }));
await page.click('.EarshotEpisode--current .EarshotEpisode-title');
await page.waitForSelector('.EarshotHero--episode', { timeout: 10000 });
await sleep(3000);
const t2 = seconds(await stamp());
check(t2 > t1, `still playing after moving to the discussion (${t1}s → ${t2}s)`);
check(!!(await page.$('.EarshotDock')), 'the dock survives navigation');
await page.screenshot({ path: `${OUT}/episode.png` });

// Timestamp chips, on the discussion that has the reply.
await page.goto(`${BASE}/d/${S.disc}`, { waitUntil: 'networkidle2' });
await page.waitForSelector('.EarshotHero--episode');
await page.waitForSelector('.EarshotStamp', { timeout: 10000 }).catch(() => null);
const chips = await page.$$eval('.EarshotStamp', (els) => els.map((e) => e.textContent));
check(JSON.stringify(chips) === '["1:30"]', `only the real time became a chip (got ${JSON.stringify(chips)}; 3:1, a link and code stay text)`);
if (chips.length) {
  await page.click('.EarshotStamp');
  await sleep(2500);
  const t3 = seconds(await stamp());
  check(t3 >= 90 && t3 < 100, `the chip plays from 1:30 (at ${t3}s)`);
}

// Chapters / seek bar present, pause saves the place.
await page.click('.EarshotDock-play');
await sleep(1500);
const saved = await page.evaluate(async (base) => {
  const r = await fetch(`${base}/api/discussions/${location.pathname.split('/')[2].split('-')[0]}`, { credentials: 'include' });
  const j = await r.json();
  return j.data.attributes.earshot.episode.progress;
}, BASE);
check(saved && saved.position >= 90, `pausing saved the member's place (${JSON.stringify(saved)})`);

// Phone layout.
await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
await page.reload({ waitUntil: 'networkidle2' });
await page.waitForSelector('.EarshotHero--episode');
await page.screenshot({ path: `${OUT}/phone-episode.png` });
const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
check(overflow <= 0, `no sideways scroll on a phone (${overflow}px)`);

check(errors.length === 0, `no page errors ${errors.length ? JSON.stringify(errors.slice(0, 3)) : ''}`);
await browser.close();
process.exit(fails.length ? 1 : 0);
