<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use InvalidArgumentException;

/** A span-based SII reader: unknown fields, comments and numeric lexemes survive edits. */
final class SiiDocument
{
    /** Packed columns avoid one PHP hash table per token. */
    private array $texts = [];

    private array $starts = [];

    private array $ends = [];

    private array $units = [];

    private array $info;

    private array $order = [];

    private array $modIds = [];

    private array $mods = [];

    private ?array $metadata = null;

    public function __construct(private string $source)
    {
        $this->lex();
        $i = 0;
        $this->expect($i, 'SiiNunit');
        $this->expect($i, '{');
        while (($this->texts[$i] ?? null) !== '}') {
            $start = $this->token($i)['start'];
            $type = $this->word($i);
            $this->expect($i, ':');
            $id = $this->word($i);
            if (isset($this->units[$id])) {
                $this->fail('Duplicate unit ID.');
            }
            $this->expect($i, '{');
            $fields = [];
            while (($this->texts[$i] ?? null) !== '}') {
                $keyToken = $this->token($i);
                $key = $this->word($i);
                if (isset($fields[$key])) {
                    $this->fail('Duplicate field: '.$key);
                }
                $this->expect($i, ':');
                $first = $i;
                while (isset($this->texts[$i]) && $this->texts[$i] !== '}'
                    && ! (($this->texts[$i + 1] ?? null) === ':' && $i > $first)) {
                    if (in_array($this->texts[$i], ['{', ':'], true)) {
                        $this->fail('Invalid field value.');
                    }
                    $i++;
                }
                if ($i === $first) {
                    $this->fail('Empty field value.');
                }
                $a = $this->starts[$first];
                $b = $this->ends[$i - 1];
                $fields[$key] = ['start' => $keyToken['start'], 'valueStart' => $a, 'end' => $b,
                    'raw' => substr($source, $a, $b - $a)];
                if ($type === 'server_packages_info' && $key === 'mod_list'
                    && preg_match('/^(0|[1-9][0-9]{0,4})$/D', $fields[$key]['raw'])
                    && (int) $fields[$key]['raw'] > ModLimits::MAX) {
                    $this->fail('Packages may contain at most '.ModLimits::MAX.' mods.');
                }
            }
            $end = $this->token($i)['end'];
            $this->expect($i, '}');
            $this->units[$id] = compact('type', 'id', 'start', 'end', 'fields');
        }
        $this->expect($i, '}');
        if ($i !== count($this->texts)) {
            $this->fail('Unexpected data after SiiNunit.');
        }
        $infos = array_filter($this->units, fn ($unit) => $unit['type'] === 'server_packages_info');
        if (count($infos) !== 1) {
            $this->fail('Exactly one server_packages_info unit is required.');
        }
        $this->info = array_values($infos)[0];
        if ($this->raw($this->info, 'version') !== '1') {
            $this->fail('Unsupported server package version.');
        }
        $count = $this->raw($this->info, 'mod_list');
        if (! preg_match('/^(0|[1-9][0-9]{0,4})$/D', $count)) {
            $this->fail('Invalid mod count.');
        }
        if ((int) $count > ModLimits::MAX) {
            $this->fail('Packages may contain at most '.ModLimits::MAX.' mods.');
        }
        $indexed = array_filter(array_keys($this->info['fields']), fn ($key) => str_starts_with($key, 'mod_list['));
        if (count($indexed) !== (int) $count) {
            $this->fail('Mod count does not match the indices.');
        }
        for ($n = 0; $n < (int) $count; $n++) {
            $id = $this->raw($this->info, 'mod_list['.$n.']');
            if (! isset($this->units[$id]) || $this->units[$id]['type'] !== 'server_mod_detail' || isset($this->modIds[$id])) {
                $this->fail('Missing or duplicate mod reference: '.$id);
            }
            $this->order[] = $id;
            $this->modIds[$id] = true;
            $this->mods[$id] = $this->mod($id);
        }
        self::scalar($this->raw($this->info, 'map_name'));
        // Parsing is complete; edits only need the preserved spans and source.
        $this->texts = $this->starts = $this->ends = [];
    }

