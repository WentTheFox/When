<script setup lang="ts">
/**
 * The pattern+preview+label+icon fields shared by ActivityLocalizations.vue's
 * per-role edit block and its "Add a customization" block — those two differ
 * only in their surrounding buttons/errors/save-vs-add behavior, which stays
 * in the parent; this owns just the fields themselves so they can't drift
 * out of sync with each other.
 */
import { BFormGroup } from 'bootstrap-vue-next';
import ColorPicker from './ColorPicker.vue';
import IconPicker from './IconPicker.vue';
import LocalizedTextInput from './LocalizedTextInput.vue';
import PatternPreview from './PatternPreview.vue';
import RegexPatternInput from './RegexPatternInput.vue';

defineProps<{
  idPrefix: string;
}>();

const pattern = defineModel<string>('pattern', { required: true });
const label = defineModel<Record<string, string>>('label', { required: true });
const previewText = defineModel<string | null>('previewText', { default: null });
/** Null (the default, and always a valid choice — see the icon row's own description) means "use the regular highlighted icon"; picking one here overrides that just for events this role matches. */
const iconKey = defineModel<string | null>('iconKey', { default: null });
/** Same idea as iconKey above, but for the event's color instead of its icon. */
const colorKey = defineModel<string | null>('colorKey', { default: null });
</script>

<template>
  <div class="row mb-3">
    <div class="col-md-6">
      <BFormGroup label="Pattern" :label-for="`${idPrefix}_pattern`" class="mb-2">
        <RegexPatternInput
          :id="`${idPrefix}_pattern`"
          field-label="Pattern"
          v-model="pattern"
          v-model:preview-model-value="previewText"
          :preview-config="{ mode: 'tokens' }"
        />
      </BFormGroup>
      <p class="small text-muted mb-1">Live preview</p>
      <PatternPreview
        v-model="previewText"
        :pattern="pattern"
        mode="tokens"
      />
    </div>
    <div class="col-md-6">
      <LocalizedTextInput
        v-model="label"
        :id="`${idPrefix}_label`"
        label="Label shown to the viewer"
        default-placeholder="Visiting"
      />
    </div>
  </div>

  <BFormGroup label="Icon" class="mb-3">
    <template #description>
      Shown on a matching event instead of the regular highlighted icon. Leave unset to keep using
      that regular icon. Picking one here is entirely optional — click the selected icon again to
      unset it.
    </template>
    <IconPicker v-model="iconKey" label="Role icon" />
  </BFormGroup>

  <BFormGroup label="Color" class="mb-3">
    <template #description>
      Shown on a matching event instead of the regular highlighted color. Leave unset to keep
      using that regular color. Picking one here is entirely optional — click the selected color
      again to unset it.
    </template>
    <ColorPicker v-model="colorKey" label="Role color" />
  </BFormGroup>
</template>
