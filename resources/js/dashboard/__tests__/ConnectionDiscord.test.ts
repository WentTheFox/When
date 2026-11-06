import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import ConnectionDiscord from '../ConnectionDiscord.vue';
import type { DiscordProfile } from '../discord';

const profile = (over: Partial<DiscordProfile> = {}): DiscordProfile => ({
  discord_user_id: '100000000000000001',
  known: true,
  username: 'maplesparrow',
  global_name: 'Maple Sparrow',
  nickname: null,
  picture_url: 'https://cdn.example.test/avatar.png',
  ...over,
});

/** An ID nobody has looked up yet: the server sends only the id. */
const placeholder = (id = '100000000000000009'): DiscordProfile => ({
  discord_user_id: id, known: false, username: null, global_name: null, nickname: null, picture_url: null,
});

function render(profiles: DiscordProfile[], syncedAt: string | null = null, canLookUp = true): Promise<string> {
  return renderToString(createSSRApp({
    render: () => h(ConnectionDiscord as object, { connectionId: 'c1', profiles, syncedAt, canLookUp }),
  }));
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

  it('says it is not end-to-end encrypted, and renders nothing for no accounts', async () => {
    expect(await render([profile()], '2026-11-06T12:00:00Z')).toContain('not end-to-end encrypted');
    expect(await render([])).not.toContain('encrypted');
  });
});

describe('ConnectionDiscord, an ID nobody has looked up', () => {
  it('shows a placeholder with the id instead of a name or an avatar', async () => {
    const html = await render([placeholder()]);
    expect(html).toContain('Not looked up yet');
    expect(html).toContain('100000000000000009');
    expect(html).not.toContain('<img');
    expect(html).not.toContain('@');
  });

  it('offers a Sync button that asks Discord about the id', async () => {
    const html = await render([placeholder()]);
    expect(html).toContain('>Sync<');
    expect(html).toContain('Sync asks Discord about this ID');
  });

  it('says what to do instead when the server cannot look ids up', async () => {
    const html = await render([placeholder()], null, false);
    expect(html).not.toContain('>Sync<');
    expect(html).toContain('Connect Discord and sync your friends');
  });

  it('keeps a looked-up person and a placeholder side by side, offering to sync the missing one', async () => {
    const html = await render([profile(), placeholder()]);
    expect(html).toContain('Maple Sparrow');
    expect(html).toContain('Not looked up yet');
    expect(html).toContain('>Sync<');
  });

  it('offers to sync again once everyone is known', async () => {
    const html = await render([profile()]);
    expect(html).toContain('Sync again');
    expect(html).not.toContain('Not looked up yet');
  });

  it('never uses the blue outlined button style', async () => {
    expect(await render([placeholder()])).not.toContain('btn-outline-primary');
  });
});
