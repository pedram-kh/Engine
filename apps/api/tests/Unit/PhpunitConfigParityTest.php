<?php

declare(strict_types=1);

/*
 * AH-119 A0. phpunit.pgsql.xml (the Postgres leg, `composer test:pg`) is a copy of phpunit.xml
 * that differs ONLY in the database env lines. Without this, the two configs drift (a new
 * pinned env in one and not the other) and the Postgres leg quietly runs a different app.
 */

/**
 * @return array{env: array<string, string>, rest: string}
 */
function phpunitConfigShape(string $file): array
{
    $dom = new DOMDocument;
    $dom->load(dirname(__DIR__, 2).'/'.$file);

    $env = [];
    foreach (iterator_to_array($dom->getElementsByTagName('env')) as $node) {
        $name = $node->getAttribute('name');
        if (str_starts_with($name, 'DB_')) {
            continue;
        }
        $env[$name] = $node->getAttribute('value').'|'.$node->getAttribute('force');
    }
    ksort($env);

    foreach (iterator_to_array($dom->getElementsByTagName('php')) as $php) {
        $php->parentNode?->removeChild($php);
    }

    return ['env' => $env, 'rest' => (string) $dom->saveXML($dom->documentElement)];
}

it('keeps phpunit.pgsql.xml identical to phpunit.xml apart from the DB_* lines', function (): void {
    $sqlite = phpunitConfigShape('phpunit.xml');
    $pgsql = phpunitConfigShape('phpunit.pgsql.xml');

    expect($pgsql['env'])->toBe($sqlite['env'])
        ->and(preg_replace('/\s+/', ' ', $pgsql['rest']))->toBe(preg_replace('/\s+/', ' ', $sqlite['rest']));
});

it('forces pgsql in the Postgres config and sqlite in the default one', function (): void {
    $xml = fn (string $f): string => (string) file_get_contents(dirname(__DIR__, 2).'/'.$f);

    expect($xml('phpunit.pgsql.xml'))->toContain('name="DB_CONNECTION" value="pgsql" force="true"')
        ->and($xml('phpunit.xml'))->toContain('name="DB_CONNECTION" value="sqlite"');
});
