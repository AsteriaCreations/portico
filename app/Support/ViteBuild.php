<?php

namespace App\Support;

/**
 * Whether a Vite entry is available: the dev server is running, or the last
 * `npm run build` produced it. A page whose entry isn't built yet (a fresh
 * install, or a deploy run with -SkipNpm) checks this instead of letting
 *
 * @vite throw.
 */
final class ViteBuild
{
    public static function has(string $entry): bool
    {
        if (is_file(public_path('hot'))) {
            return true;
        }

        $manifest = public_path('build/manifest.json');

        return is_file($manifest)
            && array_key_exists($entry, json_decode((string) file_get_contents($manifest), true) ?: []);
    }
}
