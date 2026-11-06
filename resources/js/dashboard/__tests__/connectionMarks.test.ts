import { describe, expect, it } from 'vitest';
import { discordMark, shareLinkMark, vrchatMark } from '../connectionMarks';
import type { VrchatProfile } from '../vrchat';

const profile = (over: Partial<VrchatProfile> = {}): VrchatProfile => ({
  vrchat_user_id: 'usr_1', display_name: null, picture_url: null, note: null, discord_id: null,
  stays: 0, total_ms: 0, one_on_one_ms: 0, first_at: null, last_at: null, ...over,
});

describe('vrchatMark', () => {
  it('has no mark without a VRChat ID, whatever else is known', () => {
    expect(vrchatMark(false, [])).toBe('none');
    expect(vrchatMark(false, [profile({ display_name: 'Alice' })])).toBe('none');
  });

  it('is muted while nothing confirms the ID: not synced, or VRCX has not cached the user', () => {
    expect(vrchatMark(true, undefined)).toBe('muted');
    expect(vrchatMark(true, [])).toBe('muted');
    expect(vrchatMark(true, [profile()])).toBe('muted');
  });

  it('is active once VRCX knows the account, by name or by shared visits', () => {
    expect(vrchatMark(true, [profile({ display_name: 'Alice' })])).toBe('active');
    expect(vrchatMark(true, [profile({ stays: 2 })])).toBe('active');
    expect(vrchatMark(true, [profile(), profile({ display_name: 'Second' })])).toBe('active');
  });
});

describe('discordMark', () => {
  it('has no mark without a Discord ID from either source', () => {
    expect(discordMark(false, [])).toBe('none');
    expect(discordMark(false, undefined)).toBe('none');
    expect(discordMark(false, [profile({ display_name: 'Alice' })])).toBe('none');
  });

  it('is muted when the ID is only what was typed into the field', () => {
    expect(discordMark(true, [])).toBe('muted');
    expect(discordMark(true, [profile({ display_name: 'Alice' })])).toBe('muted');
  });

  it('is active when VRCX reported a Discord ID, with or without the field filled in', () => {
    expect(discordMark(false, [profile({ discord_id: '123456789012345678' })])).toBe('active');
    expect(discordMark(true, [profile({ discord_id: '123456789012345678' })])).toBe('active');
  });
});

describe('shareLinkMark', () => {
  it('is red (unhighlighted) when a tokens field is chosen and the connection has none', () => {
    expect(shareLinkMark(true, true, [])).toBe('unhighlighted');
    expect(shareLinkMark(false, true, null)).toBe('unhighlighted');
    expect(shareLinkMark(true, true, undefined)).toBe('unhighlighted');
    expect(shareLinkMark(true, true, ['', '  '])).toBe('unhighlighted');
  });

  it('keeps the visited / never-visited look once there is at least one token', () => {
    expect(shareLinkMark(true, true, ['Alice'])).toBe('visited');
    expect(shareLinkMark(false, true, ['Alice'])).toBe('idle');
  });

  it('does not flag per connection when no tokens field is chosen at all', () => {
    expect(shareLinkMark(true, false, [])).toBe('visited');
    expect(shareLinkMark(false, false, null)).toBe('idle');
  });
});
