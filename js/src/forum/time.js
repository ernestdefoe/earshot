/* Time helpers with no Flarum imports, so they can be unit-tested in Node. */

/** 3723 → "1:02:03", 62 → "1:02". */
export function clock(seconds) {
  const s = Math.max(0, Math.floor(seconds || 0));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const ss = String(s % 60).padStart(2, '0');
  return h ? `${h}:${String(m).padStart(2, '0')}:${ss}` : `${m}:${ss}`;
}

/** "1:02:03" or "62:03" → seconds, or null. */
export function parseClock(text) {
  const m = /^(?:(\d{1,2}):)?(\d{1,3}):(\d{2})$/.exec(String(text).trim());
  if (!m || +m[3] > 59) return null;
  return (+m[1] || 0) * 3600 + +m[2] * 60 + +m[3];
}

/** A time in running text: not part of a longer number or a ratio like 3:1. */
export const STAMP = /(^|[^\d:])((?:\d{1,2}:)?\d{1,3}:[0-5]\d)(?![\d:])/g;

/** The times in a piece of text that fall inside an episode of `limit` seconds: [[text, seconds, index], ...]. */
export function findStamps(text, limit) {
  const out = [];
  String(text).replace(STAMP, (match, before, stamp, offset) => {
    const seconds = parseClock(stamp);
    if (seconds !== null && seconds <= limit) out.push([stamp, seconds, offset + before.length]);
    return match;
  });
  return out;
}

/** A deterministic bar pattern for the hero's seek bar, the same every visit for one episode. */
export function bars(seed, count = 72) {
  let x = (seed * 9301 + 49297) % 233280;
  const out = [];
  for (let i = 0; i < count; i++) {
    x = (x * 9301 + 49297) % 233280;
    const r = x / 233280;
    out.push(0.28 + 0.72 * Math.abs(Math.sin(i * 0.55 + r * 2.2)) * (0.55 + 0.45 * r));
  }
  return out;
}
