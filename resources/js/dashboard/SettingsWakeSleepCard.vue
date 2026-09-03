<script setup lang="ts">
/** Settings.vue's "Wake & sleep times" card — form.availability plus the separate SleepExceptions CRUD, still saved via Settings.vue's own shared form/submit(). */
import { BButton, BCard, BFormInput } from 'bootstrap-vue-next';
import { computed } from 'vue';
import SleepExceptions from './SleepExceptions.vue';
import type { SettingsForm } from '../Pages/Dashboard/Settings.vue';

const props = defineProps<{
  form: SettingsForm;
  sleepExceptions: { id: string; start_date: string; end_date: string; label_ciphertext: string | null }[];
  submit: () => void;
}>();

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
/** Same weekday indices (0=Sun..6=Sat) as form.availability, just walked starting from week_start instead of always Sunday. */
const orderedDayIndices = computed(() => Array.from({ length: 7 }, (_, i) => (props.form.week_start + i) % 7));

/** Genuinely clears every day to blank — distinct from the "Reset" button next to it, which restores the last-saved/loaded values instead of wiping them. */
function clearAvailability(): void {
  props.form.availability = days.map(() => ({ wake: '', sleep: '' }));
}
</script>

<template>
  <form @submit.prevent="submit">
    <BCard class="mb-4">
      <h2 class="h5 mb-3">Wake &amp; sleep times</h2>
      <p class="small text-muted">Set a wake/sleep time per day. Leave both blank for no default sleep block that day.</p>
      <table class="table table-sm">
        <thead>
          <tr><th>Day</th><th>Wake up</th><th>Go to sleep</th></tr>
        </thead>
        <tbody>
          <tr v-for="i in orderedDayIndices" :key="i">
            <td class="align-middle">{{ days[i] }}</td>
            <td><BFormInput v-model="form.availability[i].wake" type="time" size="sm" /></td>
            <td><BFormInput v-model="form.availability[i].sleep" type="time" size="sm" /></td>
          </tr>
        </tbody>
      </table>

      <SleepExceptions :initial="sleepExceptions" />

      <template #footer>
        <BButton type="submit" variant="primary" :disabled="form.processing">Save settings</BButton>
        <BButton variant="outline-secondary" class="ms-2" @click="form.reset('availability')">Reset</BButton>
        <BButton variant="outline-secondary" class="ms-2" @click="clearAvailability">Clear all</BButton>
      </template>
    </BCard>
  </form>
</template>
