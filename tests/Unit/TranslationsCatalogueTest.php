<?php

declare(strict_types=1);

use Spora\Plugins\Calendar\CalendarPlugin;

$catalogueDir = dirname(__DIR__, 2) . '/locales';
$catalogueFile = $catalogueDir . '/de.json';

it('declares a translations directory that holds the catalogue', function () use ($catalogueDir): void {
    $paths = (new CalendarPlugin())->translationsPaths();

    expect($paths)->toHaveCount(1);
    expect(is_dir($paths[0]))->toBeTrue();
    expect(realpath($paths[0]))->toBe(realpath($catalogueDir));
});

it('decodes locales/de.json into a non-empty map', function () use ($catalogueFile): void {
    expect(is_file($catalogueFile))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($catalogueFile), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();
    expect($decoded)->not->toBeEmpty();
    expect(array_is_list($decoded))->toBeFalse();
});

it('translates every entry instead of repeating the English key', function () use ($catalogueFile): void {
    /** @var array<string, string> $decoded */
    $decoded = json_decode((string) file_get_contents($catalogueFile), true, flags: JSON_THROW_ON_ERROR);

    foreach ($decoded as $source => $translation) {
        expect(trim($translation))->not->toBe('', "empty German value for: {$source}");
        expect($translation)->not->toBe($source, "untranslated German value for: {$source}");
    }
});
