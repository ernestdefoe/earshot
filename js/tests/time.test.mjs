import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clock, parseClock, findStamps, bars } from '../src/forum/time.js';

test('clock', () => {
  assert.equal(clock(0), '0:00');
  assert.equal(clock(62), '1:02');
  assert.equal(clock(3723), '1:02:03');
  assert.equal(clock(-5), '0:00');
});

test('parseClock', () => {
  assert.equal(parseClock('1:02:03'), 3723);
  assert.equal(parseClock('62:03'), 3723);
  assert.equal(parseClock(' 0:05 '), 5);
  assert.equal(parseClock('1:60'), null);
  assert.equal(parseClock('soon'), null);
});

test('findStamps: real times become chips, scores, long numbers and out-of-range times do not', () => {
  const text = 'At 1:30 and 1:02:03, but the score was 3:1, the ratio 12:345 and 99:00 is past the end.';
  assert.deepEqual(
    findStamps(text, 3800).map(([s, sec]) => [s, sec]),
    [
      ['1:30', 90],
      ['1:02:03', 3723],
    ]
  );
  assert.equal(findStamps('1:30', 60).length, 0);
  const [[, , index]] = findStamps('see 2:15 here', 600);
  assert.equal('see 2:15 here'.slice(index, index + 4), '2:15');
});

test('bars are the same for the same episode', () => {
  assert.deepEqual(bars(42), bars(42));
  assert.notDeepEqual(bars(42), bars(43));
  assert.ok(bars(7).every((h) => h > 0.2 && h <= 1));
});
