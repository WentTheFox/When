/**
 * Tabs of the Settings page and their URL hash, so a reload (or a link) reopens
 * the same one:
 *
 *   #tab=vrchat        the tab itself (Calendar is the default and is omitted)
 *   #share-links       an element id: the tab holding it opens and it scrolls into view
 *
 * Element-id links already exist (the "Choose one below" links between cards, a
 * link from the custom attributes panel), and a link into a card that sits in a
 * hidden tab needs the tab to open first, hence tabForAnchor.
 */

/** One tab per settings section (each is its own form with its own Save). */
export const SETTINGS_TABS = {
  calendar: 'settings-tab-calendar',
  availability: 'settings-tab-availability',
  sleep: 'settings-tab-sleep',
  matching: 'settings-tab-matching',
  activities: 'settings-tab-activities',
  sharing: 'settings-tab-sharing',
  connections: 'settings-tab-connections',
  vrchat: 'settings-tab-vrchat',
  discord: 'settings-tab-discord',
  public: 'settings-tab-public',
} as const;

export type SettingsTabName = keyof typeof SETTINGS_TABS;

const DEFAULT_TAB: SettingsTabName = 'calendar';

/** Element ids reachable by a link, and the tab that holds each. */
const ANCHOR_TABS: Record<string, SettingsTabName> = {
  calendar_url: 'calendar',
  calendar_parsing_mode: 'calendar',
  timezone: 'calendar',
  week_start: 'calendar',
  'share-links': 'sharing',
  'highlight-tokens': 'sharing',
  'bypass-dnd': 'sharing',
  connections: 'connections',
  'alternative-names': 'connections',
  vrchat: 'vrchat',
  'vrchat-user-id': 'vrchat',
  'vrchat-friends': 'vrchat',
  discord: 'discord',
  'discord-user-id': 'discord',
  public_page_title: 'public',
};

export function isSettingsTab(value: string | null | undefined): value is SettingsTabName {
  return !!value && Object.prototype.hasOwnProperty.call(SETTINGS_TABS, value);
}

/** The tab holding an element id; the event title matching fields are all `*_pattern`. */
export function tabForAnchor(id: string): SettingsTabName | null {
  if (Object.prototype.hasOwnProperty.call(ANCHOR_TABS, id)) return ANCHOR_TABS[id];

  return id.endsWith('_pattern') ? 'matching' : null;
}

export interface SettingsHashState {
  tab: SettingsTabName;
  /** An element to scroll to once its tab is open. */
  anchor?: string;
}

export function parseSettingsHash(hash: string): SettingsHashState {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash;
  if (raw === '') return { tab: DEFAULT_TAB };

  if (raw.includes('=')) {
    const tab = new URLSearchParams(raw).get('tab');
    return { tab: isSettingsTab(tab) ? tab : DEFAULT_TAB };
  }

  let anchor = raw;
  try {
    anchor = decodeURIComponent(raw);
  } catch {
    // Not valid percent-encoding: use it as typed.
  }

  const tab = tabForAnchor(anchor);
  return tab === null ? { tab: DEFAULT_TAB } : { tab, anchor };
}

/** Inverse of the tab form; '' for the default tab. */
export function buildSettingsHash(tab: SettingsTabName): string {
  return tab === DEFAULT_TAB ? '' : `#tab=${tab}`;
}
