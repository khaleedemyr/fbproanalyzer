<?php

namespace App\Services\Angle;

use App\Models\AngleFinding;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AngleFinderService
{
    protected ?string $lastWarning = null;

    protected string $lastEngine = 'template';

    public function lastWarning(): ?string
    {
        return $this->lastWarning;
    }

    public function lastEngine(): string
    {
        return $this->lastEngine;
    }

    public function analyze(User $user, string $topic): AngleFinding
    {
        $this->lastWarning = null;
        $this->lastEngine = 'template';

        $topic = trim($topic) !== '' ? trim($topic) : 'psikologi toxic relationship';
        $apiKey = trim((string) config('radar.youtube_api_key', ''), " \t\n\r\0\x0B\"'");

        if ($apiKey === '') {
            $this->lastWarning = 'YOUTUBE_API_KEY belum terbaca. Angle Finder butuh YouTube Data API resmi.';

            return $this->persist($user, $topic, [
                'videos' => [],
                'comments' => [],
                'pain_points' => [],
                'angles' => $this->fallbackAngles($topic, []),
                'summary' => 'Tidak bisa ambil komentar karena YouTube API key kosong.',
            ]);
        }

        try {
            $videos = $this->searchPopularVideos($topic, $apiKey);
        } catch (\Throwable $e) {
            $this->lastWarning = 'Gagal cari video YouTube: '.$e->getMessage();
            Log::warning('AngleFinder search: '.$e->getMessage());

            return $this->persist($user, $topic, [
                'videos' => [],
                'comments' => [],
                'pain_points' => [],
                'angles' => $this->fallbackAngles($topic, []),
                'summary' => 'Pencarian video gagal.',
            ]);
        }

        $allComments = [];
        foreach (array_slice($videos, 0, 5) as $video) {
            try {
                $comments = $this->fetchComments((string) $video['video_id'], $apiKey, 40);
                foreach ($comments as $c) {
                    $allComments[] = [
                        'text' => $c,
                        'video_title' => $video['title'],
                        'video_url' => $video['url'],
                    ];
                }
                $video['comment_sample_count'] = count($comments);
            } catch (\Throwable $e) {
                $video['comment_error'] = $e->getMessage();
                Log::info('AngleFinder comments skip '.$video['video_id'].': '.$e->getMessage());
            }
        }

        if ($allComments === []) {
            $this->lastWarning = ($this->lastWarning ? $this->lastWarning.' · ' : '')
                .'Belum ada komentar terbaca (video mungkin disable komentar / kuota). Pakai angle template.';
        }

        $analyzed = $this->analyzeWithGemini($topic, $videos, $allComments);
        if ($analyzed === null) {
            $analyzed = $this->analyzeWithTemplate($topic, $allComments);
            $this->lastEngine = 'template';
            if ($this->lastWarning === null && $allComments !== []) {
                $this->lastWarning = 'Gemini tidak tersedia — ringkasan komentar pakai template heuristik.';
            }
        } else {
            $this->lastEngine = 'gemini';
        }

        return $this->persist($user, $topic, [
            'videos' => $videos,
            'comments' => array_slice($allComments, 0, 24),
            'pain_points' => $analyzed['pain_points'],
            'angles' => $analyzed['angles'],
            'summary' => $analyzed['summary'],
        ]);
    }

    /**
     * @param  array{videos:array,comments:array,pain_points:array,angles:array,summary:string}  $payload
     */
    protected function persist(User $user, string $topic, array $payload): AngleFinding
    {
        return AngleFinding::query()->create([
            'user_id' => $user->id,
            'topic' => Str::limit($topic, 180, ''),
            'engine' => $this->lastEngine,
            'videos_scanned' => count($payload['videos']),
            'comments_scanned' => count($payload['comments']),
            'source_videos' => $payload['videos'],
            'pain_points' => $payload['pain_points'],
            'angles' => $payload['angles'],
            'sample_comments' => $payload['comments'],
            'summary' => $payload['summary'],
            'warning' => $this->lastWarning,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function searchPopularVideos(string $topic, string $apiKey): array
    {
        $queries = [
            $topic.' psikologi',
            $topic.' toxic',
            'psikologi gelap '.$topic,
        ];

        $items = [];
        foreach (array_slice($queries, 0, 2) as $q) {
            $response = Http::timeout(25)
                ->withHeaders($this->browserHeaders())
                ->get('https://www.googleapis.com/youtube/v3/search', [
                    'part' => 'snippet',
                    'q' => $q,
                    'type' => 'video',
                    'order' => 'relevance',
                    'maxResults' => 5,
                    'relevanceLanguage' => 'id',
                    'regionCode' => 'ID',
                    'key' => $apiKey,
                ]);

            if (! $response->successful()) {
                $msg = data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status());
                throw new \RuntimeException($msg);
            }

            foreach ($response->json('items') ?? [] as $row) {
                $id = (string) data_get($row, 'id.videoId', '');
                if ($id === '') {
                    continue;
                }
                $items[$id] = [
                    'video_id' => $id,
                    'title' => (string) data_get($row, 'snippet.title', ''),
                    'channel' => (string) data_get($row, 'snippet.channelTitle', ''),
                    'url' => 'https://www.youtube.com/watch?v='.$id,
                    'published_at' => data_get($row, 'snippet.publishedAt'),
                ];
            }
        }

        $ids = array_keys($items);
        if ($ids !== []) {
            $stats = Http::timeout(20)
                ->withHeaders($this->browserHeaders())
                ->get('https://www.googleapis.com/youtube/v3/videos', [
                    'part' => 'statistics',
                    'id' => implode(',', array_slice($ids, 0, 15)),
                    'key' => $apiKey,
                ]);
            if ($stats->successful()) {
                foreach ($stats->json('items') ?? [] as $item) {
                    $vid = (string) ($item['id'] ?? '');
                    if (isset($items[$vid])) {
                        $items[$vid]['views'] = (int) data_get($item, 'statistics.viewCount', 0);
                        $items[$vid]['comments'] = (int) data_get($item, 'statistics.commentCount', 0);
                        $items[$vid]['likes'] = (int) data_get($item, 'statistics.likeCount', 0);
                    }
                }
            }
        }

        return collect($items)
            ->sortByDesc(fn ($v) => (int) ($v['views'] ?? 0))
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function fetchComments(string $videoId, string $apiKey, int $limit = 40): array
    {
        $response = Http::timeout(25)
            ->withHeaders($this->browserHeaders())
            ->get('https://www.googleapis.com/youtube/v3/commentThreads', [
                'part' => 'snippet',
                'videoId' => $videoId,
                'order' => 'relevance',
                'maxResults' => min(100, max(10, $limit)),
                'textFormat' => 'plainText',
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            $msg = data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status());
            throw new \RuntimeException($msg);
        }

        $out = [];
        foreach ($response->json('items') ?? [] as $item) {
            $text = trim((string) data_get($item, 'snippet.topLevelComment.snippet.textDisplay', ''));
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
            if ($text === '' || mb_strlen($text) < 12) {
                continue;
            }
            // Skip spammy links-only.
            if (preg_match('/^https?:\/\//i', $text)) {
                continue;
            }
            $out[] = Str::limit($text, 280, '…');
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $videos
     * @param  array<int, array<string, mixed>>  $comments
     * @return array{pain_points:array<int,string>,angles:array<int,array<string,mixed>>,summary:string}|null
     */
    protected function analyzeWithGemini(string $topic, array $videos, array $comments): ?array
    {
        $apiKey = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        if ($apiKey === '' || $comments === []) {
            return null;
        }

        $commentBlock = collect($comments)
            ->take(80)
            ->map(fn ($c, $i) => ($i + 1).'. '.Str::limit((string) ($c['text'] ?? ''), 200))
            ->implode("\n");

        $videoBlock = collect($videos)
            ->take(8)
            ->map(fn ($v) => '- '.$v['title'].' ('.number_format((int) ($v['views'] ?? 0)).' views)')
            ->implode("\n");

        $prompt = <<<PROMPT
Kamu analis konten viral niche psikologi gelap untuk brand Kea Abbasy.
Topik riset: {$topic}

Video sumber:
{$videoBlock}

Komentar penonton (keluhan / reaksi emosional):
{$commentBlock}

Tugas: temukan CELAH SUDUT PANDANG yang belum klise — dari apa yang paling dikeluhkan / diperdebatkan penonton.

Keluarkan JSON SAJA:
{
  "summary": "2-3 kalimat ringkas temuan komentar",
  "pain_points": ["keluhan 1", "keluhan 2", "... max 8"],
  "angles": [
    {
      "title": "judul sudut pendek tajam",
      "angle": "1-2 kalimat sudut pandang unik / kontroversial / emosional untuk naskah",
      "hook": "hook 1 kalimat pembuka reels",
      "why_it_works": "kenapa sudut ini beda dari kreator lain",
      "emotion": "marah|sedih|tersentak|tersindir|legaan",
      "controversy_score": 1-100
    }
  ]
}

WAJIB 5-7 angles. Urutkan controversy_score tertinggi dulu.
DILARANG sudut generik "pernahkah kamu" / edukasi biasa.
PROMPT;

        $models = array_values(array_unique(array_filter([
            (string) config('blueprint.gemini_model', 'gemini-3.8-flash'),
            ...((array) config('blueprint.gemini_model_fallbacks', [])),
        ])));

        foreach ($models as $model) {
            try {
                $response = Http::timeout(90)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                        [
                            'contents' => [['parts' => [['text' => $prompt]]]],
                            'generationConfig' => [
                                'temperature' => 0.85,
                                'responseMimeType' => 'application/json',
                            ],
                        ]
                    );

                if (! $response->successful()) {
                    continue;
                }

                $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
                if ($text === '') {
                    continue;
                }
                if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
                    $text = trim($m[1]);
                }

                $decoded = json_decode($text, true);
                if (! is_array($decoded) || empty($decoded['angles']) || ! is_array($decoded['angles'])) {
                    continue;
                }

                $angles = [];
                foreach ($decoded['angles'] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $angles[] = [
                        'title' => Str::limit(trim((string) ($row['title'] ?? 'Sudut unik')), 120, ''),
                        'angle' => trim((string) ($row['angle'] ?? '')),
                        'hook' => trim((string) ($row['hook'] ?? '')),
                        'why_it_works' => trim((string) ($row['why_it_works'] ?? '')),
                        'emotion' => trim((string) ($row['emotion'] ?? 'tersentak')),
                        'controversy_score' => max(1, min(100, (int) ($row['controversy_score'] ?? 70))),
                    ];
                }

                $angles = array_values(array_filter($angles, fn ($a) => $a['angle'] !== ''));
                if ($angles === []) {
                    continue;
                }

                usort($angles, fn ($a, $b) => $b['controversy_score'] <=> $a['controversy_score']);

                $pains = array_values(array_filter(array_map(
                    fn ($p) => is_string($p) ? trim($p) : '',
                    $decoded['pain_points'] ?? []
                )));

                return [
                    'summary' => trim((string) ($decoded['summary'] ?? 'Ringkasan komentar penonton.')),
                    'pain_points' => array_slice($pains, 0, 8),
                    'angles' => array_slice($angles, 0, 7),
                ];
            } catch (\Throwable $e) {
                Log::warning("AngleFinder Gemini {$model}: ".$e->getMessage());
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @return array{pain_points:array<int,string>,angles:array<int,array<string,mixed>>,summary:string}
     */
    protected function analyzeWithTemplate(string $topic, array $comments): array
    {
        $texts = collect($comments)->pluck('text')->filter()->values();
        $buckets = [
            'kontrol' => ['kontrol', 'mengontrol', 'gaslight', 'dipaksa', 'takut'],
            'bersalah' => ['bersalah', 'minta maaf', 'salahku', 'aku yang'],
            'pergi' => ['pergi', 'tinggalin', 'putus', 'ninggalin', 'cabut'],
            'validasi' => ['validasi', 'dibilang berlebihan', 'drama', 'lebay'],
            'keluarga' => ['ortu', 'mertua', 'keluarga', 'ibu', 'ayah'],
            'kerja' => ['bos', 'kantor', 'kerja', 'rekan'],
        ];

        $painHits = [];
        foreach ($buckets as $label => $keys) {
            $count = 0;
            foreach ($texts as $t) {
                $low = Str::lower((string) $t);
                foreach ($keys as $k) {
                    if (str_contains($low, $k)) {
                        $count++;
                        break;
                    }
                }
            }
            if ($count > 0) {
                $painHits[$label] = $count;
            }
        }
        arsort($painHits);

        $painPoints = [];
        foreach ($painHits as $label => $count) {
            $painPoints[] = match ($label) {
                'kontrol' => "Banyak yang curhat merasa dikontrol tapi takut dibilang lebay ({$count} sinyal).",
                'bersalah' => "Pola rasa bersalah dipelihara pasangan/orang dekat sering muncul ({$count}).",
                'pergi' => "Orang stuck: tahu harus pergi tapi belum berani ({$count}).",
                'validasi' => "Mereka butuh validasi bahwa reaksi emosionalnya waras ({$count}).",
                'keluarga' => "Toxic dari keluarga / mertua / ortu sering lebih tabu dibahas ({$count}).",
                'kerja' => "Manipulasi di kantor masih jarang dikemas tajam ({$count}).",
                default => "Keluhan seputar {$label} ({$count}).",
            };
        }

        if ($painPoints === []) {
            $painPoints = [
                'Penonton sering cerita bingung membedakan cinta dan kontrol.',
                'Banyak yang merasa bersalah setelah menjaga batas.',
                'Mereka ingin kalimat praktis, bukan teori panjang.',
            ];
        }

        return [
            'summary' => "Dari komentar topik \"{$topic}\": penonton paling sering mengeluhkan kontrol terselubung, rasa bersalah, dan takut pergi — celah naskah ada di sudut yang membenarkan perasaan mereka lalu kasih perintah aksi.",
            'pain_points' => array_slice($painPoints, 0, 8),
            'angles' => $this->fallbackAngles($topic, $painPoints),
        ];
    }

    /**
     * @param  array<int, string>  $painPoints
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackAngles(string $topic, array $painPoints): array
    {
        $t = Str::lower($topic);

        return [
            [
                'title' => 'Bukan cinta — itu sistem kontrol',
                'angle' => "Kupas {$topic} dari sisi: rasa cemas yang dipelihara sengaja supaya kamu mudah dikendalikan.",
                'hook' => 'Kalau kamu harus mengecil supaya dia nyaman, itu bukan cinta.',
                'why_it_works' => 'Kreator lain sering stop di “toxic itu buruk”; sudut ini menunjuk motif kontrol.',
                'emotion' => 'tersentak',
                'controversy_score' => 88,
            ],
            [
                'title' => 'Rasa bersalah sebagai senjata',
                'angle' => 'Tunjukkan bahwa “kamu egois” sering muncul tepat setelah kamu set batas — itu bukan nurani, itu taktik.',
                'hook' => 'Rasa bersalah yang datang setelah kamu bilang tidak? Itu bukan hati nurani.',
                'why_it_works' => 'Komentar penuh orang yang minta maaf setelah menjaga diri — jarang dibedah mekanismenya.',
                'emotion' => 'marah',
                'controversy_score' => 84,
            ],
            [
                'title' => 'Tetap tinggal = melatih pelaku',
                'angle' => 'Sudut kontroversial: setiap kali kamu bertahan tanpa batas, kamu mengajari mereka pola itu berhasil.',
                'hook' => 'Bertahan tanpa batas bukan kesetiaan — itu pelatihan untuk pelaku.',
                'why_it_works' => 'Menabrak narasi “bertahan demi cinta” yang masih laku di feed.',
                'emotion' => 'tersindir',
                'controversy_score' => 90,
            ],
            [
                'title' => 'Validasi bukan drama',
                'angle' => 'Balikkan tuduhan “kamu lebay”: reaksi emosional adalah data, bukan kelemahan.',
                'hook' => 'Kamu bukan drama. Tubuhmu lagi kasih laporan.',
                'why_it_works' => 'Penonton di komentar lapar dibenarkan — sudut ini kasih bahasa yang bisa mereka copy.',
                'emotion' => 'legaan',
                'controversy_score' => 78,
            ],
            [
                'title' => 'Satu batas kecil hari ini',
                'angle' => "Dari keluhan 'tahu tapi belum berani': jangan minta transformasi besar — 1 batas mikro yang bisa ditegakkan malam ini.",
                'hook' => 'Jangan rencanakan kabur dulu. Tegakkan 1 batas kecil malam ini.',
                'why_it_works' => 'Solusi mikro beda dari konten motivasi “tinggalkan sekarang” yang bikin panik.',
                'emotion' => 'sedih',
                'controversy_score' => 72,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function browserHeaders(): array
    {
        $referer = rtrim((string) config('radar.youtube_http_referer'), '/');
        if ($referer === '') {
            $referer = 'https://heng.justusku.co.id';
        }

        return [
            'Referer' => $referer.'/',
            'Origin' => $referer,
            'User-Agent' => 'Mozilla/5.0 (compatible; FBProAngleFinder/1.0)',
        ];
    }
}
