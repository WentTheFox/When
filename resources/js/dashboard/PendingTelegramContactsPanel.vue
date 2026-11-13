<script setup lang="ts">
/**
 * Telegram contacts that have no connection yet: a real, persistent pending list,
 * like the VRChat one (PendingFriendsPanel). They stay here, across page loads,
 * until you act on each one; nothing is created behind your back. Every contact
 * gets a suggestion from matching the names they go by (their name, then their
 * username) against your connections' names and highlight tokens (see friendMatching.ts), which you can
 * change per friend. A friend with no match may still show "possibly the same as…"
 * hints; they are only ever a click away, never preselected:
 *   - create a new connection,
 *   - add the contact's Telegram ID to an existing connection,
 *   - dismiss (not asked about again).
 * Matching needs the connections' names, so it waits for the vault to be unlocked.
 */
import { BBadge, BButton, BCard, BSpinner } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import SearchableSelect, { type SelectOption } from '../Components/SearchableSelect.vue';
import { type PendingTelegramContact, telegramContactName, telegramContactNames } from './telegram';
import { batchNeedsList, defaultFriendChoice, type MatchBy, type MatchCandidate, matchFriendByNames, type PossibleMatch, possibleMatchesByNames } from './friendMatching';
import type { FriendChoice, FriendDecision } from './vrchat';

const props = defineProps<{
  friends: PendingTelegramContact[];
  /** Every connection's decrypted name and highlight tokens; empty until the vault is unlocked. */
  candidates: MatchCandidate[];
  /** Whether matching can run (the vault is unlocked and the names are decrypted). */
  ready: boolean;
  /** The vault is open, so a not-ready panel is still decrypting the connections rather than waiting for an unlock. */
  unlocked?: boolean;
  /** The Telegram user ID field is a single identifier, so adding a second ID to someone turns it into a list. */
  singleField: boolean;
  /** Whether adding this friend to that connection would need the identifier -> list conversion. */
  needsConversion: (connectionId: string, telegramId: string) => boolean;
  busy: boolean;
}>();
const emit = defineEmits<{ apply: [FriendDecision[]] }>();

const choices = ref<Record<string, FriendChoice>>({});
const showAll = ref(false);
const PAGE = 25;

const names = computed(() => Object.fromEntries(props.candidates.map((c) => [c.id, c.name])));

interface Row {
  friend: PendingTelegramContact;
  /** Connections that matched, best first. */
  suggested: { id: string; by: MatchBy }[];
  /** Lower-confidence guesses, only for a friend with no match. Never preselected. */
  possible: PossibleMatch[];
}

const rows = computed<Row[]>(() => props.friends.map((friend) => {
  const match = matchFriendByNames(telegramContactNames(friend), props.candidates);
  const suggested = match.kind === 'one'
    ? [{ id: match.connectionId, by: match.by }]
    : match.kind === 'several' ? match.connectionIds.map((id) => ({ id, by: 'name' as const })) : [];

  return { friend, suggested, possible: suggested.length ? [] : possibleMatchesByNames(telegramContactNames(friend), props.candidates) };
}));

// A friend starts on what the match suggests: the one connection that matched, otherwise "new". Choices already made survive a refresh of the list.
function syncChoices(): void {
  if (!props.ready) return;

  const next: Record<string, FriendChoice> = {};
  for (const row of rows.value) {
    next[row.friend.id] = choices.value[row.friend.id] ?? defaultFriendChoice(row.suggested.map((s) => s.id));
  }
  choices.value = next;
}

syncChoices();
watch([rows, () => props.ready], syncChoices);

// Sets the action of every pending friend at once (not just the visible page), e.g. dismiss all but the couple you pick out by hand.
// It only changes the per-friend dropdowns; nothing is applied until you press Apply.
const bulkChoice = ref('');
const bulkOptions: SelectOption[] = [
  { value: 'new', text: 'Create a new connection for all' },
  { value: 'skip', text: "Dismiss all (don't ask again)" },
  { value: 'suggested', text: 'Back to the suggestions' },
];

