/**
 * The linked account that stands for a connection (its picture in every list, its name and picture
 * on the free page). The same choice App\Services\ConnectionIdentity makes on the server: keep the two in step.
 * Only accounts with a name count, as an ID nobody has looked up has nothing to show.
 */
import type { DiscordProfile } from './discord';
import type { TelegramProfile } from './telegram';
import type { VrchatProfile } from './vrchat';

/** What the owner picked: 'discord:<id>' / 'vrchat:<id>' / 'telegram:<id>', 'none', or null for automatic. */
export const NO_IDENTITY = 'none';

export interface IdentityOption {
  key: string;
  label: string;
  provider: 'discord' | 'vrchat' | 'telegram';
  name: string;
  picture_url: string | null;
}

export interface LinkedProfiles {
  discord?: DiscordProfile[];
  vrchat?: VrchatProfile[];
  telegram?: TelegramProfile[];
}

const PROVIDER_LABEL = { discord: 'Discord', vrchat: 'VRChat', telegram: 'Telegram' } as const;

/** Every account that can stand for the connection, in the order "automatic" tries them. */
export function identityOptions(profiles: LinkedProfiles): IdentityOption[] {
  const option = (provider: IdentityOption['provider'], id: string, name: string, picture: string | null): IdentityOption => ({
    key: `${provider}:${id}`,
    label: `${PROVIDER_LABEL[provider]}: ${name}`,
    provider,
    name,
    picture_url: picture,
  });

  return [
    ...(profiles.discord ?? [])
      .filter((p) => p.known && (p.global_name || p.username))
      .map((p) => option('discord', p.discord_user_id, (p.global_name || p.username) as string, p.picture_url)),
    ...(profiles.vrchat ?? [])
      .filter((p) => p.display_name)
      .map((p) => option('vrchat', p.vrchat_user_id, p.display_name as string, p.picture_url)),
    ...(profiles.telegram ?? [])
      .filter((p) => p.known && (p.name || p.username))
      .map((p) => option('telegram', p.telegram_user_id, (p.name || p.username) as string, p.picture_url)),
  ];
}

/** The account shown for the connection, or null for nobody (also when the picked account has gone). */
export function resolveIdentity(preferred: string | null | undefined, profiles: LinkedProfiles): IdentityOption | null {
  if (preferred === NO_IDENTITY) return null;

  const options = identityOptions(profiles);

  return preferred ? (options.find((o) => o.key === preferred) ?? null) : (options[0] ?? null);
}

/** The picture URL for the connection's list rows, or null when there is none to show. */
export function identityPicture(preferred: string | null | undefined, profiles: LinkedProfiles): string | null {
  return resolveIdentity(preferred, profiles)?.picture_url ?? null;
}
