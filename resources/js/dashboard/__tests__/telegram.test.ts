import { describe, expect, it } from 'vitest';
import { type PendingTelegramContact, telegramContactName, telegramContactNames } from '../telegram';

const contact = (over: Partial<PendingTelegramContact> = {}): PendingTelegramContact => ({
  id: 'row-1',
  telegram_user_id: '1000000001',
  username: 'fox',
  name: 'Fox Person',
  phone: null,
  picture_url: null,
  mutual: true,
  premium: false,
  ...over,
});

describe('telegramContactName', () => {
  it('leads with their name', () => {
    expect(telegramContactName(contact())).toBe('Fox Person');
  });

  it('falls back to @username, then the id', () => {
    expect(telegramContactName(contact({ name: null }))).toBe('@fox');
    expect(telegramContactName(contact({ name: null, username: null }))).toBe('1000000001');
  });
});

describe('telegramContactNames', () => {
  it('lists the name then the username, for matching against connections', () => {
    expect(telegramContactNames(contact())).toEqual(['Fox Person', 'fox']);
    expect(telegramContactNames(contact({ name: null }))).toEqual([null, 'fox']);
  });
});
