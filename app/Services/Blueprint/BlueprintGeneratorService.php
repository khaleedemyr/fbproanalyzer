<?php

namespace App\Services\Blueprint;

use App\Models\AnalyticsAiAnalysis;
use App\Models\BrandKit;
use App\Models\ContentBlueprint;
use App\Models\ContentIdea;
use App\Models\TrendSignal;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BlueprintGeneratorService
{
    public const FORMAT_REELS_VO = 'reels_vo';

    public const FORMAT_REELS_PANJANG = 'reels_panjang';

    public const FORMAT_REELS_TEXT = 'reels_text';

    public const FORMAT_FOTO = 'foto';

    /** @var list<string> */
    public const FORMATS = [
        self::FORMAT_REELS_VO,
        self::FORMAT_REELS_PANJANG,
        self::FORMAT_REELS_TEXT,
        self::FORMAT_FOTO,
    ];

    protected string $lastEngine = 'template';

    protected ?string $lastWarning = null;

    protected bool $wasDuplicate = false;

    protected int $durationSeconds = 15;

    protected int $beatSeconds = 5;

    protected bool $preferStock = true;

    protected string $voicePerspective = 'Aku';

    public function lastEngine(): string
    {
        return $this->lastEngine;
    }

    public function lastWarning(): ?string
    {
        return $this->lastWarning;
    }

    public function wasDuplicate(): bool
    {
        return $this->wasDuplicate;
    }

    /**
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool,force?:bool,voice_perspective?:string,planned_time?:string|null,analytics_ai_analysis?:AnalyticsAiAnalysis|null,analytics_ai_analysis_id?:int|null}  $options
     */
    public function generateFromTopic(
        User $user,
        string $topic,
        ?BrandKit $brandKit = null,
        ?ContentIdea $idea = null,
        ?TrendSignal $signal = null,
        ?string $plannedDate = null,
        string $format = self::FORMAT_REELS_VO,
        bool $force = false,
        array $options = [],
    ): ContentBlueprint {
        $kit = $brandKit ?? BrandKit::defaultFor($user);
        $topic = trim($topic) !== '' ? trim($topic) : 'Topik psikologi viral';
        $format = $this->normalizeFormat($format);
        $force = $force || (bool) ($options['force'] ?? false);
        $this->applyDurationOptions($format, $options);
        $this->voicePerspective = $this->normalizeVoicePerspective(
            (string) ($options['voice_perspective'] ?? ($kit->voice_perspective ?: 'Aku'))
        );
        $plannedTime = $this->normalizePlannedTime($options['planned_time'] ?? null);
        $analysis = $options['analytics_ai_analysis'] ?? null;
        if (! $analysis instanceof AnalyticsAiAnalysis && ! empty($options['analytics_ai_analysis_id'])) {
            $analysis = AnalyticsAiAnalysis::query()
                ->where('user_id', $user->id)
                ->find($options['analytics_ai_analysis_id']);
        }
        if ($analysis instanceof AnalyticsAiAnalysis && $analysis->user_id !== $user->id) {
            $analysis = null;
        }

        $this->lastEngine = 'template';
        $this->lastWarning = null;
        $this->wasDuplicate = false;

        $cleanTopic = $this->cleanTopic($topic, $idea, $signal);
        $cleanTitle = $this->coverTitle($cleanTopic, $idea);
        $extraContext = trim(implode("\n\n", array_filter([
            $this->radarContext($signal),
            $this->analyticsContext($analysis),
        ])));

        // Judul yang sama (per format) tidak digenerate ulang — tema/topik tetap bebas.
        if (! $force) {
            $existing = $this->findExistingByTitle($user, $cleanTitle, $format);
            if ($existing) {
                $this->wasDuplicate = true;
                $this->lastEngine = (string) ($existing->engine ?: 'template');

                return $existing;
            }
        }

        $gemini = $this->tryGemini($kit, $cleanTitle, $cleanTopic, $idea, $format, $extraContext);
        if ($gemini !== null) {
            $this->lastEngine = 'gemini';
            $payload = $gemini;
        } else {
            $payload = match ($format) {
                self::FORMAT_REELS_PANJANG => $this->buildLongTemplate($kit, $cleanTitle, $cleanTopic, $idea),
                self::FORMAT_REELS_TEXT => $this->buildReelsTextTemplate($kit, $cleanTitle, $cleanTopic, $idea),
                self::FORMAT_FOTO => $this->buildFotoTemplate($kit, $cleanTitle, $cleanTopic, $idea),
                default => $this->buildShortTemplate($kit, $cleanTitle, $cleanTopic, $idea),
            };
        }

        $payload['cover_theme'] = Str::upper(trim((string) ($payload['cover_theme'] ?: $cleanTitle)));

        if ($this->isVisualPostFormat($format)) {
            $payload = $this->ensureVisualClarity($payload, $cleanTopic, $idea, $kit, $format);
        }

        $payload['scenes'] = $this->ensureSceneTimecodes($payload['scenes'] ?? []);
        $payload['thumbnail_prompt'] = $this->ensureImagePromptHasText(
            (string) ($payload['thumbnail_prompt'] ?? ''),
            (string) $payload['cover_theme'],
            $kit,
            $format
        );

        // Untuk mode visual: pastikan scene[0].image_prompt juga sudah ada teks baked-in.
        if ($this->isVisualPostFormat($format) && ! empty($payload['scenes'][0])) {
            $payload['scenes'][0]['image_prompt'] = $this->ensureImagePromptHasText(
                (string) ($payload['scenes'][0]['image_prompt'] ?? $payload['thumbnail_prompt']),
                (string) $payload['cover_theme'],
                $kit,
                $format
            );
            if (trim((string) ($payload['thumbnail_prompt'] ?? '')) === '') {
                $payload['thumbnail_prompt'] = $payload['scenes'][0]['image_prompt'];
            }
            $payload['scenes'][0]['on_screen'] = (string) ($payload['scenes'][0]['on_screen'] ?: $payload['cover_theme']);
        }

        if ($this->isVisualPostFormat($format)) {
            $payload['caption'] = $this->ensureSolutionCaption(
                (string) ($payload['caption'] ?? ''),
                (string) $payload['cover_theme'],
                $cleanTopic,
                $kit
            );
        } else {
            $payload['caption'] = $this->ensureCaption(
                (string) ($payload['caption'] ?? ''),
                $payload['scenes'],
                (string) $payload['cover_theme'],
                $cleanTopic,
                $kit
            );
        }

        $storyConcept = (string) ($payload['story_concept'] ?? '');
        if ($analysis && $storyConcept !== '' && ! str_contains(Str::lower($storyConcept), 'analisa ai')) {
            $storyConcept .= "\n\n[Referensi Analisa AI #{$analysis->id}] ".$analysis->title;
        }

        return ContentBlueprint::query()->create([
            'user_id' => $user->id,
            'brand_kit_id' => $kit->id,
            'content_idea_id' => $idea?->id,
            'trend_signal_id' => $signal?->id,
            'analytics_ai_analysis_id' => $analysis?->id,
            'title' => $payload['title'],
            'cover_theme' => $payload['cover_theme'],
            'story_concept' => $storyConcept !== '' ? $storyConcept : ($payload['story_concept'] ?? null),
            'source_topic' => $cleanTopic,
            'status' => $plannedDate ? 'scheduled' : 'ready',
            'format' => $format,
            'engine' => $this->lastEngine,
            'duration_seconds' => $this->durationSeconds,
            'beat_seconds' => $this->beatSeconds,
            'prefer_stock' => $format === self::FORMAT_REELS_PANJANG ? $this->preferStock : false,
            'voice_perspective' => $this->voicePerspective,
            'scenes' => $payload['scenes'],
            'thumbnail_prompt' => $payload['thumbnail_prompt'],
            'caption' => $payload['caption'],
            'title_options' => $payload['title_options'] ?? null,
            'planned_date' => $plannedDate,
            'planned_time' => $plannedDate ? $plannedTime : null,
        ]);
    }

    /**
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool,force?:bool,voice_perspective?:string}  $options
     */
    public function generateFromIdea(
        User $user,
        ContentIdea $idea,
        ?string $plannedDate = null,
        string $format = self::FORMAT_REELS_VO,
        bool $force = false,
        array $options = [],
    ): ContentBlueprint {
        $topic = $idea->title;
        if ($idea->hook) {
            $topic .= ' — '.$idea->hook;
        }

        return $this->generateFromTopic($user, $topic, null, $idea, null, $plannedDate, $format, $force, $options);
    }

    /**
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool,force?:bool,voice_perspective?:string}  $options
     */
    public function generateFromSignal(
        User $user,
        TrendSignal $signal,
        ?string $plannedDate = null,
        string $format = self::FORMAT_REELS_VO,
        bool $force = false,
        array $options = [],
    ): ContentBlueprint {
        // Jangan append snippet radar (metrik Google/YouTube) ke topik — itu bocor ke naskah VO.
        $topic = (string) ($signal->keyword ?: $signal->title ?: 'psikologi');

        return $this->generateFromTopic($user, $topic, null, null, $signal, $plannedDate, $format, $force, $options);
    }

    public function findExistingByTitle(User $user, string $title, string $format): ?ContentBlueprint
    {
        $key = $this->titleKey($title);
        if ($key === '') {
            return null;
        }

        $candidates = ContentBlueprint::query()
            ->where('user_id', $user->id)
            ->where('format', $this->normalizeFormat($format))
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        foreach ($candidates as $bp) {
            if ($this->titleKey((string) ($bp->cover_theme ?: $bp->title)) === $key) {
                return $bp;
            }
            if ($this->titleKey((string) $bp->title) === $key) {
                return $bp;
            }
        }

        return null;
    }

    public function titleKey(string $value): string
    {
        $clean = $this->spokenTheme($value, $value);
        $clean = Str::lower($clean);
        $clean = preg_replace('/[^a-z0-9]+/u', '', $clean) ?? '';

        return $clean;
    }

    public function toMarkdown(ContentBlueprint $bp, BrandKit $kit): string
    {
        $format = $bp->format ?? self::FORMAT_REELS_VO;
        if ($format === self::FORMAT_REELS_PANJANG) {
            return $this->toLongMarkdown($bp, $kit);
        }
        if ($format === self::FORMAT_REELS_TEXT) {
            return $this->toReelsTextMarkdown($bp, $kit);
        }
        if ($format === self::FORMAT_FOTO) {
            return $this->toFotoMarkdown($bp, $kit);
        }

        $voice = $bp->voice_perspective ?: ($kit->voice_perspective ?: 'Aku');
        $durLabel = $this->humanDuration((int) ($bp->duration_seconds ?: $this->durationSeconds));
        $beat = (int) ($bp->beat_seconds ?: 5);

        $lines = [
            '🎬 Blueprint Reels Cinematic (Reels VO)',
            'Tema / Judul Cover: "'.$bp->cover_theme.'"',
            'Konsep Cerita Film: '.$bp->story_concept,
            'Durasi total: '.$durLabel.' · beat '.$beat.' dtk · Sudut pandang: "'.$voice.'"',
            '',
            'Durasi / Detik | Naskah Voiceover (VO) (Sudut Pandang "'.$voice.'") | Teks On-Screen (CapCut) | 🎨 Prompt Gambar ('.$kit->image_tool.' - '.$kit->aspect_ratio.' Cinematic) | 🎬 Prompt Animasi ('.$kit->video_tool.' / Gerakan Kamera)',
            '',
        ];

        foreach ($bp->scenes ?? [] as $scene) {
            $lines[] = ($scene['timecode'] ?? '').' | '.($scene['vo'] ?? '');
            $lines[] = 'Teks: '.($scene['on_screen'] ?? '');
            $lines[] = 'Gambar: '.($scene['image_prompt'] ?? '');
            $lines[] = 'Animasi: '.($scene['motion_prompt'] ?? '');
            $lines[] = '';
        }

        $lines[] = '🎨 Prompt Thumbnail Clickbait ('.$kit->image_tool.')';
        $lines[] = $bp->thumbnail_prompt ?? '';
        $lines[] = '';
        $lines[] = '📝 Caption Siap Salin (Copy-Paste)';
        $lines[] = $bp->caption ?? '';

        return implode("\n", $lines);
    }

    protected function toReelsTextMarkdown(ContentBlueprint $bp, BrandKit $kit): string
    {
        $scene = ($bp->scenes ?? [])[0] ?? [];
        $dur = (int) ($bp->duration_seconds ?: 8);
        $lines = [
            '📌 Blueprint Reels Text Tempel',
            'Tema / Teks di gambar: "'.$bp->cover_theme.'"',
            'Konsep: '.$bp->story_concept,
            'Durasi i2v: '.$dur.' detik',
            '',
            '🎨 Prompt Generate Image ('.$kit->image_tool.' — teks sudah baked-in, jangan edit lagi):',
            (string) ($scene['image_prompt'] ?? $bp->thumbnail_prompt ?? ''),
            '',
            '🎬 Prompt Image → Video ('.$kit->video_tool.'):',
            (string) ($scene['motion_prompt'] ?? ''),
            '',
            '📝 Caption siap salin:',
            (string) ($bp->caption ?? ''),
        ];

        return implode("\n", $lines);
    }

    protected function toFotoMarkdown(ContentBlueprint $bp, BrandKit $kit): string
    {
        $scene = ($bp->scenes ?? [])[0] ?? [];
        $lines = [
            '🖼️ Blueprint Postingan Foto',
            'Tema / Teks di gambar: "'.$bp->cover_theme.'"',
            'Konsep: '.$bp->story_concept,
            '',
            '🎨 Prompt Generate Image ('.$kit->image_tool.' — teks sudah baked-in, jangan edit lagi):',
            (string) ($scene['image_prompt'] ?? $bp->thumbnail_prompt ?? ''),
        ];
        if (trim((string) ($bp->caption ?? '')) !== '') {
            $lines[] = '';
            $lines[] = '📝 Caption (opsional):';
            $lines[] = (string) $bp->caption;
        }

        return implode("\n", $lines);
    }

    protected function toLongMarkdown(ContentBlueprint $bp, BrandKit $kit): string
    {
        $voice = $bp->voice_perspective ?: ($kit->voice_perspective ?: 'Aku');
        $durLabel = $this->humanDuration((int) ($bp->duration_seconds ?: $this->durationSeconds));
        $beat = (int) ($bp->beat_seconds ?: 5);

        $lines = [
            $bp->title,
            '🎬 BLUEPRINT REELS PANJANG / VOD',
            'Tema: "'.$bp->cover_theme.'"',
            'Konsep: '.$bp->story_concept,
            'Durasi total: '.$durLabel.' · beat '.$beat.' dtk · Sudut pandang: "'.$voice.'"',
            '',
            'Durasi | Visual Level-Dewa | Motion Prompt / Keterangan | Naskah VO & Teks Layar',
            '',
        ];

        $currentSection = null;
        foreach ($bp->scenes ?? [] as $scene) {
            $section = $scene['section'] ?? null;
            if ($section && $section !== $currentSection) {
                $currentSection = $section;
                $lines[] = '—— '.$currentSection.' ——';
                $lines[] = '';
            }
            $lines[] = ($scene['timecode'] ?? '').' | '.($scene['image_prompt'] ?? '');
            $lines[] = 'Motion: '.($scene['motion_prompt'] ?? '-');
            $lines[] = 'VO: '.($scene['vo'] ?? '');
            $lines[] = 'Teks: '.($scene['on_screen'] ?? '');
            $lines[] = '';
        }

        if (! empty($bp->title_options) && is_array($bp->title_options)) {
            $lines[] = 'Pilihan Judul (Cover/Thumbnail):';
            foreach (array_values($bp->title_options) as $i => $opt) {
                $lines[] = 'Opsi '.($i + 1).': '.$opt;
            }
            $lines[] = '';
        }

        $lines[] = '📝 Caption Siap Salin';
        $lines[] = $bp->caption ?? '';

        return implode("\n", $lines);
    }

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,mixed>>,thumbnail_prompt:string,caption:string,title_options?:array<int,string>}|null
     */
    protected function tryGemini(
        BrandKit $kit,
        string $title,
        string $topic,
        ?ContentIdea $idea,
        string $format,
        string $radarContext = '',
    ): ?array {
        $apiKey = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        if ($apiKey === '') {
            $this->lastWarning = 'GEMINI_API_KEY belum terisi / belum kebaca config. Fallback template.';

            return null;
        }

        $prompt = match ($format) {
            self::FORMAT_REELS_PANJANG => $this->geminiLongPrompt($kit, $title, $topic, $idea, $radarContext),
            self::FORMAT_REELS_TEXT => $this->geminiReelsTextPrompt($kit, $title, $topic, $idea, $radarContext),
            self::FORMAT_FOTO => $this->geminiFotoPrompt($kit, $title, $topic, $idea, $radarContext),
            default => $this->geminiShortPrompt($kit, $title, $topic, $idea, $radarContext),
        };

        $models = array_values(array_unique(array_filter([
            (string) config('blueprint.gemini_model', 'gemini-3.8-flash'),
            ...((array) config('blueprint.gemini_model_fallbacks', [])),
        ])));

        $errors = [];

        foreach ($models as $model) {
            try {
                $response = Http::timeout($format === self::FORMAT_REELS_PANJANG ? 120 : 60)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                        [
                            'contents' => [[
                                'parts' => [['text' => $prompt]],
                            ]],
                        'generationConfig' => [
                            'temperature' => 0.95,
                            'topP' => 0.95,
                            'responseMimeType' => 'application/json',
                        ],
                    ]
                );

                if (! $response->successful()) {
                    $err = data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status());
                    $errors[] = "{$model}: {$err}";

                    continue;
                }

                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (! is_string($text) || $text === '') {
                    $block = data_get($response->json(), 'candidates.0.finishReason')
                        ?: data_get($response->json(), 'promptFeedback.blockReason');
                    $errors[] = "{$model}: respons kosong".($block ? " ({$block})" : '');

                    continue;
                }

                // Kadang model bungkus JSON di markdown fence.
                $jsonText = trim($text);
                if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $jsonText, $m)) {
                    $jsonText = trim($m[1]);
                }

                $decoded = json_decode($jsonText, true);
                if (! is_array($decoded)) {
                    $errors[] = "{$model}: JSON blueprint tidak valid";

                    continue;
                }

                // Mode visual boleh kirim image_prompt di root tanpa scenes.
                if ($this->isVisualPostFormat($format) && (empty($decoded['scenes']) || ! is_array($decoded['scenes']))) {
                    $img = (string) ($decoded['image_prompt'] ?? $decoded['thumbnail_prompt'] ?? '');
                    $motion = (string) ($decoded['motion_prompt'] ?? $decoded['i2v_prompt'] ?? '');
                    $decoded['scenes'] = [[
                        'section' => $format === self::FORMAT_FOTO ? 'POSTINGAN FOTO' : 'REELS TEXT TEMPEL',
                        'timecode' => '',
                        'visual_type' => 'ai',
                        'vo' => '',
                        'on_screen' => (string) ($decoded['cover_theme'] ?? $decoded['on_screen'] ?? Str::upper($title)),
                        'image_prompt' => $img,
                        'motion_prompt' => $format === self::FORMAT_FOTO ? '-' : $motion,
                    ]];
                }

                if (empty($decoded['scenes']) || ! is_array($decoded['scenes'])) {
                    $errors[] = "{$model}: JSON blueprint tidak valid";

                    continue;
                }

                $scenes = array_values(array_map(
                    fn ($s) => $this->normalizeScene(is_array($s) ? $s : []),
                    $decoded['scenes']
                ));

                if (! $this->scenesAreUsable($scenes, $format)) {
                    if ($this->isVisualPostFormat($format)) {
                        $errors[] = "{$model}: prompt gambar/i2v kosong atau teks belum baked-in";
                    } else {
                        $filled = count(array_filter($scenes, fn ($s) => trim((string) ($s['vo'] ?? '')) !== ''));
                        $errors[] = "{$model}: naskah VO kosong/invalid ({$filled}/".count($scenes).' scene berisi VO)';
                    }

                    continue;
                }

                $thumb = (string) ($decoded['thumbnail_prompt'] ?? $decoded['image_prompt'] ?? ($scenes[0]['image_prompt'] ?? ''));

                return [
                    'title' => (string) ($decoded['title'] ?? $title),
                    'cover_theme' => (string) ($decoded['cover_theme'] ?? Str::upper($title)),
                    'story_concept' => (string) ($decoded['story_concept'] ?? ''),
                    'scenes' => $scenes,
                    'thumbnail_prompt' => $thumb,
                    'caption' => (string) ($decoded['caption'] ?? ''),
                    'title_options' => array_values(array_map('strval', $decoded['title_options'] ?? [])),
                ];
            } catch (\Throwable $e) {
                $errors[] = "{$model}: ".$e->getMessage();
            }
        }

        $this->lastWarning = 'Gemini gagal ('.implode(' | ', $errors).') — pakai template.';

        return null;
    }

    protected function geminiShortPrompt(
        BrandKit $kit,
        string $title,
        string $topic,
        ?ContentIdea $idea,
        string $radarContext = '',
    ): string {
        $hook = $idea?->hook ?: '';
        $angle = $idea?->angle ?: '';
        $radarBlock = $radarContext !== '' ? "Konteks riset (JANGAN dibaca di VO, hanya inspirasi sudut):\n{$radarContext}\n" : '';

        $beats = $this->beatCount();
        $dur = $this->durationSeconds;
        $beat = $this->beatSeconds;

        $banned = $this->bannedHookPhrasesForPrompt();

        return <<<PROMPT
Kamu adalah ghostwriter viral Reels psikologi gelap untuk brand "{$kit->name}".
Tujuan: blueprint yang BERPOTENSI SANGAT VIRAL — bukan konten edukasi biasa.

Karakter visual WAJIB konsisten:
- Nama: {$kit->character_name}
- Visual: {$kit->character_visual}
- Style: {$kit->visual_style}
- Aspect: {$kit->aspect_ratio}
- Font on-screen: {$kit->on_screen_font}
- Sudut VO WAJIB: "{$this->voicePerspective}" — semua kalimat VO pakai sudut ini (bukan ceramah orang ketiga)
- Hashtag caption: {$kit->caption_hashtags}

Tema: {$title}
Topik: {$topic}
Hook ide (boleh diabaikan jika lemah/klise): {$hook}
Angle: {$angle}
{$radarBlock}
DURASI: {$dur} detik · beat {$beat}s · tepat {$beats} scene.
Setiap scene timecode format "MM:SS - MM:SS" (beat 1 = 00:00 - 00:05, beat 2 = 00:05 - 00:10, dst).

===== WAJIB NASKAH =====
- Ini REELS VO PENDEK: SETIAP scene WAJIB punya "vo" terisi (1–2 kalimat).
- DILARANG vo kosong / "" / null.
- DILARANG visual_type "bumper" (bumper hanya untuk video panjang).
- DILARANG scene yang cuma visual tanpa naskah.
- Field "caption" WAJIB terisi (hook + 2–3 kalimat + CTA + hashtag). JANGAN kosong.

===== PRIORITAS #1: HOOK (detik 0–5) =====
- Beat pertama menentukan viral. VO pembuka HARUS on-point, mengejutkan, spesifik.
- Hook visual + teks on-screen + VO harus SELARAS.
- Teks on-screen beat 1: pendek, bold, clickbait cerdas.

DILARANG KERAS sebagai pembuka VO / teks:
{$banned}

GAYA NASKAH:
- Klaim tajam / kontradiksi / pengakuan gelap.
- Beat tengah escalate. Beat akhir punchline + CTA.

ATURAN LAIN:
- No metrik radar di VO.
- thumbnail_prompt WAJIB teks judul exact di quotes.

Setiap scene HARUS punya field: timecode, visual_type, vo, on_screen, image_prompt, motion_prompt.
vo contoh (sudut "{$this->voicePerspective}"): "{$this->voicePerspective} mending keliatan biasa tapi aset nambah, daripada sok sempurna tapi dalamnya hancur."

JSON SAJA dengan keys: title, cover_theme, story_concept, scenes, thumbnail_prompt, caption, title_options.
Tepat {$beats} scene, SEMUA vo terisi, caption WAJIB ada.
PROMPT;
    }

    protected function geminiLongPrompt(
        BrandKit $kit,
        string $title,
        string $topic,
        ?ContentIdea $idea,
        string $radarContext = '',
    ): string {
        $hook = $idea?->hook ?: '';
        $angle = $idea?->angle ?: '';
        $radarBlock = $radarContext !== '' ? "Konteks riset (JANGAN dibaca di VO):\n{$radarContext}\n" : '';

        $totalSec = $this->durationSeconds;
        $beat = $this->beatSeconds;
        $beatCount = $this->beatCount();
        $minutes = max(1, (int) round($totalSec / 60));
        $preferStock = $this->preferStock
            ? 'UTAMAKAN stock + black (minimal AI). Target: ~70% stock, ~15% black, ~10% bumper, max ~5% ai.'
            : 'Boleh campur ai/stock/black/bumper seimbang.';
        $banned = $this->bannedHookPhrasesForPrompt();

        return <<<PROMPT
Kamu adalah penulis MASTER BLUEPRINT VOD Dark Psychology yang dirancang untuk SANGAT VIRAL untuk brand "{$kit->name}".
Durasi {$totalSec}s (~{$minutes} mnt), beat {$beat}s, ~{$beatCount} scene.
{$preferStock}
Stock: "(Video Stok 10 Detik): ...". Black: "(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut". Bumper: "Bumper KEA Abbasy" + SFX, VO kosong. WAJIB bumper akhir tiap bab.

Tema: {$title}
Topik: {$topic}
Hook ide (abaikan jika klise): {$hook}
Angle: {$angle}
Sudut VO WAJIB: "{$this->voicePerspective}" (narasi dari sudut ini)
Hashtag: {$kit->caption_hashtags}
{$radarBlock}
Setiap scene timecode "MM:SS - MM:SS" sesuai beat {$beat}s.

===== GOLDEN HOOK WAJIB BERBASIS REALITA =====
- 60–120 detik pertama: CERITA NYATA tokoh dunia / kasus publik (Elon, Jobs, Churchill, Seneca, CEO, dll) relevan topik.
- Format: tahun + situasi + konflik + twist → cliff ke topik.
- 3 beat pertama WAJIB punya vo terisi (bukan bumper).

DILARANG KERAS pembuka:
{$banned}

===== NASKAH =====
- Hanya scene visual_type "bumper" yang boleh vo kosong.
- Semua scene stock/ai/black WAJIB punya vo + on_screen dari sudut "{$this->voicePerspective}".
- Hook naskah + visual = aset utama.
- Field "caption" WAJIB terisi (hook + ringkas isi + CTA + hashtag). JANGAN kosong.

ATURAN: no metrik radar. Thumbnail WAJIB teks judul di quotes.
Struktur: GOLDEN HOOK → BAB1 → BAB2 → BAB3 → PENUTUP (+ bumper antar bab saja).
JSON SAJA dengan keys: title, cover_theme, story_concept, scenes, thumbnail_prompt, caption, title_options.
PROMPT;
    }

    protected function bannedHookPhrasesForPrompt(): string
    {
        return <<<'TXT'
- "Pernahkah kamu..."
- "Apakah kamu..."
- "Tahukah kamu..."
- "Hai/Halo guys..."
- "Di video kali ini..."
- "Hari ini aku mau bahas..."
- "Kali ini kita akan..."
- "Dalam video ini..."
- "Mari kita bahas..."
- "Banyak orang tidak tahu..."
TXT;
    }

    protected function geminiReelsTextPrompt(
        BrandKit $kit,
        string $title,
        string $topic,
        ?ContentIdea $idea,
        string $radarContext = '',
    ): string {
        $hook = $idea?->hook ?: '';
        $angle = $idea?->angle ?: '';
        $radarBlock = $radarContext !== '' ? "Konteks riset (jangan masuk prompt):\n{$radarContext}\n" : '';
        $dur = max(5, $this->durationSeconds);
        $char = $kit->character_visual ?: 'stickman wireframe executive tuxedo + yellow rubber duck';
        $banned = $this->bannedHookPhrasesForPrompt();
        $copyRules = $this->visualCopyRulesForPrompt();

        return <<<PROMPT
Kamu buat blueprint REELS TEXT TEMPEL (gambar diam → image-to-video) untuk brand "{$kit->name}".
Tujuan: orang yang scroll LANGSUNG paham masalah + solusinya. Jangan abu-abu.

Karakter: {$kit->character_name} — {$char}
Style: {$kit->visual_style}
Aspect: {$kit->aspect_ratio}
Font teks: {$kit->on_screen_font}
Hashtag: {$kit->caption_hashtags}
Durasi i2v: {$dur} detik.

Tema: {$title}
Topik: {$topic}
Hook: {$hook}
Angle: {$angle}
{$radarBlock}

{$copyRules}

WAJIB TEKNIS:
1) cover_theme = 2 BARIS BESAR dipisah " | " (contoh: "CINTA BERACUN BUKAN CINTA | JIKA TERUS CEMAS — PERGI"). UPPERCASE. Max 12 kata total.
2) image_prompt = 1 prompt lengkap. Bake judul 2 baris BESAR + footer KECIL (lihat aturan di atas). User TIDAK edit teks.
3) motion_prompt = image-to-video {$dur}s, semua teks tetap tajam/tidak bergeser.
4) caption WAJIB lengkap (solusi + ajakan like/share/follow) + hashtag.
5) 1 scene: image_prompt + motion_prompt + on_screen = cover_theme (pakai newline atau " | ").

