<?php

use Illuminate\Support\Facades\File;

// The admin panel only ships Filament's own compiled CSS -- there's no
// Tailwind build for this app's Blade views -- so a Tailwind utility Filament
// doesn't itself use simply does nothing. For show/hide utilities that's
// worse than cosmetic: `sm:hidden` next to `hidden sm:block` renders BOTH
// layouts, which is how the Check-In Desk's roster listed everyone twice.
// Scope a <style> block in the component instead (see checked-in-roster, and
// CheckedInRosterTest for its render check).

test('no panel view relies on Tailwind show/hide utilities the panel CSS lacks', function () {
    // What shows or hides markup: `hidden`, or any display utility behind a
    // breakpoint. A bare `flex`/`grid`/`block` isn't a show/hide switch, so
    // it's out of scope here.
    $visibility = '(?:hidden|(?:sm|md|lg|xl|2xl):(?:hidden|block|inline|inline-block|flex|inline-flex|grid|table|contents))';
    $pattern = '/\bclass="[^"]*(?<![\w:-])'.$visibility.'(?![\w-])[^"]*"/';

    $compiledCss = File::get(public_path('css/filament/filament/app.css'));
    $basePath = str_replace('\\', '/', base_path()).'/';
    $offenders = [];

    foreach (['filament', 'components', 'emails'] as $directory) {
        foreach (File::allFiles(resource_path("views/{$directory}")) as $file) {
            $lines = explode("\n", File::get($file->getPathname()));

            foreach ($lines as $number => $line) {
                if (! preg_match_all($pattern, $line, $matches)) {
                    continue;
                }

                preg_match_all('/(?<![\w:-])'.$visibility.'(?![\w-])/', implode(' ', $matches[0]), $classes);

                foreach ($classes[0] as $class) {
                    // Filament may happen to ship a plain utility; only flag
                    // what the compiled CSS genuinely doesn't define.
                    if (! str_contains($compiledCss, '.'.str_replace(':', '\\:', $class).'{')) {
                        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($basePath));
                        $offenders[] = "{$relative}:".($number + 1)."  {$class}";
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
