import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it } from 'vitest';
import RadioChoices from '../RadioChoices.vue';

const choices = [
  { value: '', text: '(unset)' },
  { value: 'Gold', text: 'Gold' },
  { value: 'Silver', text: 'Silver' },
];

function radios(html: string): { value: string | undefined; checked: boolean; disabled: boolean }[] {
  return [...html.matchAll(/<input\b[^>]*type="radio"[^>]*>/g)].map(([tag]) => ({
    value: /value="([^"]*)"/.exec(tag)?.[1],
    checked: /\bchecked\b/.test(tag),
    disabled: /\bdisabled\b/.test(tag),
  }));
}

function render(props: Record<string, unknown>): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(RadioChoices, { choices, name: 'field-x', ...props }) }));
}

describe('RadioChoices', () => {
  it('keeps an empty-string choice empty (BFormRadio would turn it into `true`)', async () => {
    expect(radios(await render({})).map((r) => r.value)).toEqual(['', 'Gold', 'Silver']);
  });

  it('selects "(unset)" when the field has no value yet', async () => {
    const all = radios(await render({ modelValue: undefined }));
    expect(all.map((r) => r.checked)).toEqual([true, false, false]);
  });

  it('selects "(unset)" for an explicitly empty value', async () => {
    expect(radios(await render({ modelValue: '' })).map((r) => r.checked)).toEqual([true, false, false]);
  });

  it('selects the choice matching the value, and only that one', async () => {
    expect(radios(await render({ modelValue: 'Silver' })).map((r) => r.checked)).toEqual([false, false, true]);
  });

  it('selects nothing for a value that is not among the choices, rather than guessing', async () => {
    expect(radios(await render({ modelValue: 'Platinum' })).some((r) => r.checked)).toBe(false);
  });

  it('works for Yes/No, where the value "false" must not read as unset', async () => {
    const yesNo = [{ value: '', text: '(unset)' }, { value: 'true', text: 'Yes' }, { value: 'false', text: 'No' }];
    const html = await render({ choices: yesNo, modelValue: 'false' });
    expect(radios(html).map((r) => r.checked)).toEqual([false, false, true]);
  });

  it('groups the radios under one name, with matching label ids', async () => {
    const html = await render({});
    expect(new Set([...html.matchAll(/type="radio"[^>]*\bname="([^"]+)"/g)].map((m) => m[1]))).toEqual(new Set(['field-x']));
    for (const index of [0, 1, 2]) {
      expect(html).toContain(`id="field-x-${index}"`);
      expect(html).toContain(`for="field-x-${index}"`);
    }
  });

  it('can be disabled', async () => {
    expect(radios(await render({ disabled: true })).every((r) => r.disabled)).toBe(true);
    expect(radios(await render({})).some((r) => r.disabled)).toBe(false);
  });
});
