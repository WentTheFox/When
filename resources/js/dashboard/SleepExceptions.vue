<script setup lang="ts">
import { faUserLock } from '@fortawesome/free-solid-svg-icons';

/** label_ciphertext is client-vault E2EE (§0.1/§0.3) — see ConnectionController's doc comment. */
import axios from 'axios';
import { BButton, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { onMounted, ref, watch } from 'vue';
import { decryptString, encryptString } from '../crypto';
import { requestUnlock } from './vaultModal';
import { useVault } from './useVault';

interface SleepException {
  id: string;
  start_date: string;
  end_date: string;
  label_ciphertext: string | null;
  label?: string;
}

const props = defineProps<{ initial: SleepException[] }>();
const { vaultUnlocked, createRecordKey, getRecordKey } = useVault();

const exceptions = ref<SleepException[]>(props.initial);
const startDate = ref('');
const endDate = ref('');
const label = ref('');
const error = ref('');
const adding = ref(false);

async function decryptLabel(exception: SleepException): Promise<void> {
  if (!exception.label_ciphertext) {
    exception.label = '';
    return;
  }
  if (!vaultUnlocked.value) {
    return;
  }
  try {
    const key = await getRecordKey(exception.id);
    exception.label = await decryptString(key, exception.label_ciphertext);
  } catch (e) {
    console.error(e);
    exception.label = '(could not decrypt)';
  }
}

onMounted(() => {
  exceptions.value.forEach(decryptLabel);
});

// Dates aren't vault-tier at all, so the list/add/remove above works
// whether or not the vault is unlocked — only the optional private note is
// E2EE. Decrypt whichever notes are still pending as soon as unlock happens.
watch(vaultUnlocked, (unlocked) => {
  if (unlocked) exceptions.value.forEach(decryptLabel);
});

async function add(): Promise<void> {
  error.value = '';

  if (!startDate.value || !endDate.value) {
    error.value = 'Start and end dates are required.';
    return;
  }

  adding.value = true;

  try {
    const id = crypto.randomUUID();
    let labelCiphertext: string | null = null;

    if (label.value && vaultUnlocked.value) {
      const key = await createRecordKey(id);
      labelCiphertext = await encryptString(key, label.value);
    }

    await axios.post('/settings/sleep-exceptions', {
      id,
      start_date: startDate.value,
      end_date: endDate.value,
      label_ciphertext: labelCiphertext,
    });

    exceptions.value.push({
      id,
      start_date: startDate.value,
      end_date: endDate.value,
      label_ciphertext: labelCiphertext,
      label: label.value,
    });

    startDate.value = '';
    endDate.value = '';
    label.value = '';
  } catch (e) {
    console.error(e);
    error.value = 'Could not add that exception.';
  } finally {
    adding.value = false;
  }
}

async function remove(exception: SleepException): Promise<void> {
  try {
    await axios.delete(`/settings/sleep-exceptions/${exception.id}`);
    exceptions.value = exceptions.value.filter((e) => e.id !== exception.id);
  } catch (e) {
    console.error(e);
  }
}

// Trying to interact with the note field is what should open the shared
// vault dialog — not the page loading, since dates work fine locked. Blur
// immediately so nothing can be typed until actually unlocked; once it is,
// this handler is a no-op and the field behaves normally.
function onNoteFocus(event: FocusEvent): void {
  if (vaultUnlocked.value) return;
  (event.target as HTMLInputElement).blur();
  requestUnlock();
}
</script>

<template>
  <h2 class="h5 mb-3">Sleep exceptions</h2>
  <p class="small text-muted">
    Suppress your default sleep block for a date range (e.g. travel), with an optional private note.
  </p>

  <table class="table table-sm">
    <thead>
      <tr><th>Start</th><th>End</th><th>Note</th><th /></tr>
    </thead>
    <tbody>
      <tr v-for="exception in exceptions" :key="exception.id">
        <td>{{ exception.start_date }}</td>
        <td>{{ exception.end_date }}</td>
        <td>
          <BButton v-if="exception.label_ciphertext && !vaultUnlocked" size="sm" variant="outline-secondary" @click="requestUnlock()">
            <FontAwesomeIcon :icon="faUserLock" />
            Vault is locked
          </BButton>
          <template v-else>{{ exception.label }}</template>
        </td>
        <td>
          <BButton variant="outline-danger" size="sm" @click="remove(exception)">Remove</BButton>
        </td>
      </tr>
    </tbody>
  </table>

  <div class="row align-items-end">
    <div class="col-md-3">
      <BFormGroup label="Start" label-for="exception_start" class="mb-3">
        <BFormInput id="exception_start" v-model="startDate" type="date" />
      </BFormGroup>
    </div>
    <div class="col-md-3">
      <BFormGroup label="End" label-for="exception_end" class="mb-3">
        <BFormInput id="exception_end" v-model="endDate" type="date" />
      </BFormGroup>
    </div>
    <div class="col-md-4">
      <BFormGroup label="Note (optional, private)" label-for="exception_label" class="mb-3">
        <BFormInput
          id="exception_label"
          v-model="label"
          type="text"
          :placeholder="vaultUnlocked ? '' : 'Click to unlock your vault'"
          @focus="onNoteFocus"
        />
      </BFormGroup>
    </div>
    <div class="col-md-2 mb-3">
      <BButton variant="outline-secondary" class="w-100" :disabled="adding" @click="add">Add</BButton>
    </div>
  </div>
  <div class="text-danger small">{{ error }}</div>
</template>