watch(bulkChoice, (value) => {
  if (!value) return;

  const next: Record<string, FriendChoice> = {};
  for (const row of rows.value) {
    next[row.friend.id] = value === 'suggested' ? defaultFriendChoice(row.suggested.map((s) => s.id)) : (value as FriendChoice);
  }
  choices.value = next;
  bulkChoice.value = '';
});

const visible = computed(() => (showAll.value ? rows.value : rows.value.slice(0, PAGE)));

/** The per-friend action list: new / dismiss / suggested / every other connection. */
function choiceOptions(row: { suggested: { id: string }[] }): SelectOption[] {
  const suggested = new Set(row.suggested.map((s) => s.id));
  return [
    // The meta-options come first, so they are never a long scroll away.
    { value: 'new', text: 'Create a new connection' },
    { value: 'skip', text: "Dismiss (don't ask again)" },
    ...allOptions.value.filter((o) => suggested.has(o.id)).map((o) => ({ value: o.id, text: `Add to ${o.label}`, group: 'Suggested' })),
    ...allOptions.value.filter((o) => !suggested.has(o.id)).map((o) => ({ value: o.id, text: o.label, group: 'Add to another connection' })),
  ];
}

const allOptions = computed(() => props.candidates.map((c) => ({ id: c.id, label: c.name || '(unnamed)' })).sort((a, b) => a.label.localeCompare(b.label)));

function decision(row: Row): FriendDecision {
  return { friendId: row.friend.id, choice: choices.value[row.friend.id] ?? 'new' };
}

/** Another contact in this batch goes to the same connection with a different Telegram ID (so the single field must become a list). */
function sharesConnection(row: Row): boolean {
  const choice = choices.value[row.friend.id];
  if (!props.singleField || !choice || choice === 'new' || choice === 'skip') return false;

  return batchNeedsList(rows.value
    .filter((other) => choices.value[other.friend.id] === choice)
    .map((other) => ({ choice, externalId: other.friend.telegram_user_id })));
}

function converting(row: Row): boolean {
  const choice = choices.value[row.friend.id];
  return props.singleField && !!choice && choice !== 'new' && choice !== 'skip' && props.needsConversion(choice, row.friend.telegram_user_id);
}

const counts = computed(() => {
  const tally = { new: 0, existing: 0, skip: 0 };
  for (const row of rows.value) {
    const choice = choices.value[row.friend.id] ?? 'new';
    if (choice === 'new') tally.new++;
    else if (choice === 'skip') tally.skip++;
    else tally.existing++;
  }
  return tally;
});

/** The username, when the name above is not already it. */
function alsoKnownAs(friend: PendingTelegramContact): string {
  return friend.username && friend.name ? `@${friend.username}` : '';
}
</script>