    public function metadata(): array
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }
        $mods = array_map(fn ($id) => $this->mods[$id], array_reverse($this->order));
        $stamp = $this->info['fields']['time_stamp']['raw'] ?? null;

        return $this->metadata = [
            'mod_count' => count($mods),
            'workshop_count' => count(array_filter($mods, fn ($mod) => $mod['workshop_mod'])),
            'map_name' => self::scalar($this->raw($this->info, 'map_name')),
            'exported_at' => $stamp !== null && preg_match('/^[0-9]{1,10}$/D', $stamp) ? gmdate('c', (int) $stamp) : null,
            'mods' => $mods,
        ];
    }

    /** The supplied list is UI order: highest priority first. */
    public function edit(array $visibleIds, bool $allowRemoval): string
    {
        return $this->editDocument($visibleIds, $allowRemoval)->source;
    }

    public function editDocument(array $visibleIds, bool $allowRemoval): self
    {
        if (count($visibleIds) > ModLimits::MAX) {
            $this->fail('Packages may contain at most '.ModLimits::MAX.' mods.');
        }
        if (count(array_unique($visibleIds, SORT_STRING)) !== count($visibleIds)) {
            $this->fail('Duplicate mods in the load order.');
        }
        foreach ($visibleIds as $id) {
            if (! is_string($id) || ! isset($this->modIds[$id])) {
                $this->fail('Unknown mod ID.');
            }
        }
        $removed = array_diff($this->order, $visibleIds);
        if ($removed && ! $allowRemoval) {
            $this->fail('Experimental mod removal requires explicit confirmation.');
        }
        $field = $this->info['fields']['mod_list'];
        $eol = str_contains($this->source, "\r\n") ? "\r\n" : "\n";
        $list = 'mod_list: '.count($visibleIds);
        foreach (array_reverse($visibleIds) as $n => $id) {
            $list .= $eol.' mod_list['.$n.']: '.$id;
        }
        $patches = [[$field['start'], $field['end'], $list]];
        foreach ($this->info['fields'] as $key => $value) {
            if (str_starts_with($key, 'mod_list[')) {
                $patches[] = [$value['start'], $value['end'], ''];
            }
        }
        foreach ($removed as $id) {
            $unit = $this->units[$id];
            $patches[] = [$unit['start'], $unit['end'], ''];
        }
        usort($patches, fn ($a, $b) => $a[0] <=> $b[0]);
        $parts = [];
        $offset = 0;
        foreach ($patches as [$start, $end, $replacement]) {
            $parts[] = substr($this->source, $offset, $start - $offset);
            $parts[] = $replacement;
            $offset = $end;
        }
        $parts[] = substr($this->source, $offset);

        return new self(implode('', $parts));
    }

    public function source(): string
    {
        return $this->source;
    }

    private function mod(string $id): array
    {
        $unit = $this->units[$id];
        $workshop = $this->raw($unit, 'workshop_mod');
        $optional = $this->raw($unit, 'optional_mod');
        $modId = $this->raw($unit, 'mod_id');
        $checksum = $this->raw($unit, 'checksum');
        if (! in_array($workshop, ['true', 'false'], true) || ! in_array($optional, ['true', 'false'], true)
            || ! self::uint64($modId) || ! self::uint64($checksum)) {
            $this->fail('Invalid mod metadata.');
        }

        return ['id' => $id, 'mod_name' => self::scalar($this->raw($unit, 'mod_name')),
            'package_name' => self::scalar($this->raw($unit, 'package_name')), 'mod_id' => $modId,
            'checksum' => $checksum, 'workshop_mod' => $workshop === 'true', 'optional_mod' => $optional === 'true',
            'workshop_url' => $workshop === 'true' ? 'https://steamcommunity.com/sharedfiles/filedetails/?id='.$modId : null];
    }

    private static function uint64(string $value): bool
    {
        return preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }

    private function raw(array $unit, string $key): string
    {
        return $unit['fields'][$key]['raw'] ?? throw new InvalidArgumentException('Missing field: '.$key);
    }

    public static function scalar(string $raw): string
    {
        if (str_starts_with($raw, '"')) {
            if (! str_ends_with($raw, '"')) {
                throw new InvalidArgumentException('Invalid string.');
            }
            $value = preg_replace_callback('/\\\\(x[0-9a-fA-F]{2}|[\\\\"nrt])/', static fn ($m) => str_starts_with($m[1], 'x') ? chr(hexdec(substr($m[1], 1))) : match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", default => $m[1],
            }, substr($raw, 1, -1));
        } else {
            if (! preg_match('/^[^\s{}:,()"]+$/D', $raw)) {
                throw new InvalidArgumentException('Invalid string value.');
            }
            $value = $raw;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    private function lex(): void
    {
        $offset = 0;
        $length = strlen($this->source);
        if (str_starts_with($this->source, "\xEF\xBB\xBF")) {
            $offset = 3;
        }
        while ($offset < $length) {
            if (! preg_match('~\G(?:\s+|//[^\r\n]*|/\*.*?\*/|"(?:[^"\\\\]|\\\\.)*"|[{}:(),]|[^\s{}:(),"]+)~s', $this->source, $m, 0, $offset)) {
                $this->fail('Invalid SII token.');
            }
            $text = $m[0];
            $end = $offset + strlen($text);
            if (str_starts_with($text, '/*') && ! str_ends_with($text, '*/')) {
                $this->fail('Unterminated comment.');
            }
            if (! ctype_space($text) && ! str_starts_with($text, '//') && ! str_starts_with($text, '/*')) {
                if ($text[0] === '"' && ! str_ends_with($text, '"')) {
                    $this->fail('Unterminated string.');
                }
                $this->texts[] = $text;
                $this->starts[] = $offset;
                $this->ends[] = $end;
            }
            $offset = $end;
        }
    }

    private function token(int $i): array
    {
        if (! isset($this->texts[$i])) {
            throw new InvalidArgumentException('Incomplete SII document.');
        }

        return ['text' => $this->texts[$i], 'start' => $this->starts[$i], 'end' => $this->ends[$i]];
    }

    private function word(int &$i): string
    {
        $text = $this->texts[$i++] ?? throw new InvalidArgumentException('Incomplete SII document.');
        if (! preg_match('/^[a-zA-Z0-9_.\[\]-]+$/D', $text)) {
            $this->fail('Invalid unit or field name.');
        }

        return $text;
    }

    private function expect(int &$i, string $text): void
    {
        $actual = $this->texts[$i++] ?? throw new InvalidArgumentException('Incomplete SII document.');
        if ($actual !== $text) {
            $this->fail('SII syntax error; expected: '.$text);
        }
    }

    private function fail(string $message): never
    {
        throw new InvalidArgumentException($message);
    }
}
