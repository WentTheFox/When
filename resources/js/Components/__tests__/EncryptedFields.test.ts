import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import EncryptedInput from '../EncryptedInput.vue';
import EncryptedText from '../EncryptedText.vue';

const RAW = 'q83vEjRWeJq83vEjRWeJq83vEjRWeJ==';

function render(component: object, props: Record<string, unknown>): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(component, props) }));
}

describe('EncryptedInput', () => {
  it('shows the raw ciphertext, disabled, while pending — never the model value', async () => {
    const html = await render(EncryptedInput, { modelValue: 'real value', ciphertext: RAW, pending: true });
    expect(html).toContain(RAW);
    expect(html).not.toContain('real value');
    expect(html).toMatch(/disabled/);
    expect(html).toContain('encrypted-pending');
  });

  it('is an ordinary enabled input showing the model once settled', async () => {
    const html = await render(EncryptedInput, { modelValue: 'real value', ciphertext: RAW, pending: false });
    expect(html).toContain('real value');
    expect(html).not.toContain(RAW);
    expect(html).not.toMatch(/disabled/);
    expect(html).not.toContain('encrypted-pending');
  });

  it('stays disabled when settled if the caller disables it', async () => {
    const html = await render(EncryptedInput, { modelValue: 'x', pending: false, disabled: true });
    expect(html).toMatch(/disabled/);
  });

  it('renders a textarea when multiline, with the same pending behaviour', async () => {
    const html = await render(EncryptedInput, { modelValue: 'real', ciphertext: RAW, pending: true, multiline: true });
    expect(html).toContain('<textarea');
    expect(html).toContain(RAW);
    expect(html).not.toContain('real');
  });

  it('shows nothing (not "undefined") while pending with no ciphertext', async () => {
    const html = await render(EncryptedInput, { modelValue: '', ciphertext: null, pending: true });
    expect(html).not.toContain('undefined');
    expect(html).not.toContain('null');
  });
});

describe('EncryptedText', () => {
  it('shows the raw ciphertext (hidden from screen readers) while the value is undefined', async () => {
    const html = await render(EncryptedText, { ciphertext: RAW });
    expect(html).toContain(RAW);
    expect(html).toContain('aria-hidden="true"');
    expect(html).toContain('Decrypting…');
  });

  it('shows the value once decrypted, including a legitimately empty one', async () => {
    expect(await render(EncryptedText, { value: 'Alice', ciphertext: RAW })).toContain('Alice');
    const empty = await render(EncryptedText, { value: '', ciphertext: RAW });
    expect(empty).not.toContain(RAW);
  });
});
