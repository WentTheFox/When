import { describe, expect, it } from 'vitest';
import { buildSettingsHash, parseSettingsHash, SETTINGS_TABS, tabForAnchor } from '../settingsTabs';

describe('settings tab hash', () => {
  it('opens the calendar tab by default and for anything it does not recognise', () => {
    expect(parseSettingsHash('')).toEqual({ tab: 'calendar' });
    expect(parseSettingsHash('#')).toEqual({ tab: 'calendar' });
    expect(parseSettingsHash('#tab=nope')).toEqual({ tab: 'calendar' });
    expect(parseSettingsHash('#no-such-element')).toEqual({ tab: 'calendar' });
  });

  it('reads and writes a tab name', () => {
    for (const tab of Object.keys(SETTINGS_TABS) as (keyof typeof SETTINGS_TABS)[]) {
      expect(parseSettingsHash(buildSettingsHash(tab))).toEqual({ tab });
    }
    expect(buildSettingsHash('calendar')).toBe('');
    expect(buildSettingsHash('vrchat')).toBe('#tab=vrchat');
  });

  it('turns an element-id link into its tab plus the element to scroll to', () => {
    expect(parseSettingsHash('#share-links')).toEqual({ tab: 'sharing', anchor: 'share-links' });
    expect(parseSettingsHash('#highlight-tokens')).toEqual({ tab: 'sharing', anchor: 'highlight-tokens' });
    expect(parseSettingsHash('#bypass-dnd')).toEqual({ tab: 'sharing', anchor: 'bypass-dnd' });
    expect(parseSettingsHash('#vrchat-friends')).toEqual({ tab: 'vrchat', anchor: 'vrchat-friends' });
    expect(parseSettingsHash('#discord-user-id')).toEqual({ tab: 'discord', anchor: 'discord-user-id' });
    expect(parseSettingsHash('#dnd_event_pattern')).toEqual({ tab: 'matching', anchor: 'dnd_event_pattern' });
    expect(parseSettingsHash('#timezone')).toEqual({ tab: 'calendar', anchor: 'timezone' });
  });

  it('puts every pattern field on the matching tab and nothing else on it by accident', () => {
    for (const id of ['dnd_event_pattern', 'highlight_clause_pattern', 'public_event_pattern', 'open_start_pattern']) {
      expect(tabForAnchor(id)).toBe('matching');
    }
    expect(tabForAnchor('patterns')).toBeNull();
    expect(tabForAnchor('constructor')).toBeNull();
  });

  it('has one tab per settings section', () => {
    expect(Object.keys(SETTINGS_TABS)).toEqual(['calendar', 'availability', 'sleep', 'matching', 'activities', 'sharing', 'vrchat', 'discord', 'public']);
  });

  it('survives malformed percent-encoding', () => {
    expect(parseSettingsHash('#%E0%A4%A')).toEqual({ tab: 'calendar' });
  });
});
