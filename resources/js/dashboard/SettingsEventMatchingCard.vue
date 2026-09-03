<script setup lang="ts">
/** Settings.vue's "Event title matching rules" card — dnd/nap/work/school event-name patterns plus the highlight/activity/tentative/open-end/open-start regex fields, all part of the shared `form` Settings.vue owns and saves via its own submit(). */
import { BAlert, BBadge, BButton, BCard, BFormGroup } from 'bootstrap-vue-next';
import PatternPreview from './PatternPreview.vue';
import RegexHighlightedCode from './RegexHighlightedCode.vue';
import RegexPatternInput from './RegexPatternInput.vue';
import type { Settings, SettingsDefaults } from './settingsTypes';
import type { EventMatchingSettingsForm } from '../Pages/Dashboard/Settings.vue';

const props = defineProps<{
  eventMatchingSettingsForm: EventMatchingSettingsForm;
  defaults: SettingsDefaults;
}>();

/** Every pattern input's own `id` is set to its Settings field name 1:1 (e.g. `id="work_event_pattern"` on the field bound to `form.work_event_pattern`) — RegexPatternInput.vue forwards that id straight onto its native textarea, so this is enough to reach the actual focusable element without a template ref per field. */
function focusField(field: string): void {
  document.getElementById(field)?.focus();
}

/**
 * Also used by the "Use" button next to each pattern field's suggested/
 * default value (below) — putting that value straight into the field is
 * the same operation as resetting a color to a literal. Focusing the
 * field afterward means an owner who clicked "Use" can immediately start
 * editing from there (e.g. tweaking the suggestion) without an extra
 * click to get into the field themselves.
 */
function setFormField(field: keyof Settings, value: string): void {
  (props.eventMatchingSettingsForm as unknown as Record<string, string>)[field] = value;
  focusField(field);
}

/** This card's "Reset" button field list, named explicitly for clarity even though eventMatchingSettingsForm holds only these fields anyway. */
const EVENT_MATCHING_FIELDS = [
  'dnd_event_pattern', 'nap_event_pattern', 'work_event_pattern', 'school_event_pattern',
  'highlight_clause_pattern', 'highlight_split_pattern', 'activity_clause_pattern',
  'tentative_pattern', 'open_end_pattern', 'open_start_pattern',
] as const;

const PATTERN_DISABLED_TEXT = '(blank, off)';

function submit(): void {
  props.eventMatchingSettingsForm.patch('/settings', {
    preserveScroll: true,
    // Updates form's own "reset to" baseline to the values just saved —
    // without this, every card's Reset button would always revert to
    // whatever was on the page at the very first load, never to a save
    // made sometime after that.
    onSuccess: () => props.eventMatchingSettingsForm.defaults(),
  });
}
</script>

