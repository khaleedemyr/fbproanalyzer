<?php

namespace App\Services\Blueprint;

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

    protected string $lastEngine = 'template';

    protected ?string $lastWarning = null;

    protected bool $wasDuplicate = false;

    protected int $durationSeconds = 15;

    protected int $beatSeconds = 5;

    protected bool $preferStock = true;

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
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool,force?:bool}  $options
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

        $this->lastEngine = 'template';
        $this->lastWarning = null;
        $this->wasDuplicate = false;

        $cleanTopic = $this->cleanTopic($topic, $idea, $signal);
        $cleanTitle = $this->coverTitle($cleanTopic, $idea);
        $radarContext = $this->radarContext($signal);

        // Judul yang sama (per format) tidak digenerate ulang — tema/topik tetap bebas.
        if (! $force) {
            $existing = $this->findExistingByTitle($user, $cleanTitle, $format);
            if ($existing) {
                $this->wasDuplicate = true;
                $this->lastEngine = (string) ($existing->engine ?: 'template');

                return $existing;
            }
        }

        $gemini = $this->tryGemini($kit, $cleanTitle, $cleanTopic, $idea, $format, $radarContext);
        if ($gemini !== null) {
            $this->lastEngine = 'gemini';
            $payload = $gemini;
        } else {
            $payload = $format === self::FORMAT_REELS_PANJANG
                ? $this->buildLongTemplate($kit, $cleanTitle, $cleanTopic, $idea)
                : $this->buildShortTemplate($kit, $cleanTitle, $cleanTopic, $idea);
        }

        $payload['cover_theme'] = Str::upper(trim((string) ($payload['cover_theme'] ?: $cleanTitle)));
        $payload['thumbnail_prompt'] = $this->ensureThumbnailHasTitle(
            (string) ($payload['thumbnail_prompt'] ?? ''),
            (string) $payload['cover_theme'],
            $kit,
            $format
        );

        return ContentBlueprint::query()->create([
            'user_id' => $user->id,
            'brand_kit_id' => $kit->id,
            'content_idea_id' => $idea?->id,
            'trend_signal_id' => $signal?->id,
            'title' => $payload['title'],
            'cover_theme' => $payload['cover_theme'],
            'story_concept' => $payload['story_concept'],
            'source_topic' => $cleanTopic,
            'status' => $plannedDate ? 'scheduled' : 'ready',
            'format' => $format,
            'engine' => $this->lastEngine,
            'duration_seconds' => $this->durationSeconds,
            'beat_seconds' => $this->beatSeconds,
            'prefer_stock' => $format === self::FORMAT_REELS_PANJANG ? $this->preferStock : false,
            'scenes' => $payload['scenes'],
            'thumbnail_prompt' => $payload['thumbnail_prompt'],
            'caption' => $payload['caption'],
            'title_options' => $payload['title_options'] ?? null,
            'planned_date' => $plannedDate,
        ]);
    }

    /**
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool,force?:bool}  $options
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
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool,force?:bool}  $options
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
        if (($bp->format ?? self::FORMAT_REELS_VO) === self::FORMAT_REELS_PANJANG) {
            return $this->toLongMarkdown($bp, $kit);
        }

        $lines = [
            '🎬 Blueprint Reels Cinematic (Reels VO)',
            'Tema / Judul Cover: "'.$bp->cover_theme.'"',
            'Konsep Cerita Film: '.$bp->story_concept,
            '',
            'Durasi / Detik | Naskah Voiceover (VO) (Sudut Pandang "'.$kit->voice_perspective.'") | Teks On-Screen (CapCut) | 🎨 Prompt Gambar ('.$kit->image_tool.' - '.$kit->aspect_ratio.' Cinematic) | 🎬 Prompt Animasi ('.$kit->video_tool.' / Gerakan Kamera)',
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

    protected function toLongMarkdown(ContentBlueprint $bp, BrandKit $kit): string
    {
        $lines = [
            $bp->title,
            '🎬 BLUEPRINT REELS PANJANG / VOD',
            'Tema: "'.$bp->cover_theme.'"',
            'Konsep: '.$bp->story_concept,
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

        $prompt = $format === self::FORMAT_REELS_PANJANG
            ? $this->geminiLongPrompt($kit, $title, $topic, $idea, $radarContext)
            : $this->geminiShortPrompt($kit, $title, $topic, $idea, $radarContext);

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
                if (! is_array($decoded) || empty($decoded['scenes']) || ! is_array($decoded['scenes'])) {
                    $errors[] = "{$model}: JSON blueprint tidak valid";

                    continue;
                }

                $scenes = array_values(array_map(
                    fn ($s) => $this->normalizeScene(is_array($s) ? $s : []),
                    $decoded['scenes']
                ));

                if (! $this->scenesAreUsable($scenes, $format)) {
                    $filled = count(array_filter($scenes, fn ($s) => trim((string) ($s['vo'] ?? '')) !== ''));
                    $errors[] = "{$model}: naskah VO kosong/invalid ({$filled}/".count($scenes).' scene berisi VO)';

                    continue;
                }

                return [
                    'title' => (string) ($decoded['title'] ?? $title),
                    'cover_theme' => (string) ($decoded['cover_theme'] ?? Str::upper($title)),
                    'story_concept' => (string) ($decoded['story_concept'] ?? ''),
                    'scenes' => $scenes,
                    'thumbnail_prompt' => (string) ($decoded['thumbnail_prompt'] ?? ''),
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
- Sudut VO: "{$kit->voice_perspective}" (bahasa Indonesia tajam, sinis cerdas, bukan ceramah)
- Hashtag: {$kit->caption_hashtags}

Tema: {$title}
Topik: {$topic}
Hook ide (boleh diabaikan jika lemah/klise): {$hook}
Angle: {$angle}
{$radarBlock}
DURASI: {$dur} detik · beat {$beat}s · tepat {$beats} scene.

===== WAJIB NASKAH =====
- Ini REELS VO PENDEK: SETIAP scene WAJIB punya "vo" terisi (1–2 kalimat).
- DILARANG vo kosong / "" / null.
- DILARANG visual_type "bumper" (bumper hanya untuk video panjang).
- DILARANG scene yang cuma visual tanpa naskah.

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
vo contoh: "Aku mending keliatan biasa tapi aset nambah, daripada sok sempurna tapi dalamnya hancur."

JSON SAJA, tepat {$beats} scene, SEMUA vo terisi.
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
Hashtag: {$kit->caption_hashtags}
{$radarBlock}

===== GOLDEN HOOK WAJIB BERBASIS REALITA =====
- 60–120 detik pertama: CERITA NYATA tokoh dunia / kasus publik (Elon, Jobs, Churchill, Seneca, CEO, dll) relevan topik.
- Format: tahun + situasi + konflik + twist → cliff ke topik.
- 3 beat pertama WAJIB punya vo terisi (bukan bumper).

DILARANG KERAS pembuka:
{$banned}

===== NASKAH =====
- Hanya scene visual_type "bumper" yang boleh vo kosong.
- Semua scene stock/ai/black WAJIB punya vo + on_screen.
- Hook naskah + visual = aset utama.

ATURAN: no metrik radar. Thumbnail WAJIB teks judul di quotes.
Struktur: GOLDEN HOOK → BAB1 → BAB2 → BAB3 → PENUTUP (+ bumper antar bab saja).
JSON SAJA.
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
        $voice = $kit->voice_perspective ?: 'Aku';
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
        $caption = "Sering tiba-tiba merasa bersalah padahal kamu yang sedang diserang?\n\n"
            ."Di video ini (~{$minutes} menit) aku membongkar anatomi {$shortTopic}: merebut bingkai, membalikkan keadaan, mengunci narasi lawan.\n\n"
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
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,prefer_stock?:bool}  $options
     */
    protected function applyDurationOptions(string $format, array $options): void
    {
        $this->beatSeconds = 5;
        $this->preferStock = array_key_exists('prefer_stock', $options)
            ? (bool) $options['prefer_stock']
            : true;

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
        return in_array($format, [self::FORMAT_REELS_VO, self::FORMAT_REELS_PANJANG], true)
            ? $format
            : self::FORMAT_REELS_VO;
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

    protected function ensureThumbnailHasTitle(string $prompt, string $coverTheme, BrandKit $kit, string $format): string
    {
        $cover = trim($coverTheme) !== '' ? Str::upper($coverTheme) : 'JUDUL COVER';
        $prompt = trim($prompt);
        $titleClause = 'At the top of the image, the text "'.$cover.'" is boldly displayed in a large, modern, glowing yellow Anton-style font with a dark drop shadow';

        if ($prompt !== '' && (str_contains($prompt, '"'.$cover.'"') || preg_match('/the text\s+"'.preg_quote($cover, '/').'"/i', $prompt))) {
            return $prompt;
        }

        if ($prompt !== '') {
            // Buang frasa generik tanpa judul, lalu sisipkan judul exact.
            $prompt = preg_replace('/,?\s*(bold\s+)?(moody\s+)?(anton\s+)?title overlay aesthetic\.?/i', '', $prompt) ?? $prompt;

            return rtrim($prompt, " \t\n\r\0\x0B.").'. '.$titleClause.'.';
        }

        if ($format === self::FORMAT_REELS_PANJANG) {
            return 'A vertical 9:16 cinematic dark psychology thumbnail. Moody high-contrast scene. '.$titleClause.'. Professional film grain, high contrast, no offensive elements.';
        }

        $char = $kit->character_visual ?: 'stickman wireframe executive in tuxedo holding a yellow rubber duck';

        return 'A vertical '.($kit->aspect_ratio ?: '9:16').' cinematic graphic design for a video thumbnail. '.$char.' in a bustling neon-lit Jakarta street at night. '.$titleClause.'. High contrast, urban cinematic aesthetic, professional film grain, no offensive elements.';
    }
}
