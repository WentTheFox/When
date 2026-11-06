<script setup lang="ts">
/**
 * "This connection is really that one": picks the connection that should take
 * over this one's VRChat ID(s). The page then adds the ID(s) to it and deletes
 * this connection. Nothing happens until Merge.
 */
import { BAlert, BButton, BFormInput, BModal } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
  /** The connection being merged away (decrypted name). */
  sourceName: string;
  /** The VRChat ids that move. */
  ids: string[];
  /** Every other connection, in list order. */
  options: { id: string; label: string }[];
  /** The VRChat field is a single identifier, so a target that already has an id turns it into a list. */
  singleField: boolean;
  busy: boolean;
  error: string;
}>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ merge: [string] }>();

const filter = ref('');
const targetId = ref('');

watch(open, (isOpen) => {
  if (isOpen) {
    filter.value = '';
    targetId.value = '';
  }
});

const shown = computed(() => {
  const query = filter.value.trim().toLowerCase();
  return query ? props.options.filter((o) => o.label.toLowerCase().includes(query)) : props.options;
});

const targetLabel = computed(() => props.options.find((o) => o.id === targetId.value)?.label ?? '');
</script>

<template>
  <BModal v-model="open" title="Merge into another connection" scrollable no-footer>
    <p class="small mb-2">
      Moves {{ ids.length === 1 ? 'the VRChat ID' : `the ${ids.length} VRChat IDs` }} of <strong>{{ sourceName || 'this connection' }}</strong>
      to the connection you choose, then <strong>deletes {{ sourceName || 'this connection' }}</strong>.
    </p>
    <ul class="small font-monospace text-muted">
      <li v-for="id in ids" :key="id">{{ id }}</li>
    </ul>

    <BFormInput v-model="filter" type="search" size="sm" placeholder="Find a connection" class="mb-2" autocomplete="off" />
    <select v-model="targetId" class="form-select form-select-sm mb-2" size="8" aria-label="Connection to merge into">
      <option v-for="option in shown" :key="option.id" :value="option.id">{{ option.label || '(unnamed)' }}</option>
    </select>
    <p v-if="!shown.length" class="small text-muted">No connection matches.</p>

    <BAlert :model-value="true" variant="warning" class="small py-2">
      Everything else on <strong>{{ sourceName || 'this connection' }}</strong> (notes, custom fields, relationships, share link) is deleted with it.
      The VRChat name is added to the other connection's highlight tokens when its profile is fetched.
      <template v-if="singleField">If it already has a different VRChat ID, the VRChat ID field becomes an identifier list first (one way).</template>
    </BAlert>
    <BAlert :model-value="error !== ''" variant="danger" class="small py-2">{{ error }}</BAlert>

    <div class="d-flex gap-2">
      <BButton variant="danger" :disabled="busy || !targetId" @click="emit('merge', targetId)">
        {{ busy ? 'Merging…' : targetLabel ? `Merge into ${targetLabel}` : 'Merge' }}
      </BButton>
      <BButton variant="outline-secondary" :disabled="busy" @click="open = false">Cancel</BButton>
    </div>
  </BModal>
</template>
