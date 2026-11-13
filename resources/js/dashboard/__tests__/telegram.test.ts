import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import PendingTelegramContactsPanel from '../PendingTelegramContactsPanel.vue';
import { batchNeedsList, type MatchCandidate } from '../friendMatching';
import { type PendingTelegramContact, telegramContactName, telegramContactNames } from '../telegram';

const contact = (over: Partial<PendingTelegramContact> = {}): PendingTelegramContact => ({
  id: 'row-1',
  telegram_user_id: '1000000001',
  username: 'fox',
  name: 'Fox Person',
  phone: null,
  picture_url: null,
  mutual: true,
  premium: false,
  ...over,
});

describe('telegramContactName', () => {
  it('leads with their name', () => {
    expect(telegramContactName(contact())).toBe('Fox Person');
  });

  it('falls back to @username, then the id', () => {
    expect(telegramContactName(contact({ name: null }))).toBe('@fox');
    expect(telegramContactName(contact({ name: null, username: null }))).toBe('1000000001');
  });
});

describe('telegramContactNames', () => {
  it('lists the name then the username, for matching against connections', () => {
    expect(telegramContactNames(contact())).toEqual(['Fox Person', 'fox']);
    expect(telegramContactNames(contact({ name: null }))).toEqual([null, 'fox']);
  });
});

describe('batchNeedsList', () => {
  const d = (choice: string, externalId: string) => ({ choice, externalId });

  it('is true when two different ids are going to the same existing connection', () => {
    expect(batchNeedsList([d('c1', '111'), d('c1', '222')])).toBe(true);
  });

  it('is false for one id per connection, whichever connections they are', () => {
    expect(batchNeedsList([d('c1', '111'), d('c2', '222')])).toBe(false);
    expect(batchNeedsList([d('c1', '111')])).toBe(false);
  });

  it('ignores new connections and dismissals: they never share a field', () => {
    expect(batchNeedsList([d('new', '111'), d('new', '222'), d('skip', '333'), d('skip', '444')])).toBe(false);
  });

  it('does not count the same id twice, whatever its case or padding', () => {
    expect(batchNeedsList([d('c1', 'Fox_1'), d('c1', ' fox_1 ')])).toBe(false);
  });
});

describe('PendingTelegramContactsPanel: two accounts of one person', () => {
  const contact = (id: string, name: string): PendingTelegramContact => ({ ...base(), id, telegram_user_id: `20000000${id}`, name, username: null });
  const base = (): PendingTelegramContact => ({ id: '', telegram_user_id: '', username: null, name: null, phone: null, picture_url: null, mutual: true, premium: false });

  function render(contacts: PendingTelegramContact[], candidates: MatchCandidate[], singleField: boolean): Promise<string> {
    return renderToString(createSSRApp({
      render: () => h(PendingTelegramContactsPanel as object, { friends: contacts, candidates, ready: true, singleField, needsConversion: () => false, busy: false }),
    }));
  }

  const alice: MatchCandidate[] = [{ id: 'c1', name: 'Alice', tokens: [] }, { id: 'c2', name: 'Bob', tokens: [] }];
  const warning = 'turns the Telegram ID field into an identifier list';

  it('warns, before applying, that a single field becomes a list when two contacts match one connection', async () => {
    const html = await render([contact('1', 'Alice'), contact('2', 'Alice')], alice, true);
    expect(html).toContain(warning);
    expect(html).toContain('also goes to this connection');
  });

  it('does not warn when the field is already a list', async () => {
    expect(await render([contact('1', 'Alice'), contact('2', 'Alice')], alice, false)).not.toContain(warning);
  });

  it('does not warn when each contact goes to a different connection, or only one does', async () => {
    expect(await render([contact('1', 'Alice'), contact('2', 'Bob')], alice, true)).not.toContain(warning);
    expect(await render([contact('1', 'Alice')], alice, true)).not.toContain(warning);
  });
});