<template>
  <BCard id="pending-telegram-contacts" class="mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <h2 class="h5 mb-0">
        Telegram contacts to add
        <BBadge variant="secondary" pill class="ms-1">{{ friends.length }}</BBadge>
      </h2>
      <BButton v-if="ready" variant="primary" size="sm" :disabled="busy" @click="emit('apply', rows.map(decision))">
        {{ busy ? 'Working…' : `Apply all (${counts.new} new, ${counts.existing} to existing${counts.skip ? `, ${counts.skip} dismissed` : ''})` }}
      </BButton>
    </div>
    <p class="small text-muted mb-3">
      These Telegram contacts have no connection yet. They stay here until you deal with each one. The suggestion comes from matching
      the names they go by (their name, then their username) against your connections' names and highlight tokens; change it where it's wrong.
    </p>

    <p v-if="!ready && unlocked" class="small mb-0" role="status">
      <BSpinner small class="me-2" />Decrypting your connections to match these friends…
    </p>
    <p v-else-if="!ready" class="small mb-0">Unlock your vault to match these friends against your connections.</p>

    <template v-else>
      <div v-if="rows.length > 1" class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="small text-muted">Set all {{ rows.length }} to:</span>
        <SearchableSelect
          v-model="bulkChoice"
          style="width: 18rem; max-width: 100%;"
          :options="bulkOptions"
          placeholder="Choose an action for everyone…"
          :aria-label="`Set the action for all ${rows.length} pending friends`"
        />
        <span class="small text-muted">then change the few you do want, and press Apply all.</span>
      </div>
      <div class="list-group" style="max-height: 28rem; overflow-y: auto;">
        <div v-for="row in visible" :key="row.friend.id" class="list-group-item">
          <div class="d-flex align-items-center gap-3">
            <img
              v-if="row.friend.picture_url"
              :src="row.friend.picture_url"
              :alt="`${telegramContactName(row.friend)}'s Telegram photo`"
              width="40"
              height="40"
              class="rounded flex-shrink-0 object-fit-cover"
              
            >
            <div v-else class="rounded bg-body-secondary flex-shrink-0" style="width: 40px; height: 40px;" aria-hidden="true" />

            <div class="flex-grow-1 small">
              <div class="fw-semibold">
                {{ telegramContactName(row.friend) }}
                <BBadge v-if="row.friend.premium" variant="info" class="ms-1" title="Telegram Premium">Premium</BBadge>
                <BBadge v-if="!row.friend.mutual" variant="secondary" class="ms-1" title="They do not have you in their contacts">One-way</BBadge>
              </div>
              <div v-if="alsoKnownAs(row.friend)" class="text-muted">{{ alsoKnownAs(row.friend) }}</div>
              <div class="text-muted font-monospace">{{ row.friend.telegram_user_id }}</div>
              <div v-if="row.friend.phone" class="text-muted">{{ row.friend.phone }}</div>
              <div v-if="row.suggested.length === 1" class="text-success">
                Matches <strong>{{ names[row.suggested[0].id] || '(unnamed)' }}</strong> by {{ row.suggested[0].by === 'name' ? 'name' : row.suggested[0].by === 'token' ? 'highlight token' : 'name, ignoring symbols and other-script characters' }}
              </div>
              <div v-else-if="row.suggested.length > 1" class="text-warning">Matches {{ row.suggested.length }} connections: pick one, or create a new one</div>
              <div v-else-if="row.possible.length" class="text-body-secondary">
                Possibly the same as
                <template v-for="(p, i) in row.possible" :key="p.id">
                  <BButton
                    variant="link"
                    size="sm"
                    class="p-0 align-baseline"
                    :title="`Looks alike via “${p.via}”`"
                    @click="choices[row.friend.id] = p.id"
                  >{{ names[p.id] || '(unnamed)' }}</BButton>
                  <span class="text-muted"> ({{ p.reason === 'word' ? `shares “${p.detail}”` : p.reason === 'prefix' ? `starts like “${p.detail}”` : p.detail }}{{ p.via !== names[p.id] ? ` in “${p.via}”` : '' }})</span>{{ i < row.possible.length - 1 ? ', ' : '' }}
                </template>
              </div>
            </div>

            <SearchableSelect
              v-model="choices[row.friend.id]"
              style="width: 18rem; max-width: 100%;"
              :options="choiceOptions(row)"
              :aria-label="`What to do with ${telegramContactName(row.friend)}`"
            />

            <BButton variant="outline-secondary" size="sm" :disabled="busy" @click="emit('apply', [decision(row)])">Apply</BButton>
          </div>
          <div v-if="converting(row)" class="small text-muted mt-1 ms-5 ps-3">
            That connection already has a different Telegram ID: applying turns the Telegram ID field into an identifier list first (one way).
          </div>
          <div v-else-if="sharesConnection(row)" class="small text-muted mt-1 ms-5 ps-3">
            Another contact here also goes to this connection: applying turns the Telegram ID field into an identifier list first (one way).
          </div>
        </div>
      </div>

      <div v-if="rows.length > PAGE" class="mt-2">
        <BButton variant="link" size="sm" class="p-0" @click="showAll = !showAll">
          {{ showAll ? `Show the first ${PAGE}` : `Show all ${rows.length}` }}
        </BButton>
      </div>
    </template>
  </BCard>
</template>
