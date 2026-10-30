<?php

namespace App\Support;

use Illuminate\Support\Str;

class SequelContext
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{series_title:?string,episode_number:?int,parent_blueprint_id:?int,previous_episodes:list<array{n:int,title:string,hook_or_angle?:string}>}
     */
    public static function normalize(array $input): array
    {
        $series = trim((string) ($input['series_title'] ?? ''));
        $series = $series !== '' ? Str::limit($series, 180, '') : null;

        $ep = $input['episode_number'] ?? null;
        $ep = is_numeric($ep) ? max(1, min(99, (int) $ep)) : null;

        $parentId = $input['parent_blueprint_id'] ?? null;
        $parentId = is_numeric($parentId) && (int) $parentId > 0 ? (int) $parentId : null;

        $previous = self::normalizeEpisodes($input['previous_episodes'] ?? []);

        return [
            'series_title' => $series,
            'episode_number' => $ep,
            'parent_blueprint_id' => $parentId,
            'previous_episodes' => $previous,
        ];
    }

    /**
     * Parse textarea / array / JSON string ke list episode.
     *
     * @param  mixed  $raw
     * @return list<array{n:int,title:string,hook_or_angle?:string}>
     */
    public static function normalizeEpisodes(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            // JSON array?
            if (str_starts_with($raw, '[')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return self::normalizeEpisodes($decoded);
                }
            }
            // Satu baris = satu episode
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $out = [];
            $auto = 1;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^(?:episode|ep)\s*(\d+)\s*[:.\-]?\s*(.+)$/iu', $line, $m)) {
                    $out[] = [
                        'n' => max(1, (int) $m[1]),
                        'title' => Str::limit(trim($m[2]), 160, ''),
                    ];
                } else {
                    $out[] = [
                        'n' => $auto,
                        'title' => Str::limit($line, 160, ''),
                    ];
                }
                $auto++;
            }

            return array_slice($out, 0, 20);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $auto = 1;
        foreach ($raw as $row) {
            if (is_string($row)) {
                $title = trim($row);
                if ($title === '') {
                    continue;
                }
                $out[] = ['n' => $auto, 'title' => Str::limit($title, 160, '')];
                $auto++;
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $row['label'] ?? ''));
            if ($title === '') {
                continue;
            }
            $n = isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : $auto;
            $item = [
                'n' => max(1, $n),
                'title' => Str::limit($title, 160, ''),
            ];
            $hook = trim((string) ($row['hook_or_angle'] ?? $row['hook'] ?? $row['angle'] ?? ''));
            if ($hook !== '') {
                $item['hook_or_angle'] = Str::limit($hook, 240, '');
            }
            $out[] = $item;
            $auto = max($auto, $n) + 1;
        }

        return array_slice($out, 0, 20);
    }

    /**
     * Blok teks untuk prompt Gemini.
     *
     * @param  array{series_title:?string,episode_number:?int,parent_blueprint_id:?int,previous_episodes?:list<array{n:int,title:string,hook_or_angle?:string}>}  $sequel
     */
    public static function promptBlock(array $sequel): string
    {
        $series = trim((string) ($sequel['series_title'] ?? ''));
        $ep = $sequel['episode_number'] ?? null;
        $prev = $sequel['previous_episodes'] ?? [];
        if ($series === '' && $ep === null && $prev === []) {
            return '';
        }

        $lines = [
            'KONTEKS SEQUEL / SERIAL (WAJIB dihormati — jangan mengulang episode lama):',
        ];
        if ($series !== '') {
            $lines[] = 'Judul serial: '.$series;
        }
        if ($ep !== null) {
            $lines[] = 'Episode yang sedang ditulis: Episode '.$ep;
            $lines[] = 'Judul kerja / cover sebaiknya format: "Episode '.$ep.': [taktik/tema BARU]"';
        }
        if ($prev !== []) {
            $lines[] = 'Episode yang SUDAH TAYANG (dilarang mengulang taktik/judul yang sama):';
            foreach ($prev as $row) {
                $hook = trim((string) ($row['hook_or_angle'] ?? ''));
                $lines[] = '- Ep'.($row['n'] ?? '?').': '.($row['title'] ?? '-')
                    .($hook !== '' ? ' | sudut: '.$hook : '');
            }
        }
        $lines[] = 'Aturan: escalate ke taktik/sudut BARU dalam serial yang sama; boleh referensi singkat serial di hook, tapi VO & konsep harus mandiri; jangan daur ulang Frame Control / Wajah Serigala / Repetisi jika sudah ada di daftar atas.';

        return implode("\n", $lines);
    }

    /**
     * Apakah sequel aktif (ada serial atau riwayat).
     *
     * @param  array<string, mixed>  $sequel
     */
    public static function isActive(array $sequel): bool
    {
        return trim((string) ($sequel['series_title'] ?? '')) !== ''
            || ($sequel['episode_number'] ?? null) !== null
            || (($sequel['previous_episodes'] ?? []) !== []);
    }
}
