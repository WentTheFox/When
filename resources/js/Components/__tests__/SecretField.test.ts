import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import SecretField from '../SecretField.vue';

const base = { id: 'key', label: 'API key', noun: 'API key', configured: false, modelValue: '' };
const render = (props: object) => renderToString(createSSRApp({ render: () => h(SecretField as object, { ...base, ...props }) }));

describe('SecretField', () => {
  it('masks the value and offers to show it, naming what it is', async () => {
    const html = await render({ modelValue: 'sekret' });
    expect(html).toContain('type="password"');
    expect(html).toContain('aria-label="Show API key"');
    expect(html).toContain('for="key"');
  });

  it('badges whether a value is saved', async () => {
    expect(await render({ configured: true })).toContain('Configured');
    const unset = await render({ configured: false });
    expect(unset).toContain('Not set');
    expect(unset).not.toContain('Configured');
  });

  it('shows the description and the error only when given', async () => {
    const plain = await render({});
    expect(plain).not.toContain('text-danger');

    const html = await render({ description: 'Paste it here.', error: 'Too short.' });
    expect(html).toContain('Paste it here.');
    expect(html).toContain('Too short.');
  });
});
