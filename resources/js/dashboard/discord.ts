/**
 * Shapes for the Discord friends import (§0.2 tier: server-readable; see
 * DiscordSyncService::summary()).
 */
/** A Discord friend linked to a connection, as the sync saw them (§0.2 tier, not E2EE). */
export interface DiscordProfile {
  discord_user_id: string;
  username: string | null;
  /** Their display name. */
  global_name: string | null;
  /** Your own private nickname for them in Discord. */
  nickname: string | null;
  picture_url: string | null;
}

export interface DiscordSummary {
  /** The server has a Discord app configured at all. */
  available: boolean;
  /** This owner has granted access (identify + relationships.read). */
  connected: boolean;
  /** Connected, and a Discord user ID field is chosen: friends can be listed. */
  configured: boolean;
  account_name: string | null;
  synced_at: string | null;
  /** Friends still waiting for the browser to link or create their connection. */
  pending_friends: number;
  /** The custom field holding Discord user ids, with the value shape its type takes. */
  field: { id: string; type: string } | null;
  /** Connection id to the synced Discord friends linked to it. */
  profiles: Record<string, DiscordProfile[]>;
}

export const emptyDiscordSummary: DiscordSummary = {
  available: false,
  connected: false,
  configured: false,
  account_name: null,
  synced_at: null,
  pending_friends: 0,
  field: null,
  profiles: {},
};

/** A Discord friend still waiting for the owner to act on them. */
export interface PendingDiscordFriend {
  /** The server's id for the pending friend (not the Discord id). */
  id: string;
  discord_user_id: string;
  username: string | null;
  global_name: string | null;
  /** Your own private nickname for them in Discord. */
  nickname: string | null;
  picture_url?: string | null;
}

/** What to call a friend: your nickname for them, else their display name, else their username. */
export function discordFriendName(friend: PendingDiscordFriend): string {
  return friend.nickname || friend.global_name || friend.username || friend.discord_user_id;
}

/** Every name a friend goes by, most personal first: what they are matched against your connections by. */
export function discordFriendNames(friend: PendingDiscordFriend): (string | null)[] {
  return [friend.nickname, friend.global_name, friend.username];
}
