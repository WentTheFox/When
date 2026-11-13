/**
 * Shapes for the optional Telegram contacts import (§0.2 tier: server-readable; see
 * TelegramSyncService::summary()).
 */
/** A Telegram contact linked to a connection, as the sync saw them (§0.2 tier, not E2EE). */
export interface TelegramProfile {
  telegram_user_id: string;
  /** The server has this person's names. False is a placeholder for an ID the sync has not seen: everything else is null. */
  known: boolean;
  username: string | null;
  /** First and last name, as one string. */
  name: string | null;
  /** A small photo thumbnail as a data URL. */
  picture_url: string | null;
}

export interface TelegramSummary {
  /** The operator set up the Telegram sidecar at all. */
  available: boolean;
  /** This owner has logged in to Telegram. */
  connected: boolean;
  /** Logged in, and a Telegram user ID field is chosen: contacts can be listed. */
  configured: boolean;
  account_name: string | null;
  synced_at: string | null;
  /** Contacts still waiting for the browser to link or create their connection. */
  pending_friends: number;
  /** The custom field holding Telegram user ids, with the value shape its type takes. */
  field: { id: string; type: string } | null;
  /** Connection id to the synced Telegram contacts linked to it. */
  profiles: Record<string, TelegramProfile[]>;
}

export const emptyTelegramSummary: TelegramSummary = {
  available: false,
  connected: false,
  configured: false,
  account_name: null,
  synced_at: null,
  pending_friends: 0,
  field: null,
  profiles: {},
};

/** A Telegram contact still waiting for the owner to act on them. */
export interface PendingTelegramContact {
  /** The server's id for the pending contact (not the Telegram id). */
  id: string;
  telegram_user_id: string;
  username: string | null;
  /** First and last name, as one string. */
  name: string | null;
  /** Their phone number, when Telegram shares it with you. */
  phone: string | null;
  picture_url: string | null;
  /** You have each other in your contacts. */
  mutual: boolean;
  premium: boolean;
}

/** What to call a contact: their name, else their username. */
export function telegramContactName(contact: PendingTelegramContact): string {
  return contact.name || (contact.username ? `@${contact.username}` : contact.telegram_user_id);
}

/** Every name a contact goes by: what they are matched against your connections by. */
export function telegramContactNames(contact: PendingTelegramContact): (string | null)[] {
  return [contact.name, contact.username];
}
