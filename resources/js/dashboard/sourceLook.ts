/**
 * A source's logo is set on its category and inherited by the source, which can override the icon and the
 * color independently (null on the source = use the category's). `icon` is the decrypted icon key
 * (palette icon, `flag:xx` or `brand:xx`); `color_key` a palette key.
 */
export interface LookSource { category_id: string | null; icon?: string | null; color_key?: string | null }
export interface LookCategory { id: string; icon?: string | null; color_key?: string | null }
export interface SourceLook { icon: string | null; color_key: string | null }

export function sourceLook(source: LookSource, categories: LookCategory[]): SourceLook {
  const category = source.category_id ? categories.find((c) => c.id === source.category_id) : undefined;

  return {
    icon: source.icon ?? category?.icon ?? null,
    color_key: source.color_key ?? category?.color_key ?? null,
  };
}
