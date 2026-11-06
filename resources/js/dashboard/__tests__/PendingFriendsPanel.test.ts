import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import PendingFriendsPanel from '../PendingFriendsPanel.vue';
import type { MatchCandidate } from '../friendMatching';
import type { PendingFriend } from '../vrchat';

const friend = (id: string, name: string): PendingFriend => ({ id, vrchat_user_id: `usr_${id}`, display_name: name, picture_url: null });

function render(friends: PendingFriend[], candidates: MatchCandidate[], extra: object = {}): Promise<string> {
  return renderToString(createSSRApp({
    render: () => h(PendingFriendsPanel as object, { friends, candidates, ready: true, singleField: false, needsConversion: () => false, busy: false, ...extra }),
  }));
}

describe('PendingFriendsPanel', () => {
  it('suggests, and preselects, the one connection that matches exactly', async () => {
    const html = await render([friend('1', 'Alice')], [{ id: 'c1', name: 'Alice', tokens: [] }, { id: 'c2', name: 'Bob', tokens: [] }]);
    expect(html).toContain('Matches');
    expect(html).not.toContain('Possibly the same as');
  });

  it('shows "possibly the same as" for a renamed friend', async () => {
    const html = await render([friend('2', 'AerieBerry')], [{ id: 'c1', name: 'Someone', tokens: ['AerieHusky'] }]);
    expect(html).toContain('Possibly the same as');
    expect(html).toContain('Someone');
    expect(html).toContain('shares “aerie”');
  });

  it('offers no hint for a friend nobody resembles', async () => {
    const html = await render([friend('3', 'Zephyr')], [{ id: 'c1', name: 'Alice', tokens: [] }]);
    expect(html).not.toContain('Possibly the same as');
    expect(html).not.toContain('Matches');
  });

  it('waits for the vault before matching or offering any action', async () => {
    const html = await render([friend('4', 'Alice')], [], { ready: false });
    expect(html).toContain('Unlock your vault');
    expect(html).not.toContain('Apply all');
  });

  it('counts the friends and says what Apply all will do', async () => {
    const html = await render([friend('1', 'Alice'), friend('2', 'Zephyr')], [{ id: 'c1', name: 'Alice', tokens: [] }]);
    expect(html).toContain('VRChat friends to add');
    expect(html).toContain('Apply all (1 new, 1 to existing)');
  });
});
