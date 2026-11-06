<script setup lang="ts">
/**
 * The "Migrate field" section of the attributes panel: every in-place type change available from the open field's
 * current type (the server's FieldMigrations), each with what it does to encryption and to the values, plus the
 * non-destructive "copy into an identifier" tool for text fields. Running one asks for confirmation here; the page
 * does the work (Connections.vue migrateDefinition).
 */
import { BBadge, BButton, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref } from 'vue';
import AppIcon from '../Components/AppIcon.vue';
import { ATTRIBUTE_TYPE_ICONS } from './attributeTypeIcons';
import { requestConfirm } from './confirmModal';
import { featuresBrokenBy, hasWayBack, type MigrationPath, pathsFrom } from './fieldMigrations';

const TIER_BADGES = {
  e2ee: { text: 'Stays end-to-end encrypted', variant: 'success' },
  to_plain: { text: 'Leaves end-to-end encryption', variant: 'warning' },
  to_e2ee: { text: 'Becomes end-to-end encrypted', variant: 'success' },
  plain: { text: 'Not end-to-end encrypted either way', variant: 'secondary' },
} as const;

/** What a path does beyond the badges; paths without an entry need no explanation. */
const NOTES: Record<string, string> = {
  'textarea>text': 'Line breaks become spaces.',
  'text>list': 'Each value is read as one entry per line; blank lines and repeats are dropped.',
  'textarea>list': 'Each value is read as one entry per line; blank lines and repeats are dropped.',
  'radio>list': 'The chosen option becomes the one entry. The field\'s choices are removed.',
  'radio>textarea': 'The chosen option becomes the text. The field\'s choices are removed.',
  'radio>text': 'The chosen option becomes the text. The field\'s choices are removed.',
  'list>textarea': 'Entries become lines.',
  'text>identifier': 'Values are sent to the server in plaintext (encrypted at rest with the app key) so they can link a connection to an external service.',
  'list>identifier_list': 'Entries are sent to the server in plaintext (encrypted at rest with the app key) so they can be used by site features.',
  'identifier>identifier_list': 'Each value becomes a one-entry list; empty values are dropped.',
  'identifier_list>list': 'Entries are encrypted in your browser; the server no longer holds them in plaintext.',
  'boolean>radio': 'Becomes a choice field with “Yes” and “No”; the server no longer holds the answers in plaintext.',
};

const STRATEGY_LABELS: Record<string, string> = { first: 'Keep the first entry', join: 'Join entries with commas' };

interface DefinitionLike { id: string; type: string; label?: string }

const props = defineProps<{
  definition: DefinitionLike;
  paths: MigrationPath[];
  typeLabels: Record<string, string>;
  /** How many connections hold a value for the field. */
  usage: number;
  /** Labels of the site features that use the field. */
  usedBy: string[];
  /** Which field types each feature (by label) can use. */
  featureTypes: Record<string, string[]>;
  /** The identifier fields a text field can be copied into. */
  identifierTargets: { id: string; label: string }[];
  copyStatus: string | null;
  busy: boolean;
  disabled: boolean;
}>();

const emit = defineEmits<{
  migrate: [id: string, to: string, options: { strategy?: string; releaseBindings?: boolean }];
  copyToIdentifier: [string, string];
  createIdentifierAndCopy: [string, string];
}>();

const available = computed(() => pathsFrom(props.paths, props.definition.type));
const strategy = ref<Record<string, string>>({});
const copyTargetId = ref('');
const newIdentifierLabel = ref('');

function targetLabel(path: MigrationPath): string {
  return props.typeLabels[path.to] ?? path.to;
}

