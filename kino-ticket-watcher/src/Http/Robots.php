<?php
declare(strict_types=1);

namespace Kino\Http;

/**
 * Minimaler robots.txt-Parser: nur User-agent, Allow, Disallow.
 * Laengster passender Pfad gewinnt, bei Gleichstand gewinnt Allow -- so
 * beschreibt es der Standard, und so verhaelt sich auch Google.
 */
final class Robots
{
    /** @var array<string, list<array{0:bool,1:string}>> */
    private array $groups = [];

    public function __construct(string $robotsTxt)
    {
        $current = [];
        foreach (preg_split('/\R/', $robotsTxt) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                $current = [strtolower($value)];
                $this->groups[$current[0]] ??= [];
                continue;
            }
            if ($field !== 'allow' && $field !== 'disallow') {
                continue;
            }
            foreach ($current as $agent) {
                $this->groups[$agent][] = [$field === 'allow', $value];
            }
        }
    }

    public function allows(string $path, string $agent = '*'): bool
    {
        $rules = $this->groups[strtolower($agent)] ?? $this->groups['*'] ?? [];
        $best = null;
        foreach ($rules as [$isAllow, $pattern]) {
            if ($pattern === '') {
                // "Disallow:" ohne Wert heisst ausdruecklich: alles erlaubt.
                continue;
            }
            if (!$this->matches($path, $pattern)) {
                continue;
            }
            $len = strlen($pattern);
            if ($best === null || $len > $best[1] || ($len === $best[1] && $isAllow)) {
                $best = [$isAllow, $len];
            }
        }
        return $best === null ? true : $best[0];
    }

    private function matches(string $path, string $pattern): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = rtrim($pattern, '$');
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';
        return (bool)preg_match($regex, $path);
    }
}
