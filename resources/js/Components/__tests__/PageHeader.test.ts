import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import PageHeader from '../PageHeader.vue';

const render = (props: object, slots: Record<string, () => unknown> = {}) =>
  renderToString(createSSRApp({ render: () => h(PageHeader as object, props, slots) }));

describe('PageHeader', () => {
  it('is a single h1, with the description only when given', async () => {
    const plain = await render({ title: 'Settings' });
    expect(plain.match(/<h1/g)).toHaveLength(1);
    expect(plain).toContain('Settings');
    expect(plain).not.toContain('text-muted');

    const described = await render({ title: 'Connections', subtitle: 'Your private CRM.' });
    expect(described).toContain('Your private CRM.');
  });

  it('renders the actions slot only when one is provided', async () => {
    expect(await render({ title: 'A' })).not.toContain('d-flex gap-2');
    expect(await render({ title: 'A' }, { actions: () => h('button', 'Go') })).toContain('<button>Go</button>');
  });
});
