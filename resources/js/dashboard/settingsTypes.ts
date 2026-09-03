/**
 * Shared shape for the dashboard Settings page — split out so every
 * SettingsXxxCard.vue can type its own `form` prop against the exact same
 * fields Settings.vue's own single useForm() instance actually has,
 * without each card redeclaring (and risking drifting out of sync with)
 * its own copy of this interface.
 */
export interface Settings {
  /** Null until the owner's first Settings save — see Settings.vue's own autodetect-on-load fallback. Every server-side reader of a still-null value falls back to 'UTC' itself (e.g. DashboardController, RecomputeShareLinkAvailability). */
  timezone: string | null;
  /** 0=Sunday..6=Saturday, date-fns' own weekStartsOn convention. */
  week_start: number;
  dnd_event_pattern: string | null;
  dnd_event_pattern_preview: string | null;
  nap_event_pattern: string | null;
  nap_event_pattern_preview: string | null;
  work_event_pattern: string | null;
  work_event_pattern_preview: string | null;
  school_event_pattern: string | null;
  school_event_pattern_preview: string | null;
  calendar_parsing_mode: 'full_detail' | 'free_busy_only';
  highlight_clause_pattern: string | null;
  highlight_clause_pattern_preview: string | null;
  highlight_split_pattern: string | null;
  highlight_split_pattern_preview: string | null;
  activity_clause_pattern: string | null;
  activity_clause_pattern_preview: string | null;
  tentative_pattern: string | null;
  tentative_pattern_preview: string | null;
  open_end_pattern: string | null;
  open_end_pattern_preview: string | null;
  open_start_pattern: string | null;
  open_start_pattern_preview: string | null;
  public_page_title: Record<string, string> | null;
  name: string;
  accent_color_key: string | null;
  secondary_color_key: string | null;
  sleep_color_key: string | null;
  busy_color_key: string | null;
  work_color_key: string | null;
  school_color_key: string | null;
  free_color_key: string | null;
  highlight_color_key: string | null;
  free_icon_key: string | null;
  busy_icon_key: string | null;
  work_icon_key: string | null;
  school_icon_key: string | null;
  sleep_icon_key: string | null;
  highlight_icon_key: string | null;
  now_color_key: string | null;
  availability: Record<number, { wake: string | null; sleep: string | null }>;
}

/** Settings.vue's own `defaults` prop — suggested/fallback pattern values, keyed camelCase (matches SettingsController::edit()'s own payload shape). */
export interface SettingsDefaults {
  dndEventPattern: string;
  napEventPattern: string;
  workEventPattern: string;
  schoolEventPattern: string;
  highlightClausePattern: string;
  highlightSplitPattern: string;
  activityClausePattern: string;
  tentativePattern: string;
  openEndPattern: string;
  openStartPattern: string;
}