DILARANG teks klise: {$banned}
DILARANG cover cuma label tema tanpa solusi (contoh jelek: "TERJEBAK DALAM CINTA BERACUN").
DILARANG lupa footer kecil "lihat caption" + like/share/follow di image_prompt.

JSON keys: title, cover_theme, story_concept, scenes, thumbnail_prompt, caption, title_options.
PROMPT;
    }

    protected function geminiFotoPrompt(
        BrandKit $kit,
        string $title,
        string $topic,
        ?ContentIdea $idea,
        string $radarContext = '',
    ): string {
        $hook = $idea?->hook ?: '';
        $angle = $idea?->angle ?: '';
        $radarBlock = $radarContext !== '' ? "Konteks riset (jangan masuk prompt):\n{$radarContext}\n" : '';
        $char = $kit->character_visual ?: 'stickman wireframe executive tuxedo + yellow rubber duck';
        $banned = $this->bannedHookPhrasesForPrompt();
        $copyRules = $this->visualCopyRulesForPrompt();

        return <<<PROMPT
Kamu buat blueprint POSTINGAN FOTO untuk brand "{$kit->name}".
Tujuan: 1 foto = pesan jelas (masalah + solusi). Jangan abu-abu / puitis kosong.

Karakter: {$kit->character_name} — {$char}
Style: {$kit->visual_style}
Aspect: {$kit->aspect_ratio} (boleh 4:5 feed)
Font teks: {$kit->on_screen_font}
Hashtag: {$kit->caption_hashtags}

Tema: {$title}
Topik: {$topic}
Hook: {$hook}
Angle: {$angle}
{$radarBlock}

{$copyRules}

WAJIB TEKNIS:
1) cover_theme = 2 BARIS BESAR dipisah " | " (hook tajam + perintah/solusi). UPPERCASE. Max 12 kata.
2) image_prompt = 1 prompt generate image. Bake judul 2 baris BESAR + footer KECIL (lihat aturan). User TIDAK edit teks.
3) motion_prompt = "-"
4) caption WAJIB ada SOLUSI konkret + ajakan like/share/follow + hashtag.
5) 1 scene saja.

