<?php

namespace App\Domain\Calendar;

enum FeedMode: string
{
    case FullDetail = 'full_detail';
    case FreeBusyOnly = 'free_busy_only';
    case Mixed = 'mixed';
}
