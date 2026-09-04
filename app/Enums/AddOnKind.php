<?php

namespace App\Enums;

/**
 * 'entry' marks the one protected add_ons row that plans/subscriptions
 * target to mean "the Regular/entry subscription" — see the add_ons
 * migration's own comment for why this exists instead of a nullable
 * add_on_id. Entry's fee logic never reads price/pricing fields off this
 * row; only the subscription-lookup side is unified through it. Every other
 * row is an ordinary 'addon'.
 */
enum AddOnKind: string
{
    case Entry = 'entry';
    case Addon = 'addon';
}
