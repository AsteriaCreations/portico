<?php

use App\Filament\Concerns\TranslatesPageLabels;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Concerns\TranslatesResourceLabels;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\File;

// Guards the localization convention in CONTRIBUTING.md so a new screen
// can't quietly ship English-only text. Labels are exempt from the literal
// scan: a global translateLabel() default in AppServiceProvider already
// sends every field/column/filter/action label through the translator.

/**
 * Class names of the PHP files under app/{directory} whose file name ends in $suffix.
 *
 * @return array<int, class-string>
 */
function guardClassesIn(string $directory, string $suffix = '.php'): array
{
    $appPath = str_replace('\\', '/', app_path()).'/';

    return collect(File::allFiles(app_path($directory)))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), $suffix))
        ->map(fn (SplFileInfo $file): string => 'App\\'.str_replace(
            '/',
            '\\',
            substr(str_replace('\\', '/', $file->getPathname()), strlen($appPath), -4),
        ))
        ->values()
        ->all();
}

/**
 * @param  array<int, class-string>  $classes
 * @return array<int, class-string>
 */
function guardClassesMissingTrait(array $classes, string $trait): array
{
    return collect($classes)
        ->reject(fn (string $class): bool => in_array($trait, class_uses_recursive($class), true))
        ->values()
        ->all();
}

test('every Resource uses the translated-label trait', function () {
    $resources = collect(guardClassesIn('Filament/Admin/Resources', 'Resource.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Resource::class))
        ->all();

    expect($resources)->not->toBeEmpty()
        ->and(guardClassesMissingTrait($resources, TranslatesResourceLabels::class))->toBe([]);
});

test('every relation manager uses the translated-title trait', function () {
    $managers = collect(guardClassesIn('Filament/Admin/Resources'))
        ->filter(fn (string $class): bool => is_subclass_of($class, RelationManager::class))
        ->all();

    expect($managers)->not->toBeEmpty()
        ->and(guardClassesMissingTrait($managers, TranslatesRelationManagerTitle::class))->toBe([]);
});

test('every admin page uses the translated-label trait', function () {
    $pages = guardClassesIn('Filament/Admin/Pages');

    expect($pages)->not->toBeEmpty()
        ->and(guardClassesMissingTrait($pages, TranslatesPageLabels::class))->toBe([]);
});

test('no user-facing prose string is left as an untranslated literal', function () {
    // A method call whose FIRST argument is a plain string literal containing a
    // letter (so a bare "—" placeholder is fine). __() / trans_choice() calls
    // start with a function name, not a quote, so they never match.
    $proseMethod = '->(?:helperText|placeholder|tooltip|description|heading|modalHeading|modalDescription|modalSubmitActionLabel|hint|title|body|emptyStateHeading|emptyStateDescription)\(\s*[\'"][^\'"]*[A-Za-z]';
    $factoryHeading = '(?:Section|Fieldset|Tab)::make\(\s*[\'"]';
    $validationFailure = '\$fail\(\s*[\'"]';
    $pattern = '/'.$proseMethod.'|'.$factoryHeading.'|'.$validationFailure.'/';

    $basePath = str_replace('\\', '/', base_path()).'/';
    $offenders = [];

    foreach (['Filament', 'Observers', 'Services', 'Mail'] as $directory) {
        foreach (File::allFiles(app_path($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $lines = explode("\n", $contents);

            // Whole-file match (not line by line) so a string literal on the line
            // after "->helperText(" is caught too.
            preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$match, $offset]) {
                $number = substr_count($contents, "\n", 0, $offset);

                if (preg_match('#^\s*(//|\*|/\*)#', $lines[$number])) {
                    continue;
                }

                $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($basePath));
                $offenders[] = $relative.':'.($number + 1).'  '.trim($lines[$number]);
            }
        }
    }

    expect($offenders)->toBe([]);
});