DILARANG: {$banned}
DILARANG cover cuma label tema. DILARANG caption tanpa solusi / tanpa CTA engagement.
DILARANG lupa footer kecil di image_prompt.

JSON keys: title, cover_theme, story_concept, scenes, thumbnail_prompt, caption, title_options.
PROMPT;
    }

    protected function visualCopyRulesForPrompt(): string
    {
        $footer1 = $this->imageFooterLine1();
        $footer2 = $this->imageFooterLine2();
        $cta = $this->engagementCtaLine();

        return <<<TXT
===== PESAN HARUS JELAS (ANTI ABU-ABU) =====
Teks di gambar = yang orang baca dalam 1 detik. Harus ada:
- Baris 1 (BESAR): diagnosis / kontradiksi tajam (bukan judul tema generik)
- Baris 2 (BESAR): SOLUSI / perintah aksi (pergi, putus pola, set batas, tolak, dll)
- Footer KECIL di bawah gambar (wajib, lebih kecil dari judul, tidak menyaingi judul):
  1) "{$footer1}"
  2) "{$footer2}"

Contoh BAGUS cover_theme:
- "CINTA BERACUN BUKAN CINTA | JIKA TERUS CEMAS — PERGI"
- "MERASA BERSALAH TERUS? | ITU BUKAN CINTA — ITU KONTROL"
- "JANGAN JAWAB PERTANYAAN JEBAKAN | BALIKKAN BINGKAINYA"

