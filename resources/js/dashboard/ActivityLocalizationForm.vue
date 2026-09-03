<script setup lang="ts">
/**
 * The pattern+preview+label fields shared by ActivityLocalizations.vue's
 * per-role edit block and its "Add a localization" block — those two differ only
 * in their surrounding buttons/errors/save-vs-add behavior, which stays
 * in the parent; this owns just the fields themselves so they can't drift
 * out of sync with each other.
 */
import { BFormGroup } from 'bootstrap-vue-next';
import LocalizedTextInput from './LocalizedTextInput.vue';
import PatternPreview from './PatternPreview.vue';
import RegexPatternInput from './RegexPatternInput.vue';

defineProps<{
  idPrefix: string;
  /** Only the "Add a localization" block requires a default label — an existing role already has one. */
  labelRequired?: boolean;
}>();

const pattern = defineModel<string>('pattern', { required: true });
const label = defineModel<Record<string, string>>('label', { required: true });
const previewText = defineModel<string | null>('previewText', { default: null });
</script>

<template>
  <div class="row mb-3">
    <div class="col-md-6">
      <BFormGroup label="Pattern" :label-for="`${idPrefix}_pattern`" class="mb-2">
        <RegexPatternInput :id="`${idPrefix}_pattern`" v-model="pattern" />
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
        :required="labelRequired"
      />
    </div>
  </div>
</template>
