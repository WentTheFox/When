import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import IconRulesEditor from '../IconRulesEditor.vue';
import type { ParsedRuleSet } from '../iconRules';

vi.mock('../../composables/useTheme', () => ({ useResolvedTheme: () => ({ value: 'light' }) }));

function render(props: Record<string, unknown>): Promise<string> {
  const component: object = IconRulesEditor;

  return renderToString(createSSRApp({ render: () => h(component, props) }));
}

const regexRules: ParsedRuleSet = {
  kind: 'ok',
  set: {
    version: 1,
    stopAfterFirst: false,
    rules: [{ id: 'r1', icon: 'star', color: null, condition: { op: 'regex', pattern: '^vip-\\d+$', preview: 'vip-12\nnobody' } }],
  },
};

describe('IconRulesEditor', () => {
  it('shows a saved regex rule with its pattern and its persisted preview examples', async () => {
    const html = await render({ type: 'text', choices: [], parsed: regexRules, pending: false });

    expect(html).toContain('vip-');
    expect(html).toContain('vip-12');
    expect(html).toContain('nobody');
  });

  it('says so, and offers no editing, for rules saved by a newer version', async () => {
    const html = await render({ type: 'text', choices: [], parsed: { kind: 'newer', version: 99 }, pending: false });

    expect(html).toContain('newer version');
    expect(html).not.toContain('Add rule');
  });

  it('offers to start over when the saved rules cannot be read', async () => {
    const html = await render({ type: 'text', choices: [], parsed: { kind: 'invalid' }, pending: false });

    expect(html).toContain('could not be read');
    expect(html).toContain('Start over');
  });

  it('starts empty for a field with no rules', async () => {
    const html = await render({ type: 'boolean', choices: [], pending: false });

    expect(html).toContain('No rules yet.');
    expect(html).toContain('Add rule');
  });
});
