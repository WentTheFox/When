import { describe, expect, it } from 'vitest';
import { identityOptions, identityPicture, NO_IDENTITY, resolveIdentity, type LinkedProfiles } from '../connectionIdentity';

const profiles: LinkedProfiles = {
  discord: [
    { discord_user_id: '1', known: false, username: null, global_name: null, nickname: null, picture_url: null },
    { discord_user_id: '2', known: true, username: 'maple', global_name: 'Maple', nickname: null, picture_url: 'd.png' },
  ],
  vrchat: [{ vrchat_user_id: 'usr_1', display_name: 'MapleInVR', picture_url: 'v.png' } as LinkedProfiles['vrchat'] extends (infer P)[] | undefined ? P : never],
  telegram: [{ telegram_user_id: '9', known: true, username: 'maple_t', name: null, picture_url: 't.png' }],
};

describe('connectionIdentity', () => {
  it('lists only named accounts, Discord then VRChat then Telegram', () => {
    expect(identityOptions(profiles).map((o) => o.key)).toEqual(['discord:2', 'vrchat:usr_1', 'telegram:9']);
  });

  it('is automatic (the first account) when nothing is picked', () => {
    expect(identityPicture(null, profiles)).toBe('d.png');
  });

  it('follows the pick, and shows nobody for none or a vanished account', () => {
    expect(identityPicture('telegram:9', profiles)).toBe('t.png');
    expect(resolveIdentity(NO_IDENTITY, profiles)).toBeNull();
    expect(resolveIdentity('discord:404', profiles)).toBeNull();
  });

  it('has no picture without linked accounts', () => {
    expect(identityPicture(null, {})).toBeNull();
  });
});
