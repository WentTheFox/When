<?php

namespace App\Support;

/**
 * The owner's own chosen preference for `users.calendar_parsing_mode` —
 * deliberately a strict binary (see remove_auto_from_calendar_parsing_
 * mode's own migration comment for why "auto" was dropped), unlike
 * App\Domain\Calendar\FeedMode's three cases (FullDetail/FreeBusyOnly/
 * Mixed), which is FeedClassifier's own *detected* shape of an actual ICS
 * feed and can genuinely be "mixed". Kept as its own enum rather than
 * reusing FeedMode so a validation rule here can never accidentally
 * accept "mixed" as an owner preference again.
 *
 * Same reasoning as ColorSwatchKey/IconKey/NowColorPresetKey for being a
 * backed enum at all: SettingsController/CalendarPreviewController
 * validate this column with Rule::enum(self::class) — the PHP enum is
 * the only source of truth (see drop_stale_enum_check_constraints's own
 * migration comment for why there's deliberately no DB-level CHECK/enum
 * type duplicating it).
 */
enum CalendarParsingMode: string
{
    case FullDetail = 'full_detail';
    case FreeBusyOnly = 'free_busy_only';
}