<template>
  <form @submit.prevent="submit">
    <BCard class="mb-4">
        <h2 class="h5 mb-3">Event title matching rules</h2>

        <BAlert :model-value="true" variant="secondary" class="small">
          <p class="mb-2">
            <strong>What these text-match fields actually do:</strong> what you type isn't
            compared for an exact match — it's used as the body of a
            <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions" target="_blank" rel="noopener">regular expression</a>,
            tested case-insensitively against <em>anywhere</em> in the event's title by default.
            A quick crash course in the handful of regex bits that actually come up on this page:
          </p>
          <p class="mb-1">Each field below is badged with how it actually uses your pattern:</p>
          <dl class="wtf-badge-legend mb-2">
            <dt><BBadge variant="secondary" class="align-middle">Boolean</BBadge></dt>
            <dd>Just tests whether it matches at all — nothing is captured.</dd>
            <dt><BBadge variant="info" text="dark" class="align-middle">Capture</BBadge></dt>
            <dd>
              Requires exactly one real <code>(…)</code> capture group, whose contents are the
              actual thing used (see the <code>(…)</code>/<code>(?:…)</code> bullets below — this
              is checked and rejected on save if it's missing or there's more than one).
            </dd>
            <dt><BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></dt>
            <dd>
              Also a boolean match (no group read), but with two side effects together: it marks
              the event tentative at that edge (start/end/both, depending on the field)
              <em>and</em> removes the matched marker text from the title used for pattern
              matching.
            </dd>
            <dt><BBadge variant="primary" class="align-middle">Split</BBadge></dt>
            <dd>
              Isn't matched against a title at all — it's a delimiter used to break one field's
              captured text into individual pieces.
            </dd>
          </dl>
          <p>A quick crash course in the handful of regex bits that actually come up on this page:</p>
          <ul class="mb-2">
            <li>
              Unanchored by default — a plain word like <code>dnd</code> matches a title that
              merely <em>contains</em> "dnd" anywhere, case-insensitively. "Team DND block"
              matches just as much as a title that's only "DND".
            </li>
            <li>
              <code>^</code> anchors to the very <em>start</em> of the title — <code>^dnd</code>
              only matches a title that begins with "dnd".
            </li>
            <li>
              <code>$</code> anchors to the very <em>end</em> of the title — <code>dnd$</code>
              only matches a title that ends with "dnd".
            </li>
            <li>
              <code>^</code> and <code>$</code> together require the pattern to match the
              <em>whole</em> title, not just part of it — <code>^dnd$</code> matches a title
              that's exactly "dnd" (still case-insensitive), but not "Team DND block".
            </li>
            <li>
              <code>(…)</code> groups characters together — mainly to build an alternation like
              <code>(dnd|do not disturb)</code>, or so <code>?</code>/<code>*</code>/<code>+</code>
              apply to more than one character at once. Parenthesized text is also "captured",
              but only the Highlight and Activity fields below actually use a capture group's
              contents — everywhere else on this page, <code>(…)</code> is just for grouping.
            </li>
            <li>
              <code>(?:…)</code> is the same grouping, just <em>non-capturing</em> — it still
              lets you write an alternation like <code>(?:dnd|do not disturb)</code> without
              that group counting as the pattern's capture group. The Highlight and Activity
              fields below require exactly one real <code>(…)</code> capture group each (the
              one whose contents actually get used) — reach for <code>(?:…)</code> for any
              other grouping in those two fields so it doesn't count against that limit.
            </li>
            <li>
              <code>[…]</code> is a character class — it matches any <em>one</em> of the
              characters listed inside, not the sequence as a whole. <code>[,&amp;/]</code> (the
              Highlight name-split expression's own default, below) matches a single comma,
              ampersand, <em>or</em> slash — not that whole three-character string.
              <code>-</code> inside a class builds a <em>range</em> (<code>[a-z]</code> is every
              lowercase letter) rather than meaning a literal hyphen — if you actually want a
              literal <code>-</code> in a class and aren't sure whether it'll be read as a range,
              put it right at the start or the end of the class (e.g. <code>[,&amp;/-]</code>),
              where it can't form one.
            </li>
            <li>
              <code>?</code>, <code>*</code>, and <code>+</code> repeat whatever came right
              before them — a single character, or a whole <code>(…)</code>/<code>(?:…)</code>
              group — but as a fixed shorthand rather than a chosen count: <code>?</code> means
              "zero or one" (optional), <code>*</code> means "zero or more", and <code>+</code>
              means "one or more". <code>colou?r</code> matches both "color" and "colour";
              <code>lo+l</code> matches "lol", "lool", "loool", and so on, but not "ll".
            </li>
            <li>
              <code>{n}</code>, <code>{n,}</code>, and <code>{n,m}</code> also repeat whatever came
              right before them the same way, but for an exact or bounded count you choose instead
              of one of those three fixed shorthands: exactly <code>n</code> times, <code>n</code>
              or more times, or between <code>n</code> and <code>m</code> times. <code>a{2,4}</code>
              matches "aa", "aaa", or "aaaa", but not a single "a" or five of them.
            </li>
            <li>
              <code>.</code> matches <em>any single character</em> (except a newline) — not a
              literal period the way it looks. <code>a.c</code> matches "abc", "a c", "a-c", and so
              on, just as much as "a.c". If you actually want a literal period, escape it:
              <code>a\.c</code> matches only "a.c".
            </li>
            <li>
              <code>\</code> in front of any of the metacharacters above (<code>\.</code>,
              <code>\?</code>, <code>\[</code>, <code>\]</code>, <code>\{</code>, <code>\}</code>,
              <code>\(</code>, <code>\)</code>, <code>\\</code>, etc.) strips that character's
              special meaning so it matches only itself — the pattern editors above color these
              as plain text, since that's really all they are. The same <code>\</code> in front of
              a letter usually means the opposite — it <em>adds</em> special meaning instead
              (<code>\d</code> any digit, <code>\w</code> any letter/digit/underscore,
              <code>\s</code> any whitespace) — those get their own color above since they're a
              genuinely different kind of match, not just an escaped literal.
            </li>
          </ul>
          <p class="mb-2">
            If what you type isn't valid regex syntax, saving is rejected with an error rather
            than silently accepting it — a pattern that somehow still turns out to be invalid at
            match time anyway (e.g. after a future PHP/PCRE upgrade) fails closed instead of
            breaking your page. Leaving a field blank falls back to its built-in default if it has
            one, or turns that feature off entirely if it doesn't — see each field's own
            description below for which applies.
          </p>
          <p class="mb-0">
            The live previews below run in your browser's own regex engine, just to give you a
            quick sanity check as you type — the actual matching that decides what a viewer sees
            always happens server-side, in PHP's regex engine. The two are compatible for
            everything covered above, but if you reach for more exotic regex syntax, a rare
            mismatch between the two engines is possible; the server-side result is always the
            one that counts.
          </p>
        </BAlert>

        <!--
          Each field gets its own input+preview row (rather than one column
          of all 8 inputs stacked above a second column of all 8 previews)
          so on mobile — where col-md-6 collapses to full width and the two
          columns stack — a field's own preview appears immediately after
          it, not after scrolling past every other field first.
        -->
        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="dnd_event_pattern" class="mb-0">
              <template #label>DND event regular expression <BBadge variant="secondary" class="align-middle">Boolean</BBadge></template>
              <RegexPatternInput id="dnd_event_pattern" v-model="eventMatchingSettingsForm.dnd_event_pattern" />
              <template #description>
                A match causes that event's duration to be marked as unavailable, unless a share link bypasses it.
                Suggested: <RegexHighlightedCode :pattern="defaults.dndEventPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('dnd_event_pattern', defaults.dndEventPattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">Live preview — <code>{{
                  eventMatchingSettingsForm.dnd_event_pattern || PATTERN_DISABLED_TEXT
                }}</code></p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.dnd_event_pattern"
                :examples="['DND', 'Team DND block', 'dnd - focus time', 'Focus time', 'Lunch with Sarah']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="nap_event_pattern" class="mb-0">
              <template #label>Nap event regular expression <BBadge variant="secondary" class="align-middle">Boolean</BBadge></template>
              <RegexPatternInput id="nap_event_pattern" v-model="eventMatchingSettingsForm.nap_event_pattern" />
              <template #description>
                A match shows the event as sleep instead of busy.
                Suggested: <RegexHighlightedCode :pattern="defaults.napEventPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('nap_event_pattern', defaults.napEventPattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">Live preview — <code>{{
                  eventMatchingSettingsForm.nap_event_pattern || PATTERN_DISABLED_TEXT
                }}</code></p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.nap_event_pattern"
                :examples="['Nap', 'Afternoon nap', 'NAP TIME', 'Sleep', 'Standup meeting']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="work_event_pattern" class="mb-0">
              <template #label>Work event regular expression <BBadge variant="secondary" class="align-middle">Boolean</BBadge></template>
              <RegexPatternInput id="work_event_pattern" v-model="eventMatchingSettingsForm.work_event_pattern" />
              <template #description>
                A match counts toward the "work" slice of the dashboard's time-breakdown widget and
                the /free calendar's own work category.
                Suggested: <RegexHighlightedCode :pattern="defaults.workEventPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('work_event_pattern', defaults.workEventPattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">Live preview — <code>{{
                  eventMatchingSettingsForm.work_event_pattern || PATTERN_DISABLED_TEXT
                }}</code></p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.work_event_pattern"
                :examples="['Work', 'Work block', 'WFH', 'Team standup', 'Lunch with Sarah']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="school_event_pattern" class="mb-0">
              <template #label>School event regular expression <BBadge variant="secondary" class="align-middle">Boolean</BBadge></template>
              <RegexPatternInput id="school_event_pattern" v-model="eventMatchingSettingsForm.school_event_pattern" />
              <template #description>
                A match counts toward the "school" slice of the dashboard's time-breakdown widget
                and the /free calendar's own school category.
                Suggested: <RegexHighlightedCode :pattern="defaults.schoolEventPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('school_event_pattern', defaults.schoolEventPattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">Live preview — <code>{{
                  eventMatchingSettingsForm.school_event_pattern || PATTERN_DISABLED_TEXT
                }}</code></p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.school_event_pattern"
                :examples="['Chemistry class', 'School pickup', 'CLASS 4B', 'Team standup', 'Lunch with Sarah']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="highlight_clause_pattern" class="mb-0">
              <template #label>Highlight regular expression <BBadge variant="info" text="dark" class="align-middle">Capture</BBadge></template>
              <RegexPatternInput id="highlight_clause_pattern" v-model="eventMatchingSettingsForm.highlight_clause_pattern" :placeholder="defaults.highlightClausePattern" />
              <template #description>
                Same regex-body rules as above, but everything after "with"/"w/" is captured as a
                whole (to the end of the title), then split — using the name-split expression
                below — into individual names, each checked as a <em>substring</em> (not a
                whole-word match, and this comparison is case-<strong>sensitive</strong>) against
                a share link's own configured highlight words (set per-link, not here). "Dinner
                with Alice, Bob" checks both "Alice" and "Bob" individually. Any pattern
                configured under "Activity localizations" below also matches independently of this field
                (e.g. the classic "Host X"/"Visit X" convention, now just two example roles you
                can edit or remove) — see that section for details. Leave blank to fall back to
                the built-in default rather than turning matching off.
                Default: <RegexHighlightedCode :pattern="defaults.highlightClausePattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('highlight_clause_pattern', defaults.highlightClausePattern)">Use default</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{
                  eventMatchingSettingsForm.highlight_clause_pattern || defaults.highlightClausePattern
                }}</code>
                <br><span class="text-muted">(against sample configured words "Alice", "Bob")</span>
              </p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.highlight_clause_pattern || defaults.highlightClausePattern"
                :examples="['Dinner with Alice', 'Call w/ Bob', 'Team sync', 'Dinner with Charlie, Alice, Bob']"
                :sample-words="['Alice', 'Bob']"
                :split-pattern="eventMatchingSettingsForm.highlight_split_pattern || defaults.highlightSplitPattern"
                mode="tokens"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="highlight_split_pattern" class="mb-0">
              <template #label>Highlight name-split expression <BBadge variant="primary" class="align-middle">Split</BBadge></template>
              <RegexPatternInput id="highlight_split_pattern" v-model="eventMatchingSettingsForm.highlight_split_pattern" :placeholder="defaults.highlightSplitPattern" />
              <template #description>
                A clause can name more than one person — this splits the Highlight field's own
                capture (e.g. "Alice, Bob" from "Dinner with Alice, Bob") into individual names
                before each is checked. Each resulting piece is always trimmed of surrounding
                whitespace, so the default (comma, ampersand, or slash — "Alice, Bob", "Alice &amp;
                Bob", and "Alice/Bob" all split the same way) doesn't care about spacing around
                whichever one shows up — override this only if you use a different separator
                entirely (e.g. <code>;\s*</code>). Leave blank to fall back to the built-in
                default rather than turning splitting off (a clause is always split on
                <em>something</em>).
                Default: <RegexHighlightedCode :pattern="defaults.highlightSplitPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('highlight_split_pattern', defaults.highlightSplitPattern)">Use default</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — splitting on
                <code>{{
                    eventMatchingSettingsForm.highlight_split_pattern || defaults.highlightSplitPattern
                  }}</code>
              </p>
              <PatternPreview
                pattern="(.+)"
                :examples="['Alicia, Bob', 'Cleo/Damien/Ed', 'Frank & George']"
                :split-pattern="eventMatchingSettingsForm.highlight_split_pattern || defaults.highlightSplitPattern"
                mode="split"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="activity_clause_pattern" class="mb-0">
              <template #label>Activity regular expression <BBadge variant="info" text="dark" class="align-middle">Capture</BBadge></template>
              <BAlert variant="warning" :model-value="true" class="small mb-2">
                <strong>If you set this, the activity itself — not just who an event is with —
                will be shown, but only to a viewer whose share link is already highlighting that
                event</strong> (i.e. someone actually mentioned in it, per the highlight clause
                above — not anyone else with a link to your calendar). E.g. "Dinner" from "Dinner
                with Alice" is shown only to Alice's own link. Leave it blank (the default) and
                nothing is ever extracted or shown, no matter how a matched event's title reads.
              </BAlert>
              <RegexPatternInput id="activity_clause_pattern" v-model="eventMatchingSettingsForm.activity_clause_pattern" />
              <template #description>
                A separate pattern from the highlight clause above — its capture group is the
                freetext <em>before</em> "with"/"w/" (e.g. "Dinner" in "Dinner with Alice"). Only
                ever applied to an event that already matched a highlight word, and only shown if
                the individual share link viewing it also has its own "show activity" option on
                (a link-level toggle, not here). Same regex-body rules as the fields above.
                Suggested (matches the highlight clause above):
                <RegexHighlightedCode :pattern="defaults.activityClausePattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('activity_clause_pattern', defaults.activityClausePattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{
                  eventMatchingSettingsForm.activity_clause_pattern || PATTERN_DISABLED_TEXT
                }}</code>
              </p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.activity_clause_pattern"
                :examples="['Dinner with Alice', 'Call w/ Bob', 'Team sync', 'Coffee then gym with Charlie, Daniel']"
                mode="extract"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="tentative_pattern" class="mb-0">
              <template #label>Tentative regular expression <BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></template>
              <RegexPatternInput id="tentative_pattern" v-model="eventMatchingSettingsForm.tentative_pattern" />
              <template #description>
                Same regex-body rules as above. An event whose title matches this (in addition to
                any calendar-provided "tentative" status) is shown to viewers as tentative — both
                its start and end are shown as unknown — and the matched text is stripped from the
                title used for pattern matching. A blank field turns title-based detection off
                entirely (calendar-provided "tentative" status still applies either way) — the
                suggested starting point matches a trailing <code>(?)</code>, e.g. "Maybe lunch
                (?)" &rarr; "Maybe lunch".
                Suggested: <RegexHighlightedCode :pattern="defaults.tentativePattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('tentative_pattern', defaults.tentativePattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{
                  eventMatchingSettingsForm.tentative_pattern || PATTERN_DISABLED_TEXT
                }}</code>
              </p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.tentative_pattern"
                :examples="['Maybe lunch (?)', 'Team standup', 'Coffee with Alice (?)', 'Workshop']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="open_end_pattern" class="mb-0">
              <template #label>Open-end regular expression <BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></template>
              <RegexPatternInput id="open_end_pattern" v-model="eventMatchingSettingsForm.open_end_pattern" />
              <template #description>
                For an event that's definitely happening but has no known end time (e.g. it runs
                until whenever it's over). Same regex-body rules as above; matched text is stripped
                the same way. A blank field turns this detection off entirely — the suggested
                starting point matches a trailing <code>(-?)</code>, e.g. "Dinner with Alice (-?)"
                &rarr; "Dinner", shown to viewers with a known start and an open end.
                Suggested: <RegexHighlightedCode :pattern="defaults.openEndPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('open_end_pattern', defaults.openEndPattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{
                  eventMatchingSettingsForm.open_end_pattern || PATTERN_DISABLED_TEXT
                }}</code>
              </p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.open_end_pattern"
                :examples="['Dinner (-?)', 'Team standup', 'Party (-?)', 'Workshop']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="open_start_pattern" class="mb-0">
              <template #label>Open-start regular expression <BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></template>
              <RegexPatternInput id="open_start_pattern" v-model="eventMatchingSettingsForm.open_start_pattern" />
              <template #description>
                Same idea as open-end above, for an event whose start time isn't known but which
                definitely ends by a known time. A blank field turns this detection off entirely —
                the suggested starting point matches a trailing <code>(?-)</code>, e.g. "Dinner
                with Alice (?-)" &rarr; "Dinner", shown to viewers with an open start and a known
                end. Suggested: <RegexHighlightedCode :pattern="defaults.openStartPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline ms-1" @click="setFormField('open_start_pattern', defaults.openStartPattern)">Use suggested</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{
                  eventMatchingSettingsForm.open_start_pattern || PATTERN_DISABLED_TEXT
                }}</code>
              </p>
              <PatternPreview
                :pattern="eventMatchingSettingsForm.open_start_pattern"
                :examples="['Dinner (?-)', 'Team standup', 'Party (?-)', 'Workshop']"
                mode="match"
              />
            </div>
          </div>
        </div>

      <template #footer>
        <BButton type="submit" variant="primary" :disabled="eventMatchingSettingsForm.processing">Save settings</BButton>
        <BButton variant="outline-secondary" class="ms-2" @click="eventMatchingSettingsForm.reset(...EVENT_MATCHING_FIELDS)">Reset</BButton>
      </template>
    </BCard>
  </form>
</template>
