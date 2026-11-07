<script setup lang="ts">
/**
 * Discord friends that have no connection yet: a real, persistent pending list,
 * like the VRChat one (PendingFriendsPanel). They stay here, across page loads,
 * until you act on each one; nothing is created behind your back. Every friend
 * gets a suggestion from matching the names they go by (your own nickname for
 * them first, then their display name, then their username) against your
 * connections' names and highlight tokens (see friendMatching.ts), which you can
 * change per friend. A friend with no match may still show "possibly the same as…"
 * hints; they are only ever a click away, never preselected:
 *   - create a new connection,
 *   - add the friend's Discord ID to an existing connection,
 *   - dismiss (not asked about again).
 * Matching needs the connections' names, so it waits for the vault to be unlocked.
 */
import { BBadge, BButton, BCard } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import SearchableSelect, { type SelectOption } from '../Components/SearchableSelect.vue';
import { discordFriendName, discordFriendNames, type PendingDiscordFriend } from './discord';
import { defaultFriendChoice, type MatchBy, type MatchCandidate, matchFriendByNames, type PossibleMatch, possibleMatchesByNames } from './friendMatching';
import type { FriendChoice, FriendDecision } from './vrchat';

const props = defineProps<{
  friends: PendingDiscordFriend[];
  /** Every connection's decrypted name and highlight tokens; empty until the vault is unlocked. */
  candidates: MatchCandidate[];
  /** Whether matching can run (the vault is unlocked and the names are decrypted). */
  ready: boolean;
  /** The Discord user ID field is a single identifier, so adding a second ID to someone turns it into a list. */
  singleField: boolean;
  /** Whether adding this friend to that connection would need the identifier -> list conversion. */
  needsConversion: (connectionId: string, discordId: string) => boolean;
  busy: boolean;
}>();
const emit = defineEmits<{ apply: [FriendDecision[]] }>();

const choices = ref<Record<string, FriendChoice>>({});
const showAll = ref(false);
const PAGE = 25;

const names = computed(() => Object.fromEntries(props.candidates.map((c) => [c.id, c.name])));

interface Row {
  friend: PendingDiscordFriend;
  /** Connections that matched, best first. */
  suggested: { id: string; by: MatchBy }[];
  /** Lower-confidence guesses, only for a friend with no match. Never preselected. */
  possible: PossibleMatch[];
}

const rows = computed<Row[]>(() => props.friends.map((friend) => {
  const match = matchFriendByNames(discordFriendNames(friend), props.candidates);
  const suggested = match.kind === 'one'
    ? [{ id: match.connectionId, by: match.by }]
    : match.kind === 'several' ? match.connectionIds.map((id) => ({ id, by: 'name' as const })) : [];

  return { friend, suggested, possible: suggested.length ? [] : possibleMatchesByNames(discordFriendNames(friend), props.candidates) };
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

function converting(row: Row): boolean {
  const choice = choices.value[row.friend.id];
  return props.singleField && !!choice && choice !== 'new' && choice !== 'skip' && props.needsConversion(choice, row.friend.discord_user_id);
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

function alsoKnownAs(friend: PendingDiscordFriend): string {
  const shown = discordFriendName(friend);
  return [friend.global_name, friend.username].filter((n): n is string => !!n && n !== shown).join(' · ');
}
</script>

<template>
  <BCard id="pending-discord-friends" class="mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <h2 class="h5 mb-0">
        Discord friends to add
        <BBadge variant="secondary" pill class="ms-1">{{ friends.length }}</BBadge>
      </h2>
      <BButton v-if="ready" variant="primary" size="sm" :disabled="busy" @click="emit('apply', rows.map(decision))">
        {{ busy ? 'Working…' : `Apply all (${counts.new} new, ${counts.existing} to existing${counts.skip ? `, ${counts.skip} dismissed` : ''})` }}
      </BButton>
    </div>
    <p class="small text-muted mb-3">
      These Discord friends have no connection yet. They stay here until you deal with each one. The suggestion comes from matching
      the names they go by (your nickname for them first) against your connections' names and highlight tokens; change it where it's wrong.
    </p>

    <p v-if="!ready" class="small mb-0">Unlock your vault to match these friends against your connections.</p>

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
              :alt="`${discordFriendName(row.friend)}'s Discord avatar`"
              width="40"
              height="40"
              class="rounded flex-shrink-0 object-fit-cover"
              loading="lazy"
              referrerpolicy="no-referrer"
            >
            <div v-else class="rounded bg-body-secondary flex-shrink-0" style="width: 40px; height: 40px;" aria-hidden="true" />

            <div class="flex-grow-1 small">
              <div class="fw-semibold">
                {{ discordFriendName(row.friend) }}
                <BBadge v-if="row.friend.blocked" variant="danger" class="ms-1" title="You have blocked this person on Discord">Blocked</BBadge>
              </div>
              <div v-if="alsoKnownAs(row.friend)" class="text-muted">{{ alsoKnownAs(row.friend) }}</div>
              <div class="text-muted font-monospace">{{ row.friend.discord_user_id }}</div>
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
              :aria-label="`What to do with ${discordFriendName(row.friend)}`"
            />

            <BButton variant="outline-secondary" size="sm" :disabled="busy" @click="emit('apply', [decision(row)])">Apply</BButton>
          </div>
          <div v-if="converting(row)" class="small text-muted mt-1 ms-5 ps-3">
            That connection already has a different Discord ID: applying turns the Discord ID field into an identifier list first (one way).
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
