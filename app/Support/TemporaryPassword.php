<?php

namespace App\Support;

/**
 * A one-off password an admin reads out to a staff member after resetting
 * their account, e.g. "Maple-Otter-4172!". Two capitalised words, four
 * digits and a symbol: easy to say aloud and type, at least 15 characters,
 * and always passing Password::defaults() (length, mixed case, a number, a
 * symbol). Random per reset, so unlike a shared default nobody can guess it;
 * the account must choose its own at next sign-in anyway.
 */
final class TemporaryPassword
{
    // Every word at least 4 letters, so the result is always 15+ characters.
    private const WORDS = [
        'Amber', 'Anchor', 'Aspen', 'Badger', 'Birch', 'Canyon', 'Cedar', 'Cobalt',
        'Comet', 'Coral', 'Delta', 'Ember', 'Falcon', 'Fern', 'Fjord', 'Garnet',
        'Harbor', 'Hazel', 'Heron', 'Indigo', 'Juniper', 'Kestrel', 'Lagoon', 'Lantern',
        'Maple', 'Meadow', 'Nectar', 'Nimbus', 'Orbit', 'Otter', 'Pebble',
        'Pepper', 'Quartz', 'Raven', 'River', 'Saffron', 'Sequoia', 'Sparrow', 'Summit',
        'Thistle', 'Timber', 'Tundra', 'Velvet', 'Walnut', 'Willow', 'Yarrow', 'Zephyr',
    ];

    private const SYMBOLS = ['!', '#', '$', '%', '?', '@'];

    public static function generate(): string
    {
        $word = fn (): string => self::WORDS[random_int(0, count(self::WORDS) - 1)];

        return sprintf(
            '%s-%s-%04d%s',
            $word(),
            $word(),
            random_int(0, 9999),
            self::SYMBOLS[random_int(0, count(self::SYMBOLS) - 1)],
        );
    }
}
