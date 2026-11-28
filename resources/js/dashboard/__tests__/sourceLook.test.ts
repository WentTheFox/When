import { describe, expect, it } from 'vitest';
import { brandIconOptions, brandIconPath } from '../brandIcons';
import { sourceLook } from '../sourceLook';

const categories = [{ id: 'c1', icon: 'brand:discord', color_key: 'jade' }, { id: 'c2', icon: null, color_key: null }];

describe('sourceLook', () => {
  it('inherits both icon and color from the category', () => {
    expect(sourceLook({ category_id: 'c1' }, categories)).toEqual({ icon: 'brand:discord', color_key: 'jade' });
  });

  it('lets a source override the icon and the color independently', () => {
    expect(sourceLook({ category_id: 'c1', icon: 'flag:de' }, categories)).toEqual({ icon: 'flag:de', color_key: 'jade' });
    expect(sourceLook({ category_id: 'c1', color_key: 'blue' }, categories)).toEqual({ icon: 'brand:discord', color_key: 'blue' });
  });

  it('has no icon without one on either level, or without a category', () => {
    expect(sourceLook({ category_id: 'c2' }, categories)).toEqual({ icon: null, color_key: null });
    expect(sourceLook({ category_id: null, color_key: 'rose' }, categories)).toEqual({ icon: null, color_key: 'rose' });
  });
});

describe('brandIcons', () => {
  it('offers Discord, Telegram and VRChat with drawable paths', () => {
    expect(brandIconOptions.map((o) => o.key).sort()).toEqual(['brand:discord', 'brand:telegram', 'brand:vrchat']);
    brandIconOptions.forEach((o) => expect(brandIconPath(o.key)).toMatch(/^M/));
    expect(brandIconPath('brand:nope')).toBeNull();
    expect(brandIconPath('flag:de')).toBeNull();
  });
});
