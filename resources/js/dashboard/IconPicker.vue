<script setup lang="ts">
/**
 * A single button + dropdown icon picker — replaces the old "show every
 * icon in one big always-expanded grid" pattern (SettingsPublicPageCard.vue's
 * per-slot grids used to filter that grid down to IconKey::categories()'s
 * old per-slot subset; ActivityLocalizationForm.vue's per-role grid showed
 * the full catalog inline). Now that the catalog is large enough that
 * either approach would dominate the page (see IconKey.php's own doc
 * comment on why the old per-slot restriction was dropped entirely — every
 * icon is a valid pick for every slot now), the popup opens a scrollable,
 * grouped, search-filterable list instead: IconKey::group() sorts icons
 * into a handful of browsable categories, and IconKey::keywords() adds
 * extra search terms beyond an icon's own label (so "home" still finds
 * "House", "job" still finds "Briefcase", etc.) — see matches() below.
 */
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { BDropdown, BFormInput } from 'bootstrap-vue-next';
import { computed, nextTick, ref } from 'vue';
import { faIconFor, getIconPalette } from '../free/icon-palette';
import type { IconOption } from '../free/icon-palette';

const props = defineProps<{
  /** Accessible name for the toggle button. */
  label: string;
  /** Tints ONLY the toggle button's own icon glyph (never its label text) plus the active entry's icon in the list — same --app-icon-active-color the old grid used. Omit entirely for a picker with no real color concept (e.g. a per-role icon) rather than passing a generic accent — this prop existing at all is what turns tinting on. */
  activeColor?: string;
}>();

const modelValue = defineModel<string | null>({ default: null });

const search = ref('');
const searchInput = ref<{ focus: () => void } | null>(null);
const dropdownRef = ref<{ hide: () => void } | null>(null);

const allIcons = getIconPalette();

const currentIcon = computed(() => (modelValue.value ? faIconFor(modelValue.value) : undefined));
const currentLabel = computed(() => allIcons.find((i) => i.key === modelValue.value)?.label ?? null);

/** Matches label, the key itself (dashes read as spaces, so "cloud-moon" is searchable as "cloud moon"), and every configured alias — see IconKey::keywords()'s own doc comment for why aliases exist at all. */
function matches(icon: IconOption, query: string): boolean {
  if (!query) return true;
  const q = query.toLowerCase();
  return icon.label.toLowerCase().includes(q)
    || icon.key.replace(/-/g, ' ').includes(q)
    || icon.keywords.some((k) => k.toLowerCase().includes(q));
}

/** Grouped (IconKey::group()) and alphabetized so a search across a 100+ icon catalog stays browsable rather than one long flat list. */
const groups = computed(() => {
  const filtered = allIcons.filter((icon) => matches(icon, search.value));
  const byGroup = new Map<string, IconOption[]>();
  for (const icon of filtered) {
    const list = byGroup.get(icon.group);
    if (list) list.push(icon);
    else byGroup.set(icon.group, [icon]);
  }
  return [...byGroup.entries()].sort(([a], [b]) => a.localeCompare(b));
});

/** Clicking the already-selected icon again clears it back to unset — the only way to get back to "no override" once something's been picked, short of a dedicated clear button. */
function select(icon: IconOption): void {
  modelValue.value = modelValue.value === icon.key ? null : icon.key;
  dropdownRef.value?.hide();
}

function onShown(): void {
  search.value = '';
  nextTick(() => searchInput.value?.focus());
}
</script>

<template>
  <BDropdown
    ref="dropdownRef"
    variant="outline-secondary"
    toggle-class="wtf-icon-picker-toggle"
    menu-class="wtf-icon-picker-menu"
    :aria-label="label"
    @shown="onShown"
  >
    <template #button-content>
      <FontAwesomeIcon
        v-if="currentIcon"
        :icon="currentIcon"
        class="wtf-icon-picker-toggle-icon"
        :style="activeColor ? { '--app-icon-active-color': activeColor } : undefined"
      />
      <span class="small">{{ currentLabel ?? 'Choose icon' }}</span>
    </template>
    <div class="wtf-icon-picker-body" @click.stop>
      <BFormInput
        ref="searchInput"
        v-model="search"
        type="search"
        size="sm"
        placeholder="Search icons…"
        class="mb-2"
      />
      <div class="wtf-icon-picker-scroll">
        <p v-if="groups.length === 0" class="small text-muted mb-0">No icons match "{{ search }}".</p>
        <div v-for="[group, groupIcons] in groups" :key="group" class="mb-2">
          <p class="small text-muted fw-semibold mb-1">{{ group }}</p>
          <div class="wtf-swatch-grid">
            <button
              v-for="icon in groupIcons"
              :key="icon.key"
              type="button"
              class="wtf-icon-swatch-btn"
              :class="{ 'wtf-icon-swatch-btn-active': modelValue === icon.key }"
              :style="{ '--app-icon-active-color': activeColor }"
              :aria-pressed="modelValue === icon.key"
              :title="icon.label"
              @click="select(icon)"
            >
              <FontAwesomeIcon v-if="faIconFor(icon.key)" :icon="faIconFor(icon.key)!" />
              <span class="visually-hidden">{{ icon.label }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </BDropdown>
</template>
