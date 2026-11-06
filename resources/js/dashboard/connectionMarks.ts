/**
 * The VRChat and Discord marks in the connections list.
 *
 *   none   - no ID of that kind is set on the connection: no mark.
 *   muted  - an ID is set, but nothing confirms it: VRCX has no profile for the VRChat
 *            account (not synced yet, or it hasn't seen that user), or the Discord ID
 *            is only the value typed into the custom field.
 *   active - the link is live: VRCX knows the VRChat account (a name or shared visits),
 *            or VRCX itself reported the Discord ID.
 */
import type { VrchatProfile } from './vrchat';

export type MarkState = 'none' | 'muted' | 'active';

export function vrchatMark(hasVrchatId: boolean, profiles: readonly VrchatProfile[] | undefined): MarkState {
  if (!hasVrchatId) return 'none';

  return (profiles ?? []).some((p) => p.display_name !== null || p.stays > 0) ? 'active' : 'muted';
}

export function discordMark(fieldHasValue: boolean, profiles: readonly VrchatProfile[] | undefined): MarkState {
  if ((profiles ?? []).some((p) => !!p.discord_id)) return 'active';

  return fieldHasValue ? 'muted' : 'none';
}

/**
 * The share-link mark in the connections list.
 *
 *   unhighlighted - a highlight-tokens field is chosen in Settings, and this connection has no
 *                   tokens in it, so its link highlights nothing: shown red, as a nudge.
 *   visited       - the link has been opened.
 *   idle          - the link has never been opened.
 *
 * Without a highlight field chosen no link highlights anything, whatever the connection holds, so
 * that is a Settings matter and not flagged per connection.
 */
export type ShareLinkMark = 'unhighlighted' | 'visited' | 'idle';

export function shareLinkMark(visited: boolean, highlightFieldSet: boolean, tokens: readonly string[] | null | undefined): ShareLinkMark {
  const hasTokens = (tokens ?? []).some((token) => token.trim() !== '');

  if (highlightFieldSet && !hasTokens) return 'unhighlighted';

  return visited ? 'visited' : 'idle';
}
