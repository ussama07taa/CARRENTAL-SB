import assert from 'node:assert/strict';
import test from 'node:test';
import {moroccoDateTimeFormat} from './morocco-time.ts';

const clock = moroccoDateTimeFormat('en-GB', {hour: '2-digit', minute: '2-digit', hourCycle: 'h23'});

test('keeps the historical Ramadan changes and the September transition', () => {
  assert.equal(clock.format(new Date('2026-02-15T01:30:00Z')), '02:30');
  assert.equal(clock.format(new Date('2026-02-15T02:30:00Z')), '02:30');
  assert.equal(clock.format(new Date('2026-03-22T02:30:00Z')), '03:30');
  assert.equal(clock.format(new Date('2026-09-20T00:59:00Z')), '01:59');
  assert.equal(clock.format(new Date('2026-09-20T01:00:00Z')), '01:00');
});

test('matches server time even when the runtime still applies UTC+1', () => {
  assert.equal(clock.format(new Date('2026-10-24T00:25:00Z')), '00:25');
  assert.equal(clock.format(Date.parse('2027-01-15T12:00:00Z')), '12:00');
});

test('keeps the correct payment and inspection date near midnight', () => {
  const date = moroccoDateTimeFormat('en-CA', {year: 'numeric', month: '2-digit', day: '2-digit'});
  const instant = new Date('2026-10-24T23:30:00Z');
  assert.equal(date.format(instant), '2026-10-24');
  const parts = Object.fromEntries(date.formatToParts(instant).map(part => [part.type, part.value]));
  assert.deepEqual([parts.year, parts.month, parts.day], ['2026', '10', '24']);
});
