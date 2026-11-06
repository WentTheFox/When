import { beforeAll, describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { setColorPalette } from '../../free/color-palette';
import SearchableSelect from '../SearchableSelect.vue';
import SwatchDot from '../SwatchDot.vue';

beforeAll(() => {
  setColorPalette([{ key: 'red', label: 'Red', light: '#ff0000', dark: '#cc0000' }], {
    accent: 'red', secondary: 'red', free: 'red', busy: 'red', work: 'red', school: 'red', public: 'red', sleep: 'red', highlighted: 'red',
  });
});

function render(component: object, props: Record<string, unknown>): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(component, props) }));
}

const dots = (html: string) => (html.match(/wtf-swatch-dot/g) ?? []).length;

describe('SwatchDot', () => {
  it('draws a dot for a palette key', async () => {
    expect(dots(await render(SwatchDot, { colorKey: 'red' }))).toBe(1);
  });

  it('draws nothing for an unset or unknown key', async () => {
    expect(dots(await render(SwatchDot, { colorKey: null }))).toBe(0);
    expect(dots(await render(SwatchDot, { colorKey: 'nope' }))).toBe(0);
  });
});

describe('SearchableSelect swatches', () => {
  const options = [
    { value: 'a', text: 'Alpha', swatch: 'red' },
    { value: 'b', text: 'Beta', swatch: null },
  ];

  it('shows the dot of each chosen option on the button', async () => {
    const html = await render(SearchableSelect, { options, multiple: true, modelValue: ['a', 'b'] });

    expect(dots(html)).toBe(1);
    expect(html).toContain('Alpha');
    expect(html).toContain('Beta');
  });

  it('shows the dot of the chosen option in single mode', async () => {
    expect(dots(await render(SearchableSelect, { options, modelValue: 'a' }))).toBe(1);
    expect(dots(await render(SearchableSelect, { options, modelValue: 'b' }))).toBe(0);
  });
});
