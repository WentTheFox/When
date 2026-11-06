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
