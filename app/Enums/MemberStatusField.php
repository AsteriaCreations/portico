<?php

namespace App\Enums;

enum MemberStatusField: string
{
    case Banned = 'banned';
    case Watchlist = 'watchlist';
    case Deceased = 'deceased';
    case MissingPaperwork = 'missing_paperwork';
    case Active = 'active';
}
