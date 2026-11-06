/**
 * Shapes and formatting for the VRChat (VRCX) data the server caches per
 * connection (§0.2 tier — server-readable; see VrcxSyncService::summary()).
 */
export interface VrchatProfile {
  vrchat_user_id: string;
  display_name: string | null;
  picture_url: string | null;
  /** Your private VRChat note, present only while it waits to be carried over to the connection's notes. */
  note: string | null;
  /** The Discord user ID VRCX last reported for this account (when VRChat has ever shown one). */
  discord_id: string | null;
  /** How many shared-instance intervals were recorded. */
  stays: number;
  total_ms: number;
  /** Time spent with nobody else in the instance. */
  one_on_one_ms: number;
  first_at: string | null;
  last_at: string | null;
}

export interface VrchatSummary {
  /** A server, an API key and a VRChat user ID field are all set. */
  configured: boolean;
  synced_at: string | null;
  /** New VRChat friends get a connection made for them (opt-in in Settings). */
  auto_create_friends: boolean;
  /** Friends still waiting for the browser to create their connection. */
  pending_friends: number;
  /** The connection source new-friend connections are tied to, if one is chosen. */
  friends_source_id: string | null;
  /** The custom field chosen for Discord user IDs, if any. */
  discord_field_id: string | null;
  /** The identifier list holding each connection's highlight tokens, which friends are also matched against. */
  highlight_field_id: string | null;
  /** The custom field holding VRChat user ids, with the value shape its type takes. */
  field: { id: string; type: string } | null;
  /** Connection id to its linked VRChat accounts. */
  profiles: Record<string, VrchatProfile[]>;
}

export interface VrchatStay {
  vrchat_user_id: string;
  start: string;
  end: string;
  duration_ms: number;
  other_player_count: number;
}

/** "3 h 5 min", "42 min", "under a minute", "0 min" for nothing. Whole minutes; hours only once there are some. */
export function formatDuration(ms: number): string {
  const minutes = Math.floor(Math.max(0, ms) / 60000);
  if (ms > 0 && minutes === 0) return 'under a minute';
  if (minutes < 60) return `${minutes} min`;

  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

/** A VRChat friend still waiting for the owner to act on them. */
export interface PendingFriend {
  /** The server's id for the pending friend (not the VRChat id). */
  id: string;
  vrchat_user_id: string;
  display_name: string | null;
  picture_url?: string | null;
}

/** 'new' creates a connection, 'skip' dismisses the friend for good, anything else is the id of the connection to add them to. */
export type FriendChoice = 'new' | 'skip' | string;

export interface FriendDecision {
  friendId: string;
  choice: FriendChoice;
}