async function run(path: MigrationPath): Promise<void> {
  const label = props.definition.label;
  if (label === undefined) return;

  const chosen = path.strategies ? (strategy.value[path.to] ?? path.strategies[0]) : undefined;
  const broken = featuresBrokenBy(props.usedBy, props.featureTypes, path.to);

  const parts = [
    props.usage > 0 ? `${props.usage} connection${props.usage === 1 ? ' holds' : 's hold'} a value for it; each is converted.` : 'No connection holds a value for it yet.',
    NOTES[`${path.from}>${path.to}`],
    chosen ? `${STRATEGY_LABELS[chosen]} where a value has several.` : undefined,
    path.lossy ? 'Some values can come out different from how they are now.' : undefined,
    broken.length ? `A ${targetLabel(path)} field can't be used by ${broken.join(', ')}, so the field is unbound from ${broken.length === 1 ? 'it' : 'them'} (choose another field in Settings).` : undefined,
    'The field keeps its name and position; icon rules that no longer apply are dropped.',
    hasWayBack(props.paths, path) ? 'You can migrate it back later.' : 'This cannot be undone.',
  ];

  const confirmed = await requestConfirm({
    title: `Migrate “${label}” to ${targetLabel(path)}?`,
    message: parts.filter(Boolean).join(' '),
    confirmText: 'Migrate field',
    variant: path.tier === 'to_plain' ? 'danger' : 'primary',
  });
  if (!confirmed) return;

  emit('migrate', props.definition.id, path.to, { strategy: chosen, releaseBindings: broken.length > 0 });
}
</script>

<template>
  <section class="mt-4">
    <h3 class="h6">Migrate field</h3>
    <p v-if="!available.length && definition.type !== 'text'" class="small text-muted">No migrations are available for {{ typeLabels[definition.type] ?? definition.type }} fields.</p>
    <ul v-else class="list-group list-group-flush mb-2">
      <li v-for="path in available" :key="path.to" class="list-group-item px-0">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="fw-semibold">
            <AppIcon v-if="ATTRIBUTE_TYPE_ICONS[path.to]" :icon="ATTRIBUTE_TYPE_ICONS[path.to]!" class="me-1" />{{ targetLabel(path) }}
          </span>
          <BBadge :variant="TIER_BADGES[path.tier].variant">{{ TIER_BADGES[path.tier].text }}</BBadge>
          <BBadge :variant="path.lossy ? 'warning' : 'secondary'">{{ path.lossy ? 'May change values' : 'Lossless' }}</BBadge>
          <BBadge variant="secondary">{{ hasWayBack(paths, path) ? 'Can be undone' : 'One way' }}</BBadge>
          <span class="flex-grow-1" />
          <BFormSelect
            v-if="path.strategies"
            :model-value="strategy[path.to] ?? path.strategies[0]"
            size="sm"
            class="w-auto"
            :aria-label="`How to migrate to ${targetLabel(path)}`"
            @update:model-value="strategy[path.to] = String($event)"
          >
            <option v-for="option in path.strategies" :key="option" :value="option">{{ STRATEGY_LABELS[option] ?? option }}</option>
          </BFormSelect>
          <BButton size="sm" variant="outline-secondary" :disabled="busy || disabled" @click="run(path)">Migrate…</BButton>
        </div>
        <p v-if="NOTES[`${path.from}>${path.to}`]" class="small text-muted mb-0 mt-1">{{ NOTES[`${path.from}>${path.to}`] }}</p>
      </li>
    </ul>

    <template v-if="definition.type === 'text'">
      <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
        <span class="fw-semibold">Copy into an identifier field</span>
        <BBadge variant="warning">Leaves end-to-end encryption</BBadge>
        <BBadge variant="secondary">Keeps this field</BBadge>
      </div>
      <p class="small text-muted mb-2 mt-1">
        Decrypts this field's values in your browser and sends them to the server in plaintext, for every connection whose
        identifier is still empty. This field is left exactly as it is and existing identifiers are never overwritten, so it's safe to re-run.
      </p>
      <div v-if="identifierTargets.length" class="input-group input-group-sm mb-1">
        <BFormSelect v-model="copyTargetId">
          <option value="" disabled>To (identifier)…</option>
          <option v-for="target in identifierTargets" :key="target.id" :value="target.id">{{ target.label }}</option>
        </BFormSelect>
        <BButton variant="outline-secondary" :disabled="busy || disabled || !copyTargetId" @click="emit('copyToIdentifier', definition.id, copyTargetId)">Copy</BButton>
      </div>
      <div v-else class="input-group input-group-sm mb-1">
        <BFormInput v-model="newIdentifierLabel" type="text" placeholder="New identifier field name" />
        <BButton
          variant="outline-secondary"
          :disabled="busy || disabled || !newIdentifierLabel.trim()"
          @click="emit('createIdentifierAndCopy', definition.id, newIdentifierLabel.trim())"
        >
          Create and copy
        </BButton>
      </div>
      <div v-if="copyStatus" class="small text-muted">{{ copyStatus }}</div>
    </template>
  </section>
</template>
