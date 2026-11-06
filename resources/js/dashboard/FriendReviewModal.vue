<script setup lang="ts">
/**
 * Where should each ambiguous VRChat friend go? Shown when a friend matches
 * several existing connections (by name or highlight token), or matches one that
 * already holds a different VRChat id in a single-identifier field. Nothing is
 * created or changed until Apply: each friend becomes a new connection, is added
 * to one of the connections that matched, or is skipped for good.
 *
 * Closing the dialog any other way is "decide later": the friends stay pending
 * and the page offers them again.
 */
import { BAlert, BButton, BModal } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { needsListConversion, type ReviewChoice, type ReviewItem } from './friendMatching';

const props = defineProps<{
  items: ReviewItem[];
  /** The VRChat user ID field's type; 'identifier' means mapping onto an occupied one converts it to a list. */
  fieldType: string;
  busy: boolean;
}>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ apply: [Record<string, ReviewChoice>] }>();

const choices = ref<Record<string, ReviewChoice>>({});

// A friend with a single candidate starts on that connection (the match was exact, it only needs the owner's OK
// because of the occupied field); with several to pick from, starting on "new" avoids a wrong mapping.
watch(() => props.items, (items) => {
  const next: Record<string, ReviewChoice> = {};
  for (const item of items) next[item.friendId] = choices.value[item.friendId] ?? (item.candidates.length === 1 ? item.candidates[0].id : 'new');
  choices.value = next;
}, { immediate: true });

const converting = computed(() => props.fieldType === 'identifier' && needsListConversion(props.items, choices.value));
</script>

<template>
  <BModal v-model="open" title="Where should these VRChat friends go?" size="lg" scrollable no-footer>
    <p class="small text-muted">
      These friends matched more than one connection you already have, or one that already has a different VRChat ID.
      Nothing has been added yet: choose for each, then apply.
    </p>

    <div v-for="item in items" :key="item.friendId" class="border rounded p-2 mb-2">
      <div class="fw-semibold">{{ item.name }} <span class="small text-muted font-monospace">{{ item.vrchatId }}</span></div>

      <div class="form-check">
        <input :id="`friend-${item.friendId}-new`" v-model="choices[item.friendId]" class="form-check-input" type="radio" :name="`friend-${item.friendId}`" value="new">
        <label class="form-check-label" :for="`friend-${item.friendId}-new`">Create a new connection</label>
      </div>
      <div v-for="candidate in item.candidates" :key="candidate.id" class="form-check">
        <input :id="`friend-${item.friendId}-${candidate.id}`" v-model="choices[item.friendId]" class="form-check-input" type="radio" :name="`friend-${item.friendId}`" :value="candidate.id">
        <label class="form-check-label" :for="`friend-${item.friendId}-${candidate.id}`">
          Add to existing connection <strong>{{ candidate.name || '(unnamed)' }}</strong>
          <span v-if="candidate.conflict" class="small text-muted">(already has a different VRChat ID)</span>
        </label>
      </div>
      <div class="form-check">
        <input :id="`friend-${item.friendId}-skip`" v-model="choices[item.friendId]" class="form-check-input" type="radio" :name="`friend-${item.friendId}`" value="skip">
        <label class="form-check-label" :for="`friend-${item.friendId}-skip`">Skip this friend (not asked about again)</label>
      </div>
    </div>

    <BAlert :model-value="converting" variant="warning" class="small py-2">
      A connection you chose already has a VRChat ID, and the field holds only one. Applying turns the field into an
      <strong>identifier list</strong> first (every connection keeps its ID as a one-entry list). This can't be undone.
    </BAlert>

    <div class="d-flex gap-2">
      <BButton variant="primary" :disabled="busy" @click="emit('apply', { ...choices })">{{ busy ? 'Applying…' : 'Apply' }}</BButton>
      <BButton variant="outline-secondary" :disabled="busy" @click="open = false">Decide later</BButton>
    </div>
  </BModal>
</template>
