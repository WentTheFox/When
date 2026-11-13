import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import PendingDiscordFriendsPanel from '../PendingDiscordFriendsPanel.vue';
import { discordFriendName, discordFriendNames, type PendingDiscordFriend } from '../discord';
import type { MatchCandidate } from '../friendMatching';

const friend = (id: string, over: Partial<PendingDiscordFriend> = {}): PendingDiscordFriend => ({
  id,
  discord_user_id: `10000000000000000${id}`,
  username: `user${id}`,
  global_name: null,
  nickname: null,
  picture_url: null,
  ...over,
});

function render(friends: PendingDiscordFriend[], candidates: MatchCandidate[], extra: object = {}): Promise<string> {
  return renderToString(createSSRApp({
    render: () => h(PendingDiscordFriendsPanel as object, { friends, candidates, ready: true, singleField: false, needsConversion: () => false, busy: false, ...extra }),
  }));
}

describe('discord friend names', () => {
  it('prefers your nickname, then their display name, then their username, then the id', () => {
    expect(discordFriendName(friend('1', { nickname: 'Nick', global_name: 'Glob' }))).toBe('Nick');
    expect(discordFriendName(friend('1', { global_name: 'Glob' }))).toBe('Glob');
    expect(discordFriendName(friend('1'))).toBe('user1');
    expect(discordFriendName(friend('1', { username: null }))).toBe('100000000000000001');
  });

  it('lists every name for matching, most personal first', () => {
    expect(discordFriendNames(friend('1', { nickname: 'Nick', global_name: 'Glob' }))).toEqual(['Nick', 'Glob', 'user1']);
  });
});

describe('PendingDiscordFriendsPanel', () => {
  it('matches on any of the names a friend goes by, nickname first', async () => {
    const html = await render(
      [friend('1', { nickname: 'Nobody Known', global_name: 'Alice' })],
      [{ id: 'c1', name: 'Alice', tokens: [] }, { id: 'c2', name: 'Bob', tokens: [] }],
    );
    expect(html).toContain('Matches');
    expect(html).not.toContain('Possibly the same as');
  });

  it('matches the same name under different decoration and says so', async () => {
    const html = await render([friend('1', { global_name: 'Maple Sparrow星星' })], [{ id: 'c1', name: 'Maple Sparrow', tokens: [] }]);
    expect(html).toContain('Matches');
    expect(html).toContain('ignoring symbols and other-script characters');
  });

  it('shows "possibly the same as" for a renamed friend', async () => {
    const html = await render([friend('2', { global_name: 'AerieBerry' })], [{ id: 'c1', name: 'Someone', tokens: ['AerieHusky'] }]);
    expect(html).toContain('Possibly the same as');
    expect(html).toContain('shares “aerie”');
  });

  it('offers no hint for a friend nobody resembles', async () => {
    const html = await render([friend('3', { username: 'zephyr' })], [{ id: 'c1', name: 'Alice', tokens: [] }]);
    expect(html).not.toContain('Possibly the same as');
    expect(html).not.toContain('Matches');
  });

  it('shows the discord id and the other names beneath the main one', async () => {
    const html = await render([friend('4', { nickname: 'Nick', global_name: 'Glob', username: 'handle' })], []);
    expect(html).toContain('Glob · handle');
    expect(html).toContain('100000000000000004');
  });

  it('flags a friend you have blocked on Discord, and only that one', async () => {
    const html = await render([friend('1', { username: 'blockedone', blocked: true }), friend('2', { username: 'plainone' })], []);
    expect(html.match(/>Blocked</g)).toHaveLength(1);
    expect(html.indexOf('>Blocked<')).toBeGreaterThan(html.indexOf('blockedone'));
    expect(html.indexOf('>Blocked<')).toBeLessThan(html.indexOf('plainone'));
  });

  it('offers a set-all dropdown only when there is more than one friend', async () => {
    expect(await render([friend('1'), friend('2')], [])).toContain('Set all 2 to:');
    expect(await render([friend('1')], [])).not.toContain('Set all');
  });

  it('shows a loading note, not an unlock prompt, while an open vault is still decrypting', async () => {
    const html = await render([friend('5')], [], { ready: false, unlocked: true });
    expect(html).toContain('Decrypting your connections');
    expect(html).not.toContain('Unlock your vault');
  });

  it('waits for the vault before matching or offering any action', async () => {
    const html = await render([friend('5')], [], { ready: false });
    expect(html).toContain('Unlock your vault');
    expect(html).not.toContain('Apply all');
  });

  it('counts the friends and says what Apply all will do', async () => {
    const html = await render([friend('1', { global_name: 'Alice' }), friend('2', { username: 'zephyr' })], [{ id: 'c1', name: 'Alice', tokens: [] }]);
    expect(html).toContain('Discord friends to add');
    expect(html).toContain('Apply all (1 new, 1 to existing)');
  });
});

describe('PendingDiscordFriendsPanel: two accounts of one person', () => {
  const twin = (id: string) => friend(id, { global_name: 'Alice' });
  const candidates: MatchCandidate[] = [{ id: 'c1', name: 'Alice', tokens: [] }, { id: 'c2', name: 'Bob', tokens: [] }];
  const warning = 'turns the Discord ID field into an identifier list';

  it('warns, before applying, that a single field becomes a list when two friends match one connection', async () => {
    const html = await render([twin('1'), twin('2')], candidates, { singleField: true });
    expect(html).toContain(warning);
    expect(html).toContain('also goes to this connection');
  });

  it('does not warn for a list field, different connections, or a single friend', async () => {
    expect(await render([twin('1'), twin('2')], candidates, { singleField: false })).not.toContain(warning);
    expect(await render([twin('1'), friend('2', { global_name: 'Bob' })], candidates, { singleField: true })).not.toContain(warning);
    expect(await render([twin('1')], candidates, { singleField: true })).not.toContain(warning);
  });
});
