<?php

use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Support\Facades\File;

// Filament's own stylesheet only ships its fi-* classes; the Tailwind
// utilities this app's Blade views use are compiled by the panel theme
// (resources/css/filament/admin/theme.css), which includes a class only if
// it appears in a file under one of the theme's @source paths. A view outside
// them gets no styling from its classes at all -- and for show/hide classes
// that means rendering things twice, as the check-in roster once did.

/**
 * @return array<int, string> the theme's @source paths, relative to the project root, without their globs
 */
function themeSourceRoots(): array
{
    preg_match_all("/@source '\\.\\.\\/\\.\\.\\/\\.\\.\\/\\.\\.\\/([^*']+)/", File::get(resource_path('css/filament/admin/theme.css')), $matches);

    return array_map(fn (string $path): string => rtrim($path, '/').'/', $matches[1]);
}

test('the panel theme scans every folder of panel views', function () {
    $roots = themeSourceRoots();
    $basePath = str_replace('\\', '/', base_path()).'/';

    expect($roots)->toContain('app/Filament/', 'resources/views/filament/', 'resources/views/components/');

    // Emails render in a mail client, not the panel; welcome.blade.php is
    // Laravel's public landing page with its own styles.
    $unscanned = collect(File::allFiles(resource_path('views')))
        ->map(fn (SplFileInfo $file): string => substr(str_replace('\\', '/', $file->getPathname()), strlen($basePath)))
        ->filter(fn (string $path): bool => str_ends_with($path, '.blade.php'))
        ->reject(fn (string $path): bool => str_starts_with($path, 'resources/views/emails/') || $path === 'resources/views/welcome.blade.php')
        ->reject(fn (string $path): bool => collect($roots)->contains(fn (string $root): bool => str_starts_with($path, $root)))
        ->values()
        ->all();

    expect($unscanned)->toBe([]);
});

test('the theme is only loaded once a build has produced it, so an unbuilt install keeps working', function () {
    $isBuilt = fn (): bool => (fn () => static::themeIsBuilt())->call(new AdminPanelProvider(app()));
    $publicPath = public_path();
    $scratch = storage_path('framework/testing/theme-'.uniqid());
    File::ensureDirectoryExists("{$scratch}/build");

    try {
        app()->usePublicPath($scratch);
        expect($isBuilt())->toBeFalse();

        File::put("{$scratch}/build/manifest.json", json_encode(['resources/css/app.css' => []]));
        expect($isBuilt())->toBeFalse();

        File::put("{$scratch}/build/manifest.json", json_encode(['resources/css/filament/admin/theme.css' => ['file' => 'assets/theme.css']]));
        expect($isBuilt())->toBeTrue();

        File::delete("{$scratch}/build/manifest.json");
        File::put("{$scratch}/hot", 'http://localhost:5173');
        expect($isBuilt())->toBeTrue();
    } finally {
        app()->usePublicPath($publicPath);
        File::deleteDirectory($scratch);
    }
});