Contoh JELEK (dilarang):
- "TERJEBAK DALAM CINTA BERACUN" (cuma label, tidak ngajarin apa-apa)
- "PSIKOLOGI GELAP" / "TOXIC RELATIONSHIP"

CAPTION WAJIB struktur:
1) 1 kalimat hook (masalah spesifik)
2) 1–2 kalimat insight (kenapa berbahaya)
3) Blok "Solusi:" berisi 2–3 langkah konkret yang bisa dilakukan hari ini
4) Ajakan engagement: {$cta}
5) hashtag

Caption tanpa solusi ATAU tanpa ajakan like/share/follow = GAGAL.
TXT;
    }

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,mixed>>,thumbnail_prompt:string,caption:string,title_options:array<int,string>}
     */
    protected function buildShortTemplate(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): array
    {
        $theme = $this->spokenTheme($title, $topic);
        $cover = Str::upper(Str::limit($theme ?: 'TEMA VIRAL', 60, ''));
        $short = Str::limit($idea?->hook ?: $cover, 42, '');
        $char = $kit->character_visual ?: 'A stickman figure made of thin black wireframe lines in a sharp executive tuxedo with a yellow rubber duck prop';
        $style = $kit->visual_style ?: 'Cinematic 9:16 vertical, Jakarta neon night, high contrast';
        $font = $kit->on_screen_font ?: 'Anton';
        $voice = $this->voicePerspective;
        $hashtags = $kit->caption_hashtags ?: '#keaabbasy #psikologigelap #reelsviral';
        $beat = $this->beatSeconds;
        $count = $this->beatCount();

        $opening = $this->spokenLine($idea?->hook);
        if ($opening === null || $this->looksLikeWeakHook($opening)) {
            $opening = "{$voice} mending keliatan biasa aja soal {$theme}, daripada kelihatan hebat tapi dalamnya kosong.";
        }

        $seedVos = [
            $opening,
            $this->spokenLine($idea?->angle) ?: "Yang paling berbahaya soal {$theme}: orang mengira itu normal, padahal itu jebakan kontrol.",
            "Kalau kamu masih membela pola itu, berarti bingkainya yang menang — bukan kamu.",
            'Ubah pola sebelum pola itu yang ngubah hidupmu.',
            'Simpan ini. Besok kamu bakal ketemu polanya lagi — dan kali ini kamu sadar.',
            'Kalau familiar, jangan cuma angguk. Putus polanya hari ini.',
        ];
        $locations = [
            'walking forward down a crowded, bustling Jakarta city street at night with glowing neon lights and bokeh traffic',
            'standing near a crowded outdoor cafe terrace under neon signs, dark comedy psychology aesthetic',
            'walking past a glowing storefront window in a crowded downtown walkway, grit urban texture',
            'sitting alone on a dim rooftop overlooking neon city skyline, contemplative mood',
            'crossing a rainy crosswalk under streetlights, reflections on wet asphalt',
            'standing in a quiet elevator mirror hallway, high contrast rim light',
        ];
        $shots = ['wide shot', 'medium shot', 'close-up shot', 'tracking shot', 'over-the-shoulder shot', 'low angle shot'];
        $motions = [
            'The stickman character walks forward steadily toward the camera through the blurred, busy crowd, with neon lights reflecting smoothly on its tuxedo and a natural fluid walking cycle.',
            'The stickman character slowly turns its head toward the camera, while pedestrians blur softly in the background. Natural micro-movements.',
            'The camera tracks smoothly alongside the character walking forward, with natural lighting shifting across its frame and background reflections moving dynamically.',
            'Slow push-in on the character as city lights shimmer behind. Subtle breathing motion.',
            'Gentle parallax as the character stands still while traffic bokeh drifts past.',
            'Camera orbits slightly around the tuxedo stickman holding the yellow rubber duck.',
        ];
        $onSeed = [
            "{$cover} 🛑\n\n(Font: {$font}, Warna: Merah & Putih)",
            Str::upper(Str::limit($short, 28, ''))." ⚠️\n\n(Font: {$font}, Warna: Kuning Emas)",
            "SADAR SEBELUM TERLAMBAT! 🔥\n\n(Font: {$font}, Warna: Kuning & Putih)",
            "POLA INI NYATA 👁️\n\n(Font: {$font}, Warna: Putih)",
            "STOP DULU. ✋\n\n(Font: {$font}, Warna: Merah)",
            "KAMU BISA KELUAR. 💡\n\n(Font: {$font}, Warna: Emas)",
        ];

        $scenes = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $i * $beat;
            $end = $start + $beat;
            $scenes[] = [
                'section' => 'REELS VO',
                'timecode' => $this->formatTimecode($start, $end),
                'visual_type' => 'ai',
                'vo' => $seedVos[$i % count($seedVos)],
                'on_screen' => $onSeed[$i % count($onSeed)],
                'image_prompt' => "A vertical {$kit->aspect_ratio} cinematic {$shots[$i % count($shots)]} of {$char} {$locations[$i % count($locations)]}. {$style}. Ultra-detailed, consistent character design. --ar 9:16",
                'motion_prompt' => "A cinematic 3D motion. {$motions[$i % count($motions)]}",
            ];
        }

        return [
            'title' => Str::limit($theme, 120, ''),
            'cover_theme' => $cover,
            'story_concept' => "Reels VO {$this->durationSeconds} dtk (beat {$beat}s). Karakter {$kit->character_name} membongkar: {$theme}.",
            'scenes' => $scenes,
            'thumbnail_prompt' => "A vertical {$kit->aspect_ratio} cinematic graphic design for a video thumbnail. {$char} in a bustling neon-lit Jakarta street at night. At the top of the image, the text \"{$cover}\" is boldly displayed in a large, modern, glowing yellow font with a dark drop shadow. High contrast, urban cinematic aesthetic, professional film grain, no offensive elements.",
            'caption' => trim($seedVos[0])."\n\nRealitanya, {$theme} sering jadi jebakan yang terasa biasa — sampai kamu sadar biayanya terlalu mahal.\n\nSetuju atau tidak? Tulis di komentar. 👇\n.\n.\n.\n".$hashtags,
            'title_options' => [],
        ];
    }

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,mixed>>,thumbnail_prompt:string,caption:string,title_options:array<int,string>}
     */
    protected function buildLongTemplate(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): array
    {
        $theme = $this->spokenTheme($title, $topic);
        $cover = Str::upper(Str::limit($theme ?: 'EPISODE DARK PSYCHOLOGY', 70, ''));
        $shortTopic = Str::limit($theme, 80, '');
        $hashtags = $kit->caption_hashtags ?: '#keaabbasy #darkpsychology #psikologigelap #manipulasi #reelsfb';
        $hookLine = $this->spokenLine($idea?->hook);
        if ($hookLine === null || $this->looksLikeWeakHook($hookLine)) {
            $hookLine = 'Tahun 2023, seorang wartawan senior duduk di hadapan Elon Musk dengan pertanyaan yang sudah dipoles agar dia terpojok.';
        }
        $angleLine = $this->spokenLine($idea?->angle) ?: 'Kuasai bingkainya, kunci narasinya, tanyakan balik motif mereka.';

        $beat = $this->beatSeconds;
        $total = $this->durationSeconds;
        $count = $this->beatCount();

        $sectionDefs = [
            ['THE GOLDEN HOOK', 0.20, [
                $hookLine,
                'Dengan nada menyudutkan, pertanyaan jebakan diluncurkan — asumsi beracun sudah ditanam di dalamnya.',
                'Orang biasa akan panik membela diri. Itu justru yang diinginkan.',
                'Tapi yang paham psikologi gelap tidak menjawab bingkai itu — dia merusaknya.',
                'Satu pertanyaan balik cukup untuk membalikkan seluruh realita percakapan.',
                'Dalam hitungan detik, kendali berpindah tanpa bentakan.',
                "Itulah anatomi dari: {$shortTopic}.",
            ]],
            ['BAB 1', 0.22, ['Pola ini sudah dipetakan lama.', 'Lawan terdesak memaksa penjelasan.', 'Di situlah kelemahanmu muncul.', 'Contoh di kantor sehari-hari.', 'Waktu digabung dengan integritas.', 'Penjelasan panjang terdengar seperti rengekan.', 'Semakin banyak alasan, semakin mudah dimanipulasi.']],
            ['BAB 2', 0.20, ['Cara merebut kembali kendali percakapan.', 'Tatap. Tahan jeda 2 detik.', 'Pakai reframing dengan suara datar.', $angleLine, 'Dalam satu kalimat bingkai tuduhan remuk.', 'Kamu tidak membentak — kamu merebut mahkota.']],
            ['BAB 3', 0.23, ['Simpan 4 pola pembalikan narasi.', '1. Mirroring Motive — tanyakan balik motif.', '2. Merusak asumsi di dalam pertanyaan.', '3. Buat aturan main baru, jangan pilih dilema.', '4. Keheningan taktis 3–5 detik.', 'Keheningan yang tenang lebih menakutkan dari teriakan.']],
            ['PENUTUP', 0.15, ['Dunia penuh pertanyaan jebakan.', 'Pikiranmu milikmu — jaga Inner Citadel.', 'Lepaskan kebutuhan selalu membela diri.', 'Kuasai bingkai, kunci narasi.', 'Terima kasih sudah mendengar KEA Abbasy. Bagikan video ini.']],
        ];

        $stocks = [
            '(Video Stok 10 Detik): Micro expression of a person looking aggressive in shadows.',
            '(Video Stok 10 Detik): Close up male eye staring coldly without blinking.',
            '(Video Stok 10 Detik): Office worker looking stressed under dramatic lighting.',
            '(Video Stok 10 Detik): Person holding head in hands panicking.',
            '(Video Stok 10 Detik): Dark ink forming abstract shapes in clear water.',
            '(Video Stok 10 Detik): Chess pieces falling on a dark board.',
            '(Video Stok 10 Detik): Man in suit looking nervous in shadows.',
            '(Video Stok 10 Detik): Calm confident man standing still in low light.',
            '(Video Stok 10 Detik): Hourglass and ticking clock macro.',
            '(Video Stok 10 Detik): Shadow reversing direction abstract slow motion.',
        ];
        $aiSparse = [
            'Cinematic abstract. A glowing steel trap snapping shut in slow motion.',
            'Cinematic surrealism. A glass frame shattering into golden shards.',
            'Abstract geometry. A glowing golden lock snapping shut on a black box.',
        ];

        $vosPool = [];
        foreach ($sectionDefs as $def) {
            foreach ($def[2] as $line) {
                $vosPool[] = $line;
            }
        }

        $contentBeats = max(10, $count - count($sectionDefs));
        $alloc = [];
        $used = 0;
        foreach ($sectionDefs as $i => $def) {
            if ($i === count($sectionDefs) - 1) {
                $alloc[] = max(3, $contentBeats - $used);
            } else {
                $n = max(3, (int) round($contentBeats * $def[1]));
                $alloc[] = $n;
                $used += $n;
            }
        }

        $scenes = [];
        $t = 0;
        $voIdx = 0;
        $stockIdx = 0;
        $aiIdx = 0;

        foreach ($sectionDefs as $sIdx => $def) {
            $name = $def[0];
            $n = $alloc[$sIdx];
            $sectionStart = $t;

            for ($i = 0; $i < $n; $i++) {
                $start = $t;
                $end = $t + $beat;
                $mod = $i % 5;

                if ($this->preferStock) {
                    if ($mod === 2) {
                        $type = 'black';
                        $visual = '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.';
                        $motion = '-';
                    } elseif ($sIdx === 0 && $i === 0) {
                        $type = 'ai';
                        $visual = $aiSparse[$aiIdx++ % count($aiSparse)];
                        $motion = 'Extremely slow zoom in. Subtle atmospheric motion.';
                    } else {
                        $type = 'stock';
                        $visual = $stocks[$stockIdx++ % count($stocks)];
                        $motion = '-';
                    }
                } elseif ($mod === 0) {
                    $type = 'ai';
                    $visual = $aiSparse[$aiIdx++ % count($aiSparse)];
                    $motion = 'Extremely slow zoom in. Subtle atmospheric motion.';
                } elseif ($mod === 2) {
                    $type = 'black';
                    $visual = '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.';
                    $motion = '-';
                } else {
                    $type = 'stock';
                    $visual = $stocks[$stockIdx++ % count($stocks)];
                    $motion = '-';
                }

                $vo = $vosPool[$voIdx % count($vosPool)];
                $voIdx++;
                $on = Str::upper(Str::limit(preg_replace('/[^\pL\pN\s]+/u', '', $vo) ?: $cover, 34, ''));

                $scenes[] = [
                    'section' => $name.' ('.$this->formatTimecode($sectionStart, $sectionStart + ($n * $beat)).')',
                    'timecode' => $this->formatTimecode($start, $end),
                    'visual_type' => $type,
                    'image_prompt' => $visual,
                    'motion_prompt' => $motion,
                    'vo' => $vo,
                    'on_screen' => $on,
                ];
                $t = $end;
            }

            $start = $t;
            $end = $t + $beat;
            $scenes[] = [
                'section' => $name.' ('.$this->formatTimecode($sectionStart, $end).')',
                'timecode' => $this->formatTimecode($start, $end),
                'visual_type' => 'bumper',
                'image_prompt' => 'Bumper KEA Abbasy (Video Intro / Logo KEA Abbasy)',
                'motion_prompt' => '(SFX Bumper / Jeda VO)',
                'vo' => '',
                'on_screen' => $sIdx < count($sectionDefs) - 1 ? $sectionDefs[$sIdx + 1][0] : 'OUTRO',
            ];
            $t = $end;
        }

        while ((count($scenes) * $beat) > ($total + $beat) && count($scenes) > 12) {
            for ($i = count($scenes) - 2; $i > 5; $i--) {
                if (($scenes[$i]['visual_type'] ?? '') !== 'bumper') {
                    array_splice($scenes, $i, 1);
                    break;
                }
            }
            $t = 0;
            foreach ($scenes as &$sc) {
                $sc['timecode'] = $this->formatTimecode($t, $t + $beat);
                $t += $beat;
            }
            unset($sc);
        }

        $minutes = max(1, (int) round($this->durationSeconds / 60));
        $voice = $this->voicePerspective;
        $caption = "Sering tiba-tiba merasa bersalah padahal kamu yang sedang diserang?\n\n"
            ."Di video ini (~{$minutes} menit) {$voice} membongkar anatomi {$shortTopic}: merebut bingkai, membalikkan keadaan, mengunci narasi lawan.\n\n"
            ."Tonton sampai habis. Tulis di komentar. 👇\n\n".$hashtags;

        return [
            'title' => 'Episode: '.Str::limit($theme, 100, ''),
            'cover_theme' => $cover,
            'story_concept' => "Master blueprint {$minutes} mnt (beat {$beat}s, ".($this->preferStock ? 'utamakan stock' : 'campur visual').") tentang {$shortTopic}: Hook → Bab → Penutup + bumper.",
            'scenes' => $scenes,
            'thumbnail_prompt' => "A vertical 9:16 cinematic dark psychology thumbnail. Moody high-contrast scene. At the top of the image, the text \"{$cover}\" is boldly displayed in a large, modern, glowing yellow font with a dark drop shadow. Professional film grain, no offensive elements.",
            'caption' => $caption,
            'title_options' => [
                'JANGAN PERNAH Jawab Pertanyaan Mereka (Taktik Mengunci Lawan)',
                'Cara Elegan Membungkam Orang yang Suka Menyudutkanmu',
                '1 Kalimat Pembungkam: Taktik Psikologi Membalikkan Keadaan',
                'Tiru Cara Orang Tajam Menghancurkan Pertanyaan Jebakan — '.$cover,
            ],
        ];
    }

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,mixed>>,thumbnail_prompt:string,caption:string,title_options:array<int,string>}
     */
    protected function buildReelsTextTemplate(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): array
    {
        $theme = $this->spokenTheme($title, $topic);
        $copy = $this->punchyVisualCopy($theme, $idea, $kit);
        $cover = $copy['cover'];
        [$line1, $line2] = $copy['lines'];
        $char = $kit->character_visual ?: 'A stickman figure made of thin black wireframe lines in a sharp executive tuxedo with a yellow rubber duck prop';
        $style = $kit->visual_style ?: 'Cinematic 9:16 vertical, Jakarta neon night, high contrast';
        $dur = max(5, $this->durationSeconds);
        $textClause = $this->bakedTextClause($line1, $line2);

        $image = "A vertical {$kit->aspect_ratio} cinematic still frame for a text-overlay Instagram/Facebook Reels. {$char} standing in a crowded neon-lit Jakarta street at night, high contrast rim light. {$style}. {$textClause} Clean composition, readable typography, film grain, no offensive elements. --ar 9:16";

        $motion = "Image to video, {$dur} seconds. Slow cinematic push-in toward the stickman character while neon bokeh drifts softly. Keep both title lines perfectly sharp, locked, and fully readable — do not warp, crop, or remove the text. Subtle parallax, natural handheld micro-motion, cinematic color grade.";

        return [
            'title' => Str::limit($theme, 120, ''),
            'cover_theme' => $cover,
            'story_concept' => "Reels text tempel {$dur}s: pesan jelas (masalah + solusi) → image → i2v → caption ber-solusi.",
            'scenes' => [[
                'section' => 'REELS TEXT TEMPEL',
                'timecode' => $this->formatTimecode(0, $dur),
                'visual_type' => 'ai',
                'vo' => '',
                'on_screen' => str_replace(' | ', "\n", $cover),
                'image_prompt' => $image,
                'motion_prompt' => $motion,
                'beat_seconds' => $dur,
            ]],
            'thumbnail_prompt' => $image,
            'caption' => $copy['caption'],
            'title_options' => [],
        ];
    }

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,mixed>>,thumbnail_prompt:string,caption:string,title_options:array<int,string>}
     */
    protected function buildFotoTemplate(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): array
    {
        $theme = $this->spokenTheme($title, $topic);
        $copy = $this->punchyVisualCopy($theme, $idea, $kit);
        $cover = $copy['cover'];
        [$line1, $line2] = $copy['lines'];
        $char = $kit->character_visual ?: 'A stickman figure made of thin black wireframe lines in a sharp executive tuxedo with a yellow rubber duck prop';
        $style = $kit->visual_style ?: 'Cinematic high-contrast urban night';
        $ratio = $kit->aspect_ratio ?: '4:5';
        $textClause = $this->bakedTextClause($line1, $line2);

        $image = "A vertical {$ratio} cinematic Facebook/Instagram feed photo. {$char} in a moody neon Jakarta alley, sharp focus, editorial lighting. {$style}. {$textClause} Typography must be perfectly readable — no extra editing needed. Ultra sharp, feed-ready, film grain, no offensive elements.";

        return [
            'title' => Str::limit($theme, 120, ''),
            'cover_theme' => $cover,
            'story_concept' => 'Postingan foto: teks 2 baris (diagnosis + solusi) baked-in + caption ber-solusi.',
            'scenes' => [[
                'section' => 'POSTINGAN FOTO',
                'timecode' => '',
                'visual_type' => 'ai',
                'vo' => '',
                'on_screen' => str_replace(' | ', "\n", $cover),
                'image_prompt' => $image,
                'motion_prompt' => '-',
                'beat_seconds' => 0,
            ]],
            'thumbnail_prompt' => $image,
            'caption' => $copy['caption'],
            'title_options' => [],
        ];
    }

    /**
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool}  $options
     */
    protected function applyDurationOptions(string $format, array $options): void
    {
        $this->beatSeconds = 5;
        $this->preferStock = array_key_exists('prefer_stock', $options)
            ? (bool) $options['prefer_stock']
            : true;

        if ($format === self::FORMAT_FOTO) {
            $this->durationSeconds = 0;
            $this->beatSeconds = 0;

            return;
        }

        if ($format === self::FORMAT_REELS_TEXT) {
            $sec = (int) ($options['duration_seconds'] ?? 8);
            $this->durationSeconds = max(5, min(30, $sec));
            $this->beatSeconds = $this->durationSeconds;

            return;
        }

        if ($format === self::FORMAT_REELS_PANJANG) {
            if (! empty($options['duration_seconds'])) {
                $sec = (int) $options['duration_seconds'];
            } elseif (! empty($options['duration_minutes'])) {
                $sec = ((int) $options['duration_minutes']) * 60;
            } else {
                $sec = 10 * 60;
            }
            $sec = max(3 * 60, min(20 * 60, $sec));
            $this->durationSeconds = (int) (round($sec / $this->beatSeconds) * $this->beatSeconds);
        } else {
            $sec = (int) ($options['duration_seconds'] ?? 15);
            $sec = max(10, min(90, $sec));
            $this->durationSeconds = (int) (round($sec / $this->beatSeconds) * $this->beatSeconds);
        }
    }

    protected function beatCount(): int
    {
        if ($this->beatSeconds <= 0) {
            return 1;
        }

        return max(2, intdiv($this->durationSeconds, $this->beatSeconds));
    }

    protected function formatTimecode(int $startSec, int $endSec): string
    {
        $fmt = static fn (int $s): string => sprintf('%02d:%02d', intdiv($s, 60), $s % 60);

        return $fmt($startSec).' - '.$fmt($endSec);
    }

    protected function coverTitle(string $topic, ?ContentIdea $idea): string
    {
        if ($idea?->title) {
            return $this->spokenTheme($idea->title, $idea->title);
        }

        return $this->spokenTheme($topic, $topic) ?: 'Ide Viral Psikologi';
    }

    protected function normalizeFormat(string $format): string
    {
        return in_array($format, self::FORMATS, true)
            ? $format
            : self::FORMAT_REELS_VO;
    }

    protected function isVisualPostFormat(string $format): bool
    {
        return in_array($format, [self::FORMAT_REELS_TEXT, self::FORMAT_FOTO], true);
    }

    protected function normalizePlannedTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m)) {
            $h = max(0, min(23, (int) $m[1]));
            $i = max(0, min(59, (int) $m[2]));

            return sprintf('%02d:%02d:00', $h, $i);
        }

        return null;
    }

    protected function cleanTopic(string $topic, ?ContentIdea $idea, ?TrendSignal $signal): string
    {
        if ($signal) {
            return $this->spokenTheme(
                (string) ($signal->keyword ?: $signal->title ?: $topic),
                (string) ($signal->keyword ?: $topic)
            );
        }

        if ($idea?->title) {
            return $this->spokenTheme($idea->title, $topic);
        }

        return $this->spokenTheme($topic, $topic);
    }

    protected function radarContext(?TrendSignal $signal): string
    {
        if (! $signal) {
            return '';
        }

        $parts = array_filter([
            'sumber: '.$signal->source,
            $signal->keyword ? 'keyword: '.$signal->keyword : null,
            $signal->metric_value ? 'skor_radar: '.$signal->metric_value : null,
            $signal->snippet ? 'catatan: '.Str::limit($signal->snippet, 160) : null,
        ]);

        return implode(' | ', $parts);
    }

    protected function analyticsContext(?AnalyticsAiAnalysis $analysis): string
    {
        if (! $analysis) {
            return '';
        }

        $playbook = collect($analysis->million_view_playbook ?? [])
            ->take(5)
            ->map(function ($step, $i) {
                if (! is_array($step)) {
                    return null;
                }
                $n = (int) ($step['priority'] ?? ($i + 1));

                return $n.'. '.trim((string) ($step['title'] ?? ''))
                    .' — '.trim((string) ($step['how'] ?? ''))
                    .(filled($step['example_title'] ?? null) ? ' | contoh judul: '.$step['example_title'] : '');
            })
            ->filter()
            ->implode("\n");

        $hooks = collect($analysis->title_hooks ?? [])->take(8)->filter()->map(fn ($h) => '- '.$h)->implode("\n");
        $working = collect($analysis->whats_working ?? [])->take(5)->filter()->map(fn ($h) => '- '.$h)->implode("\n");
        $blocking = collect($analysis->whats_blocking ?? [])->take(5)->filter()->map(fn ($h) => '- '.$h)->implode("\n");
        $avoid = collect($analysis->avoid ?? [])->take(5)->filter()->map(fn ($h) => '- '.$h)->implode("\n");

        $lines = array_filter([
            'REFERENSI ANALISA AI YOUTUBE (inspirasi sudut & packaging — JANGAN dibaca mentah di VO/caption):',
            'Diagnosis: '.Str::limit((string) $analysis->diagnosis, 400),
            $working !== '' ? "Yang sudah jalan:\n{$working}" : null,
            $blocking !== '' ? "Yang menghambat:\n{$blocking}" : null,
            $playbook !== '' ? "Playbook:\n{$playbook}" : null,
            $hooks !== '' ? "Hook/judul uji:\n{$hooks}" : null,
            $avoid !== '' ? "Hindari:\n{$avoid}" : null,
            'WAJIB: blueprint ini harus selaras dengan sinyal data di atas (retention/formula/emosi yang menang), bukan generic.',
        ]);

        return implode("\n", $lines);
    }

    protected function spokenTheme(string $title, string $fallback): string
    {
        $text = trim($title) !== '' ? $title : $fallback;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        // Buang prefix internal generator.
        $text = preg_replace('/^(Konten FB|Versi Kea Abbasy)\s*:\s*/iu', '', $text) ?? $text;

        // Buang metadata radar yang sering nempel setelah em dash / bullet.
        $text = preg_replace('/\s+[—\-]\s*(Google|YouTube|pantauan|minat relatif|metric|skor).*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\b(Google minat relatif|pantauan otomatis|YouTube\s*·).*$/iu', '', $text) ?? $text;
        $text = preg_replace('/\d+\s*\/\s*100/', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B—-·|");

        return Str::limit($text !== '' ? $text : 'psikologi', 80, '');
    }

    protected function spokenLine(?string $line): ?string
    {
        if ($line === null || trim($line) === '') {
            return null;
        }

        if ($this->looksLikeMetaInstruction($line) || $this->looksLikeWeakHook($line)) {
            return null;
        }

        $clean = $this->spokenTheme($line, $line);
        if (
            $this->looksLikeMetaInstruction($clean)
            || $this->looksLikeWeakHook($clean)
            || $this->looksLikeRadarNoise($clean)
            || $this->looksLikeRadarNoise($line)
        ) {
            return null;
        }

        return $clean;
    }

    protected function looksLikeRadarNoise(string $text): bool
    {
        return (bool) preg_match('/google minat|pantauan otomatis|minat relatif|youtube\s*·|\/\s*100|metric_value|viral_score/i', $text);
    }

    protected function looksLikeMetaInstruction(string $text): bool
    {
        return (bool) preg_match('/radar otomatis|buat sudut|tulis ulang|jangan copy|jangan dibaca|relatable \+ insight|auto radar|seed\)|sudut personal kea/i', $text);
    }

    protected function looksLikeWeakHook(string $text): bool
    {
        return (bool) preg_match('/^\s*(pernahkah kamu|apakah kamu|tahukah kamu|hai\b|halo\b|guys\b|di video|hari ini aku|kali ini kita|mari kita|banyak orang tidak tahu|kalau kamu lagi ngerasa)/iu', $text);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{section:string,timecode:string,visual_type:string,vo:string,on_screen:string,image_prompt:string,motion_prompt:string}
     */
    protected function normalizeScene(array $raw): array
    {
        $vo = $this->firstString($raw, ['vo', 'naskah', 'naskah_vo', 'voiceover', 'voice_over', 'script', 'narasi']);
        $onScreen = $this->firstString($raw, ['on_screen', 'teks', 'teks_on_screen', 'text', 'caption_screen', 'overlay']);
        $image = $this->firstString($raw, ['image_prompt', 'visual', 'visual_prompt', 'prompt_gambar', 'gambar']);
        $motion = $this->firstString($raw, ['motion_prompt', 'motion', 'animasi', 'keterangan']);
        $type = Str::lower($this->firstString($raw, ['visual_type', 'type']) ?: 'ai');
        if (! in_array($type, ['ai', 'stock', 'black', 'bumper'], true)) {
            $type = 'ai';
        }

        // Reels pendek tidak boleh bumper kosong.
        if ($type === 'bumper' && $vo === '') {
            $type = 'ai';
        }

        return [
            'section' => $this->firstString($raw, ['section', 'bab']) ?: 'REELS VO',
            'timecode' => $this->firstString($raw, ['timecode', 'durasi', 'time']) ?: '',
            'visual_type' => $type,
            'vo' => $vo,
            'on_screen' => $onScreen,
            'image_prompt' => $image,
            'motion_prompt' => $motion,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $keys
     */
    protected function firstString(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, string>>  $scenes
     */
    protected function scenesAreUsable(array $scenes, string $format): bool
    {
        if ($scenes === []) {
            return false;
        }

        if ($format === self::FORMAT_FOTO) {
            $img = trim((string) ($scenes[0]['image_prompt'] ?? ''));

            return $img !== '';
        }

        if ($format === self::FORMAT_REELS_TEXT) {
            $img = trim((string) ($scenes[0]['image_prompt'] ?? ''));
            $motion = trim((string) ($scenes[0]['motion_prompt'] ?? ''));

            return $img !== '' && $motion !== '' && $motion !== '-';
        }

        $withVo = 0;
        $required = 0;
        foreach ($scenes as $scene) {
            $type = $scene['visual_type'] ?? 'ai';
            $vo = trim((string) ($scene['vo'] ?? ''));
            if ($format === self::FORMAT_REELS_VO) {
                $required++;
                if ($vo !== '') {
                    $withVo++;
                }
            } elseif ($type !== 'bumper') {
                $required++;
                if ($vo !== '') {
                    $withVo++;
                }
            }
        }

        if ($format === self::FORMAT_REELS_VO) {
            // Minimal 80% scene harus punya naskah; beat pertama wajib.
            $firstVo = trim((string) ($scenes[0]['vo'] ?? ''));

            return $firstVo !== '' && $withVo >= max(2, (int) ceil(count($scenes) * 0.8));
        }

        return $required > 0 && $withVo >= max(5, (int) ceil($required * 0.6));
    }

    protected function ensureImagePromptHasText(string $prompt, string $coverTheme, BrandKit $kit, string $format): string
    {
        return $this->ensureThumbnailHasTitle($prompt, $coverTheme, $kit, $format);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function ensureVisualClarity(array $payload, string $topic, ?ContentIdea $idea, BrandKit $kit, string $format): array
    {
        $cover = (string) ($payload['cover_theme'] ?? '');
        $coverWasVague = $this->looksLikeVagueCover($cover);
        $copy = $this->punchyVisualCopy($this->spokenTheme($cover !== '' ? $cover : $topic, $topic), $idea, $kit);

        if ($coverWasVague) {
            $payload['cover_theme'] = $copy['cover'];
            $cover = $copy['cover'];
        }

        if ($this->captionMissingSolution((string) ($payload['caption'] ?? ''))) {
            $payload['caption'] = $copy['caption'];
        }

        [$line1, $line2] = $this->splitCoverLines($cover);
        $textClause = $this->bakedTextClause($line1, $line2);

        if (! empty($payload['scenes'][0]) && is_array($payload['scenes'][0])) {
            $payload['scenes'][0]['on_screen'] = str_replace(' | ', "\n", $cover);
            $img = trim((string) ($payload['scenes'][0]['image_prompt'] ?? ''));
            if ($coverWasVague || $img === '' || ! $this->promptHasCoverLines($img, $line1, $line2)) {
                if ($img !== '' && ! $coverWasVague && str_contains($img, '"'.$line1.'"')) {
                    // Prompt visual + judul ada — sisipkan footer kecil / lengkapi teks.
                    $payload['scenes'][0]['image_prompt'] = rtrim($img, " \t\n\r\0\x0B.").'. '.$textClause.'.';
                } else {
                    $char = $kit->character_visual ?: 'stickman wireframe executive tuxedo with yellow rubber duck';
                    $style = $kit->visual_style ?: 'Cinematic high contrast urban night';
                    $ratio = $format === self::FORMAT_FOTO ? ($kit->aspect_ratio ?: '4:5') : ($kit->aspect_ratio ?: '9:16');
                    $payload['scenes'][0]['image_prompt'] = "A vertical {$ratio} cinematic social media frame. {$char}. {$style}. {$textClause}. High contrast, readable typography, film grain, no offensive elements.";
                }
            }
            $payload['thumbnail_prompt'] = $payload['scenes'][0]['image_prompt'];
        }

        return $payload;
    }

    protected function ensureSolutionCaption(string $caption, string $coverTheme, string $topic, BrandKit $kit): string
    {
        $caption = trim($caption);
        $copy = $this->punchyVisualCopy($this->spokenTheme($coverTheme, $topic), null, $kit);
        if ($caption === '') {
            return $copy['caption'];
        }

        $hashtags = trim((string) ($kit->caption_hashtags ?: '#keaabbasy #psikologigelap #reelsviral'));
        $body = preg_replace('/\n?\.(\n\.)+\n?.*/s', '', $caption) ?? $caption;
        $body = trim(preg_replace('/#\w+/u', '', $body) ?? $body);
        $body = trim(preg_replace('/\n*(Like,? share.*|like · share.*|Follow untuk konten.*|Simpan\. Share ke.*)$/iu', '', $body) ?? $body);

        if ($this->captionMissingSolution($body)) {
            $body .= "\n\nSolusi:\n"
                ."- Sadari polanya, jangan normalisasi rasa cemas/bersalah yang dipelihara orang lain.\n"
                ."- Set batas jelas: kontak, topik, dan akses emosional yang merusakmu.\n"
                ."- Kalau mereka menolak batasmu, itu bukti — bukan alasan untuk bertahan.";
        }

        return $body."\n\n".$this->engagementCtaLine()."\n.\n.\n.\n".$hashtags;
    }

    /**
     * @return array{cover:string,lines:array{0:string,1:string},caption:string}
     */
    protected function punchyVisualCopy(string $theme, ?ContentIdea $idea, BrandKit $kit): array
    {
        $theme = $this->spokenTheme($theme, $theme);
        $hashtags = trim((string) ($kit->caption_hashtags ?: '#keaabbasy #psikologigelap #reelsviral'));
        $t = Str::lower($theme);

        if (preg_match('/cinta|toxic|beracun|hubungan|pacar|pasangan/u', $t)) {
            $line1 = 'CINTA BERACUN BUKAN CINTA';
            $line2 = 'JIKA TERUS CEMAS — PERGI';
            $hook = 'Kalau setiap hari yang dirawat cuma luka dan rasa cemas, itu bukan cinta — itu kontrol.';
            $insight = 'Cinta yang sehat tidak membuatmu takut kehilangan diri sendiri. Kalau kamu harus mengecil supaya mereka nyaman, polanya sudah salah.';
            $steps = [
                'Tulis batas non-negotiable-mu (kontak, nada bicara, gaslighting).',
                'Komunikasikan sekali dengan tenang. Pantau apakah mereka hormati atau malah memutar kesalahan ke kamu.',
                'Kalau batas ditolak, jarakkan. Melindungi diri bukan kejam — itu waras.',
            ];
        } elseif (preg_match('/bingkai|frame|pertanyaan jebakan|menyudutkan|manipulasi/u', $t)) {
            $line1 = 'JANGAN JAWAB PERTANYAAN JEBAKAN';
            $line2 = 'BALIKKAN BINGKAINYA DULU';
            $hook = 'Pertanyaan tajam sering bukan minta jawaban — itu jebakan bingkai.';
            $insight = 'Kalau kamu langsung membela diri, kamu sudah kalah di medan yang mereka pilih.';
            $steps = [
                'Tahan 2 detik. Jangan buru-buru menjelaskan.',
                'Tanyakan balik asumsi di dalam pertanyaan mereka.',
                'Buat aturan main baru: jawab hanya di bingkai yang adil.',
            ];
        } elseif (preg_match('/bersalah|guilt|gaslight/u', $t)) {
            $line1 = 'MERASA BERSALAH TERUS?';
            $line2 = 'ITU BUKAN SALAHMU — ITU KONTROL';
            $hook = 'Rasa bersalah yang tiba-tiba sering bukan nurani — itu produk gaslighting.';
            $insight = 'Orang yang mengontrol akan membuatmu merasa berhutang setiap kali kamu menjaga diri.';
            $steps = [
                'Tanya: “Apa faktanya, atau cuma perasaan yang mereka tanam?”',
                'Jangan minta maaf untuk batas yang sehat.',
                'Catat pola: kapan rasa bersalah muncul, siapa yang untung.',
            ];
        } else {
            $short = Str::upper(Str::limit($theme, 28, ''));
            $line1 = $short !== '' ? $short : 'INI BUKAN KEBIASAAN';
            $line2 = 'INI JEBAKAN — PUTUS POLANYA';
            $hook = $this->spokenLine($idea?->hook) ?: "{$theme} sering terasa biasa — padahal itu pola yang mengikis kendali.";
            $insight = $this->spokenLine($idea?->angle) ?: 'Selama kamu hanya menyadari tanpa bertindak, polanya yang menang.';
            $steps = [
                'Namai polanya dengan satu kalimat jujur.',
                'Pilih 1 batas kecil yang bisa kamu tegakkan hari ini.',
                'Ulangi batas itu konsisten — jangan tawar lagi dengan rasa takut.',
            ];
        }

        $cover = $line1.' | '.$line2;
        $caption = $hook."\n\n".$insight."\n\nSolusi:\n"
            .'- '.$steps[0]."\n"
            .'- '.$steps[1]."\n"
            .'- '.$steps[2]."\n\n"
            .$this->engagementCtaLine()."\n.\n.\n.\n".$hashtags;

        return [
            'cover' => $cover,
            'lines' => [$line1, $line2],
            'caption' => $caption,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitCoverLines(string $cover): array
    {
        $cover = trim(str_replace(["\r\n", "\r"], "\n", $cover));
        if (str_contains($cover, '|')) {
            $parts = array_values(array_filter(array_map('trim', explode('|', $cover))));
        } elseif (str_contains($cover, "\n")) {
            $parts = array_values(array_filter(array_map('trim', explode("\n", $cover))));
        } else {
            $parts = [Str::upper($cover)];
        }

        $line1 = Str::upper($parts[0] ?? 'PESAN UTAMA');
        $line2 = Str::upper($parts[1] ?? 'LAKUKAN BATAS SEKARANG');

        return [$line1, $line2];
    }

    protected function imageFooterLine1(): string
    {
        return 'Lihat caption selengkapnya';
    }

    protected function imageFooterLine2(): string
    {
        return 'Like · Share · Follow untuk konten psikologi lainnya';
    }

    protected function engagementCtaLine(): string
    {
        return 'Like, share, dan follow buat konten psikologi lainnya. 👇';
    }

    protected function bakedTextClause(string $line1, string $line2): string
    {
        $f1 = $this->imageFooterLine1();
        $f2 = $this->imageFooterLine2();

        return 'At the top of the image, the text "'.$line1.'" is boldly displayed on the first line and the text "'.$line2.'" directly below it on the second line, both in a large modern glowing yellow Anton-style font with a dark drop shadow, perfectly readable. Near the bottom of the image, in much smaller subtle soft-white text (about 20-30% the size of the main title, low visual weight, not competing with the headline), include the exact text "'.$f1.'" and directly under it "'.$f2.'"';
    }

    protected function promptHasCoverLines(string $prompt, string $line1, string $line2): bool
    {
        return str_contains($prompt, '"'.$line1.'"')
            && str_contains($prompt, '"'.$line2.'"')
            && str_contains($prompt, '"'.$this->imageFooterLine1().'"')
            && str_contains($prompt, '"'.$this->imageFooterLine2().'"');
    }

    protected function looksLikeVagueCover(string $cover): bool
    {
        $cover = trim($cover);
        if ($cover === '') {
            return true;
        }

        // Sudah 2 baris dengan aksi = OK.
        if ((str_contains($cover, '|') || str_contains($cover, "\n"))
            && preg_match('/\b(bukan|stop|jangan|putus|pergi|lepas|batas|balikkan|tolak|keluar|lakukan|sekarang|tinggalkan)\b/iu', $cover)
        ) {
            return false;
        }

        // Label tema tanpa perintah/solusi = abu-abu.
        return ! (bool) preg_match('/\b(bukan|stop|jangan|putus|pergi|lepas|batas|cara|jika|maka|tinggalkan|lawan|tolak|keluar|balikkan|lakukan)\b/iu', $cover);
    }

    protected function captionMissingSolution(string $caption): bool
    {
        if (trim($caption) === '') {
            return true;
        }

        return ! (bool) preg_match('/\b(solusi|langkah|lakukan|batas|putus|pergi|tinggalkan|tolak|ubah pola|set batas|jarakkan|balikkan)\b/iu', $caption);
    }

    protected function ensureThumbnailHasTitle(string $prompt, string $coverTheme, BrandKit $kit, string $format): string
    {
        [$line1, $line2] = $this->splitCoverLines($coverTheme !== '' ? $coverTheme : 'JUDUL COVER');
        $prompt = trim($prompt);
        $titleClause = $this->bakedTextClause($line1, $line2);

        if ($prompt !== '' && $this->promptHasCoverLines($prompt, $line1, $line2)) {
            return $prompt;
        }

        // Single-line legacy cover still accepted if exact quote present.
        $legacy = Str::upper(trim(str_replace('|', ' ', $coverTheme)));
        if ($prompt !== '' && $legacy !== '' && str_contains($prompt, '"'.$legacy.'"')) {
            return rtrim($prompt, " \t\n\r\0\x0B.").'. '.$titleClause.'.';
        }

        if ($prompt !== '') {
            // Buang frasa generik tanpa judul, lalu sisipkan judul exact.
            $prompt = preg_replace('/,?\s*(bold\s+)?(moody\s+)?(anton\s+)?title overlay aesthetic\.?/i', '', $prompt) ?? $prompt;

            return rtrim($prompt, " \t\n\r\0\x0B.").'. '.$titleClause.'.';
        }

        if ($format === self::FORMAT_REELS_PANJANG) {
            return 'A vertical 9:16 cinematic dark psychology thumbnail. Moody high-contrast scene. '.$titleClause.'. Professional film grain, high contrast, no offensive elements.';
        }

        if ($format === self::FORMAT_FOTO) {
            $char = $kit->character_visual ?: 'stickman wireframe executive in tuxedo holding a yellow rubber duck';
            $style = $kit->visual_style ?: 'Cinematic high contrast urban night';

            return 'A vertical '.($kit->aspect_ratio ?: '4:5').' cinematic social media photo. '.$char.'. '.$style.'. '.$titleClause.'. Ultra sharp, feed-ready, no offensive elements.';
        }

        if ($format === self::FORMAT_REELS_TEXT) {
            $char = $kit->character_visual ?: 'stickman wireframe executive in tuxedo holding a yellow rubber duck';
            $style = $kit->visual_style ?: 'Cinematic 9:16 vertical, Jakarta neon night';

            return 'A vertical '.($kit->aspect_ratio ?: '9:16').' cinematic still frame for text-overlay reels. '.$char.' in a bustling neon-lit Jakarta street at night. '.$style.'. '.$titleClause.'. High contrast, clean negative space for title, film grain.';
        }

        $char = $kit->character_visual ?: 'stickman wireframe executive in tuxedo holding a yellow rubber duck';

        return 'A vertical '.($kit->aspect_ratio ?: '9:16').' cinematic graphic design for a video thumbnail. '.$char.' in a bustling neon-lit Jakarta street at night. '.$titleClause.'. High contrast, urban cinematic aesthetic, professional film grain, no offensive elements.';
    }

    protected function normalizeVoicePerspective(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Aku';
        }

        $aliases = [
            'aku' => 'Aku',
            'saya' => 'Saya',
            'gue' => 'Gue',
            'kamu' => 'Kamu',
            'anda' => 'Anda',
            'kita' => 'Kita',
            'dia' => 'Dia',
            'mereka' => 'Mereka',
            'orang ketiga' => 'Orang ketiga',
            '3rd' => 'Orang ketiga',
            'third person' => 'Orang ketiga',
        ];

        $key = Str::lower($value);

        return $aliases[$key] ?? Str::limit($value, 40, '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     * @return array<int, array<string, mixed>>
     */
    protected function ensureSceneTimecodes(array $scenes): array
    {
        if ($this->beatSeconds <= 0) {
            $out = [];
            foreach ($scenes as $scene) {
                if (! is_array($scene)) {
                    continue;
                }
                $scene['beat_seconds'] = 0;
                $out[] = $scene;
            }

            return $out;
        }

        $beat = max(1, $this->beatSeconds);
        $t = 0;
        $out = [];

        foreach ($scenes as $scene) {
            if (! is_array($scene)) {
                continue;
            }
            $timecode = trim((string) ($scene['timecode'] ?? ''));
            if ($timecode === '' || ! preg_match('/\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}/', $timecode)) {
                $scene['timecode'] = $this->formatTimecode($t, $t + $beat);
            }
            $scene['beat_seconds'] = $beat;
            $out[] = $scene;
            $t += $beat;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     */
    protected function ensureCaption(string $caption, array $scenes, string $coverTheme, string $topic, BrandKit $kit): string
    {
        if (trim($caption) !== '') {
            return trim($caption);
        }

        $firstVo = '';
        foreach ($scenes as $scene) {
            $vo = trim((string) ($scene['vo'] ?? ''));
            if ($vo !== '') {
                $firstVo = $vo;
                break;
            }
        }

        $theme = $this->spokenTheme($coverTheme, $topic);
        $hashtags = trim((string) ($kit->caption_hashtags ?: '#keaabbasy #psikologigelap #reelsviral'));
        $voice = $this->voicePerspective;
        $dur = $this->humanDuration($this->durationSeconds);

        $hook = $firstVo !== '' ? $firstVo : "{$voice} bongkar pola gelap di balik {$theme}.";

        return $hook."\n\n"
            ."Realitanya, {$theme} sering terasa biasa — sampai {$voice} sadar biayanya terlalu mahal.\n\n"
            ."Video ~{$dur}. Setuju atau tidak? Tulis di komentar. 👇\n.\n.\n.\n"
            .$hashtags;
    }

    protected function humanDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds.' detik';
        }

        $minutes = intdiv($seconds, 60);
        $rem = $seconds % 60;
        if ($rem === 0) {
            return $minutes.' menit';
        }

        return $minutes.' menit '.$rem.' detik';
    }
}
