import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import ConnectionDiscord from '../ConnectionDiscord.vue';
import type { DiscordProfile } from '../discord';

const profile = (over: Partial<DiscordProfile> = {}): DiscordProfile => ({
  discord_user_id: '100000000000000001',
  username: 'maplesparrow',
  global_name: 'Maple Sparrow',
  nickname: null,
  picture_url: 'https://cdn.example.test/avatar.png',
  ...over,
});

function render(profiles: DiscordProfile[], syncedAt: string | null = null): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(ConnectionDiscord as object, { profiles, syncedAt }) }));
}

describe('ConnectionDiscord', () => {
  it('shows the avatar, display name, username and id', async () => {
    const html = await render([profile()]);
    expect(html).toContain('src="https://cdn.example.test/avatar.png"');
    expect(html).toContain('Maple Sparrow');
    expect(html).toContain('@maplesparrow');
    expect(html).toContain('100000000000000001');
  });

  it('shows your nickname for them when you set one', async () => {
    expect(await render([profile({ nickname: 'Mabel' })])).toContain('Your nickname for them: Mabel');
    expect(await render([profile()])).not.toContain('Your nickname');
  });

  it('leads with the username when there is no display name, without repeating it', async () => {
    const html = await render([profile({ global_name: null })]);
    expect(html).toContain('maplesparrow');
    expect(html).not.toContain('@maplesparrow');
  });

  it('falls back to a placeholder without an avatar, and lists every linked account', async () => {
    const html = await render([profile({ picture_url: null }), profile({ discord_user_id: '100000000000000002', global_name: 'Plum Finch', username: 'plumfinch', picture_url: null })]);
    expect(html).not.toContain('<img');
    expect(html).toContain('Plum Finch');
    expect(html).toContain('100000000000000002');
  });

  it('says when it was synced and that it is not end-to-end encrypted, and renders nothing for no accounts', async () => {
    expect(await render([profile()], '2026-11-06T12:00:00Z')).toContain('not end-to-end encrypted');
    expect(await render([profile()])).toContain('Not synced yet');
    expect(await render([])).not.toContain('Synced');
  });
});
