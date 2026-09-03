<script setup lang="ts">
/**
 * Generic editor for an App\Support\LocalizedText value — a required
 * `default` field plus any number of owner-added language-code overrides
 * (`hu`, `de`, ...). Not hardcoded to two languages (unlike the old
 * public_page_title_en/_hu column pair this replaced) — "Add language"
 * appends a free-text language-code row, so a third/fourth language is
 * just typing a new row in, no code change needed. Used for both the
 * Public page title and each ActivityRole's own label.
 */
import { BButton, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
  id: string;
  label: string;
  defaultPlaceholder?: string;
  /** Purely a UI hint (native `required` on the default input) — actual enforcement is server-side validation, since this component has no idea whether its caller's field is truly required (ActivityRole's label is; Public page title isn't, since it already falls back to a computed default). */
  required?: boolean;
}>();

const model = defineModel<Record<string, string>>({ default: () => ({}) });

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
  Object.entries(model.value ?? {})
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

function addRow(): void {
  rows.value.push({ code: '', value: '' });
}

function removeRow(index: number): void {
  rows.value.splice(index, 1);
}
</script>

<template>
  <div>
    <BFormGroup :label="label" :label-for="id" class="mb-2">
      <BFormInput :id="id" v-model="defaultValue" type="text" :placeholder="defaultPlaceholder" :required="required" />
    </BFormGroup>
    <div v-for="(row, i) in rows" :key="i" class="d-flex gap-2 mb-2 align-items-start">
      <BFormInput v-model="row.code" type="text" placeholder="Language (e.g. hu)" style="max-width: 8rem" maxlength="10" />
      <BFormInput v-model="row.value" type="text" :placeholder="label" class="flex-grow-1" />
      <BButton variant="outline-danger" size="sm" @click="removeRow(i)">Remove</BButton>
    </div>
    <BButton variant="outline-secondary" size="sm" @click="addRow">+ Add language</BButton>
  </div>
</template>
