<script setup lang="ts">
/**
 * The pattern+preview+label+icon fields shared by ActivityLocalizations.vue's
 * per-role edit block and its "Add a customization" block — those two differ
 * only in their surrounding buttons/errors/save-vs-add behavior, which stays
 * in the parent; this owns just the fields themselves so they can't drift
 * out of sync with each other.
 */
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { BFormGroup, BTooltip } from 'bootstrap-vue-next';
import { ref } from 'vue';
import { faIconFor, getIconPalette } from '../free/icon-palette';
import LocalizedTextInput from './LocalizedTextInput.vue';
import PatternPreview from './PatternPreview.vue';
import RegexPatternInput from './RegexPatternInput.vue';

defineProps<{
  idPrefix: string;
  /** Only the "Add a customization" block requires a default label — an existing role already has one. */
  labelRequired?: boolean;
}>();

const pattern = defineModel<string>('pattern', { required: true });
const label = defineModel<Record<string, string>>('label', { required: true });
const previewText = defineModel<string | null>('previewText', { default: null });
/** Null (the default, and always a valid choice — see the icon row's own description) means "use the regular highlighted icon"; picking one here overrides that just for events this role matches. */
const iconKey = defineModel<string | null>('iconKey', { default: null });

const iconPalette = getIconPalette();

/**
 * One shared tooltip for every icon in this row, not a separate instance
 * per button — same reasoning as SettingsPublicPageCard.vue's own swatch
 * grids (see that file's header comment): with ~48 icons in this one
 * unfiltered list (every IconKey, not narrowed to a slot the way the
 * public-page pickers are — there's no natural "category" for a freeform
 * custom activity), a tooltip per button would be dozens of always-mounted
 * floating-ui instances. Local to this component (not hoisted to the
 * parent) since each role's own icon row is already visually self-
 * contained — nothing is shared across rows.
 */
const tooltipVisible = ref(false);
const activeIconTarget = ref<HTMLElement | null>(null);
const activeIconLabel = ref('');

function showIconTooltip(event: FocusEvent | MouseEvent, iconLabel: string): void {
  activeIconTarget.value = event.currentTarget as HTMLElement;
  activeIconLabel.value = iconLabel;
  tooltipVisible.value = true;
}

function hideIconTooltip(): void {
  tooltipVisible.value = false;
}
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

  <BFormGroup label="Icon" class="mb-3">
    <template #description>
      Shown on a matching event instead of the regular highlighted icon. Leave unset to keep using
      that regular icon — picking one here is entirely optional.
    </template>
    <div class="wtf-swatch-grid">
      <button
        v-for="icon in iconPalette"
        :key="icon.key"
        type="button"
        class="wtf-icon-swatch-btn"
        :class="{ 'wtf-icon-swatch-btn-active': iconKey === icon.key }"
        :aria-pressed="iconKey === icon.key"
        @click="iconKey = iconKey === icon.key ? null : icon.key"
        @mouseenter="showIconTooltip($event, icon.label)"
        @mouseleave="hideIconTooltip"
        @focus="showIconTooltip($event, icon.label)"
        @blur="hideIconTooltip"
      >
        <FontAwesomeIcon v-if="faIconFor(icon.key)" :icon="faIconFor(icon.key)!" />
        <span class="visually-hidden">{{ icon.label }}</span>
      </button>
    </div>
    <BTooltip
      v-if="activeIconTarget"
      v-model="tooltipVisible"
      :target="activeIconTarget"
      no-fade
      noninteractive
      placement="top"
    >
      {{ activeIconLabel }}
    </BTooltip>
  </BFormGroup>
</template>
