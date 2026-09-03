<script setup lang="ts">
/**
 * Generic editor for an App\Support\LocalizedText value — a required
 * `default` field (always English) plus any number of owner-added
 * language overrides (`hu`, `de`, ...), picked from a dropdown rather
 * than typed freely, so "all languages added" is a real, detectable
 * state. Not hardcoded to two languages (unlike the old
 * public_page_title_en/_hu column pair this replaced) — the language
 * list itself comes from the shared `locales` page prop (see
 * App\Support\Locales), so adding a language is a one-line PHP change,
 * no frontend change needed. Used for both the Public page title and
 * each ActivityLocalization's own label.
 */
import { BButton, BFormGroup, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faPlus, faTrash } from '@fortawesome/free-solid-svg-icons';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { SharedPageProps } from '../sharedPageProps';

const props = defineProps<{
  id: string;
  label: string;
  /** Purely a UI hint (native `required` on the default input) — actual enforcement is server-side validation, since this component has no idea whether its caller's field is truly required (ActivityLocalization's label is; Public page title isn't, since it already falls back to a computed default). */
  required?: boolean;
}>();

const model = defineModel<Record<string, string>>({ default: () => ({en:''}) });

const page = usePage<SharedPageProps>();

// The full set of language overrides an owner can add
const languageOptions = computed(() => page.props.locales);

interface Row {
  code: string;
  value: string;
}

// Owned locally from the initial value onward, same "seed once, sync out
// via emit" pattern as PatternPreview.vue's own examples textarea —
// editing a row's language code shouldn't fight with its own emitted
// update round-tripping back in through v-model.
const defaultValue = ref(model.value?.default ?? '');
const rows = ref<Row[]>(
  Object.entries(model.value ?? {en:''})
    .filter(([key]) => key !== 'default')
    .map(([code, value]) => ({ code, value })),
);

function emitUpdate(): void {
  const next: Record<string, string> = {};
  if (defaultValue.value) next.default = defaultValue.value;
  for (const row of rows.value) {
    if (row.code && row.value) next[row.code] = row.value;
  }
  model.value = next;
}

watch(defaultValue, emitUpdate);
watch(rows, emitUpdate, { deep: true });

// Every code some other row already has — a row's own dropdown keeps
// every language listed (disabled, not removed, for the ones already
// taken elsewhere) so the list's order never shifts as rows are added,
// while still keeping a row's own current selection choosable.
function codesUsedByOtherRows(index: number): Set<string> {
  return new Set(rows.value.filter((_, i) => i !== index).map((r) => r.code));
}

const nextAvailableCode = computed<string | null>(() => {
  const used = new Set(rows.value.map((r) => r.code));
  return languageOptions.value.find((l) => !used.has(l.code))?.code ?? null;
});

function addRow(): void {
  if (nextAvailableCode.value === null) return;
  rows.value.push({ code: nextAvailableCode.value, value: '' });
}

function removeRow(index: number): void {
  rows.value.splice(index, 1);
}
</script>

<template>
  <div>
    <BFormGroup :label="label" :label-for="id" class="mb-2">
      <div v-for="(row, i) in rows" :key="i" class="d-flex gap-2 mb-2 align-items-center">
        <BFormSelect v-model="row.code" style="max-width: 10rem" aria-label="Language">
          <option
            v-for="locale in languageOptions"
            :key="locale.code"
            :value="locale.code"
            :disabled="locale.code !== row.code && codesUsedByOtherRows(i).has(locale.code)"
          >
            {{ locale.native }} ({{ locale.code }})
          </option>
        </BFormSelect>
        <BFormInput v-model="row.value" type="text" :placeholder="label" class="flex-grow-1" />
        <BButton variant="outline-danger" size="sm" class="flex-shrink-0" aria-label="Remove language" @click="removeRow(i)">
          <FontAwesomeIcon :icon="faTrash" />
        </BButton>
      </div>
    </BFormGroup>
    <BButton v-if="nextAvailableCode !== null" variant="outline-secondary" size="sm" @click="addRow"><FontAwesomeIcon :icon="faPlus" class="me-1"/>Add language</BButton>
  </div>
</template>
