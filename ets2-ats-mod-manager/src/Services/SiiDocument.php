<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use InvalidArgumentException;

/** A span-based SII reader: unknown fields, comments and numeric lexemes survive edits. */
final class SiiDocument
{
    private array $tokens = [];

    private array $units = [];

    private array $info;

    private array $order = [];

    public function __construct(private string $source)
    {
        $this->lex();
        $i = 0;
        $this->expect($i, 'SiiNunit');
        $this->expect($i, '{');
        while (($this->tokens[$i]['text'] ?? null) !== '}') {
            $start = $this->token($i)['start'];
            $type = $this->word($i);
            $this->expect($i, ':');
            $id = $this->word($i);
            if (isset($this->units[$id])) {
                $this->fail('Duplicate unit ID.');
            }
            $this->expect($i, '{');
            $fields = [];
            while (($this->tokens[$i]['text'] ?? null) !== '}') {
                $keyToken = $this->token($i);
                $key = $this->word($i);
                if (isset($fields[$key])) {
                    $this->fail('Duplicate field: '.$key);
                }
                $this->expect($i, ':');
                $first = $i;
                while (isset($this->tokens[$i]) && $this->tokens[$i]['text'] !== '}'
                    && ! (($this->tokens[$i + 1]['text'] ?? null) === ':' && $i > $first)) {
                    if (in_array($this->tokens[$i]['text'], ['{', ':'], true)) {
                        $this->fail('Invalid field value.');
                    }
                    $i++;
                }
                if ($i === $first) {
                    $this->fail('Empty field value.');
                }
                $a = $this->tokens[$first]['start'];
                $b = $this->tokens[$i - 1]['end'];
                $fields[$key] = ['start' => $keyToken['start'], 'valueStart' => $a, 'end' => $b,
                    'raw' => substr($source, $a, $b - $a)];
            }
            $end = $this->token($i)['end'];
            $this->expect($i, '}');
            $this->units[$id] = compact('type', 'id', 'start', 'end', 'fields');
        }
        $this->expect($i, '}');
        if ($i !== count($this->tokens)) {
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
        if (! preg_match('/^(0|[1-9][0-9]{0,4})$/D', $count) || (int) $count > 10000) {
            $this->fail('Invalid mod count.');
        }
        $indexed = array_filter(array_keys($this->info['fields']), fn ($key) => str_starts_with($key, 'mod_list['));
        if (count($indexed) !== (int) $count) {
            $this->fail('Mod count does not match the indices.');
        }
        for ($n = 0; $n < (int) $count; $n++) {
            $id = $this->raw($this->info, 'mod_list['.$n.']');
            if (! isset($this->units[$id]) || $this->units[$id]['type'] !== 'server_mod_detail' || in_array($id, $this->order, true)) {
                $this->fail('Missing or duplicate mod reference: '.$id);
            }
            $this->order[] = $id;
            $this->mod($id);
        }
        self::scalar($this->raw($this->info, 'map_name'));
    }

    public function metadata(): array
    {
        $mods = array_map($this->mod(...), array_reverse($this->order));
        $stamp = $this->info['fields']['time_stamp']['raw'] ?? null;

        return [
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
        if (count(array_unique($visibleIds, SORT_STRING)) !== count($visibleIds)) {
            $this->fail('Duplicate mods in the load order.');
        }
        foreach ($visibleIds as $id) {
            if (! is_string($id) || ! in_array($id, $this->order, true)) {
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
        usort($patches, fn ($a, $b) => $b[0] <=> $a[0]);
        $result = $this->source;
        foreach ($patches as [$start, $end, $replacement]) {
            $result = substr_replace($result, $replacement, $start, $end - $start);
        }
        new self($result);

        return $result;
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
                $this->tokens[] = ['text' => $text, 'start' => $offset, 'end' => $end];
            }
            $offset = $end;
        }
    }

    private function token(int $i): array
    {
        return $this->tokens[$i] ?? throw new InvalidArgumentException('Incomplete SII document.');
    }

    private function word(int &$i): string
    {
        $text = $this->token($i++)['text'];
        if (! preg_match('/^[a-zA-Z0-9_.\[\]-]+$/D', $text)) {
            $this->fail('Invalid unit or field name.');
        }

        return $text;
    }

    private function expect(int &$i, string $text): void
    {
        if ($this->token($i++)['text'] !== $text) {
            $this->fail('SII syntax error; expected: '.$text);
        }
    }

    private function fail(string $message): never
    {
        throw new InvalidArgumentException($message);
    }
}
