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

    public function lastEngine(): string
    {
        return $this->lastEngine;
    }

    public function lastWarning(): ?string
    {
        return $this->lastWarning;
    }

    public function generateFromTopic(
        User $user,
        string $topic,
        ?BrandKit $brandKit = null,
        ?ContentIdea $idea = null,
        ?TrendSignal $signal = null,
        ?string $plannedDate = null,
        string $format = self::FORMAT_REELS_VO,
    ): ContentBlueprint {
        $kit = $brandKit ?? BrandKit::defaultFor($user);
        $topic = trim($topic) !== '' ? trim($topic) : 'Topik psikologi viral';
        $title = $this->coverTitle($topic, $idea);
        $format = $this->normalizeFormat($format);

        $this->lastEngine = 'template';
        $this->lastWarning = null;

        $gemini = $this->tryGemini($kit, $title, $topic, $idea, $format);
        if ($gemini !== null) {
            $this->lastEngine = 'gemini';
            $payload = $gemini;
        } else {
            $payload = $format === self::FORMAT_REELS_PANJANG
                ? $this->buildLongTemplate($kit, $title, $topic, $idea)
                : $this->buildShortTemplate($kit, $title, $topic, $idea);
        }

        return ContentBlueprint::query()->create([
            'user_id' => $user->id,
            'brand_kit_id' => $kit->id,
            'content_idea_id' => $idea?->id,
            'trend_signal_id' => $signal?->id,
            'title' => $payload['title'],
            'cover_theme' => $payload['cover_theme'],
            'story_concept' => $payload['story_concept'],
            'source_topic' => $topic,
            'status' => $plannedDate ? 'scheduled' : 'ready',
            'format' => $format,
            'scenes' => $payload['scenes'],
            'thumbnail_prompt' => $payload['thumbnail_prompt'],
            'caption' => $payload['caption'],
            'title_options' => $payload['title_options'] ?? null,
            'planned_date' => $plannedDate,
        ]);
    }

    public function generateFromIdea(
        User $user,
        ContentIdea $idea,
        ?string $plannedDate = null,
        string $format = self::FORMAT_REELS_VO,
    ): ContentBlueprint {
        $topic = $idea->title;
        if ($idea->hook) {
            $topic .= ' — '.$idea->hook;
        }

        return $this->generateFromTopic($user, $topic, null, $idea, null, $plannedDate, $format);
    }

    public function generateFromSignal(
        User $user,
        TrendSignal $signal,
        ?string $plannedDate = null,
        string $format = self::FORMAT_REELS_VO,
    ): ContentBlueprint {
        $topic = $signal->title ?: $signal->keyword;
        if ($signal->snippet) {
            $topic .= ' — '.Str::limit($signal->snippet, 120);
        }

        return $this->generateFromTopic($user, (string) $topic, null, null, $signal, $plannedDate, $format);
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
    protected function tryGemini(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea, string $format): ?array
    {
        $apiKey = trim((string) config('blueprint.gemini_api_key', ''));
        if ($apiKey === '') {
            $this->lastWarning = 'GEMINI_API_KEY belum terisi / belum kebaca config. Fallback template.';

            return null;
        }

        $model = config('blueprint.gemini_model', 'gemini-2.0-flash');
        $prompt = $format === self::FORMAT_REELS_PANJANG
            ? $this->geminiLongPrompt($kit, $title, $topic, $idea)
            : $this->geminiShortPrompt($kit, $title, $topic, $idea);

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
                            'temperature' => 0.85,
                            'responseMimeType' => 'application/json',
                        ],
                    ]
                );

            if (! $response->successful()) {
                $err = data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status());
                $this->lastWarning = 'Gemini gagal: '.$err.' — pakai template.';

                return null;
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            if (! is_string($text) || $text === '') {
                $block = data_get($response->json(), 'candidates.0.finishReason')
                    ?: data_get($response->json(), 'promptFeedback.blockReason');
                $this->lastWarning = 'Gemini kosong'.($block ? " ({$block})" : '').' — pakai template.';

                return null;
            }

            $decoded = json_decode($text, true);
            if (! is_array($decoded) || empty($decoded['scenes']) || ! is_array($decoded['scenes'])) {
                $this->lastWarning = 'Respons Gemini bukan JSON blueprint valid — pakai template.';

                return null;
            }

            return [
                'title' => (string) ($decoded['title'] ?? $title),
                'cover_theme' => (string) ($decoded['cover_theme'] ?? Str::upper($title)),
                'story_concept' => (string) ($decoded['story_concept'] ?? ''),
                'scenes' => array_values(array_map(fn ($s) => [
                    'section' => (string) ($s['section'] ?? ''),
                    'timecode' => (string) ($s['timecode'] ?? ''),
                    'visual_type' => (string) ($s['visual_type'] ?? 'ai'),
                    'vo' => (string) ($s['vo'] ?? ''),
                    'on_screen' => (string) ($s['on_screen'] ?? ''),
                    'image_prompt' => (string) ($s['image_prompt'] ?? ''),
                    'motion_prompt' => (string) ($s['motion_prompt'] ?? ''),
                ], $decoded['scenes'])),
                'thumbnail_prompt' => (string) ($decoded['thumbnail_prompt'] ?? ''),
                'caption' => (string) ($decoded['caption'] ?? ''),
                'title_options' => array_values(array_map('strval', $decoded['title_options'] ?? [])),
            ];
        } catch (\Throwable $e) {
            $this->lastWarning = 'Gemini error: '.$e->getMessage().' — pakai template.';

            return null;
        }
    }

    protected function geminiShortPrompt(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): string
    {
        $hook = $idea?->hook ?: '';
        $angle = $idea?->angle ?: '';

        return <<<PROMPT
Kamu adalah penulis blueprint Reels VO cinematic pendek (~15 detik) untuk brand "{$kit->name}" niche {$kit->niche}.
Karakter WAJIB konsisten di SEMUA prompt gambar/animasi:
- Nama: {$kit->character_name}
- Visual: {$kit->character_visual}
- Style: {$kit->visual_style}
- Aspect: {$kit->aspect_ratio}
- Font on-screen: {$kit->on_screen_font}
- Sudut VO: "{$kit->voice_perspective}" (bahasa Indonesia santai, tajam, psikologi gelap)
- Hashtag: {$kit->caption_hashtags}

Tema: {$title}
Topik: {$topic}
Hook: {$hook}
Angle: {$angle}

JSON SAJA:
{
  "title": "...",
  "cover_theme": "JUDUL COVER",
  "story_concept": "...",
  "scenes": [
    {
      "section": "HOOK",
      "timecode": "00:00 - 00:05",
      "visual_type": "ai",
      "vo": "...",
      "on_screen": "TEKS\\n\\n(Font: Anton, Warna: ...)",
      "image_prompt": "English Leonardo 9:16 MUST include stickman tuxedo + yellow rubber duck",
      "motion_prompt": "English I2V motion"
    }
  ],
  "thumbnail_prompt": "...",
  "caption": "...",
  "title_options": []
}
Tepat 3 scene: 00:00-00:05, 00:05-00:10, 00:10-00:15.
PROMPT;
    }

    protected function geminiLongPrompt(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): string
    {
        $hook = $idea?->hook ?: '';
        $angle = $idea?->angle ?: '';

        return <<<PROMPT
Kamu adalah penulis MASTER BLUEPRINT Reels Panjang / VOD Dark Psychology (~8-10 menit) untuk brand "{$kit->name}".
Gaya: cinematic dark psychology (bukan stickman). Campur visual_type: "ai", "stock", "black", "bumper".
Beat tiap ~5 detik. Bahasa Indonesia tajam untuk VO & teks layar. Prompt visual/motion dalam English.

Tema: {$title}
Topik: {$topic}
Hook: {$hook}
Angle: {$angle}
Hashtag: {$kit->caption_hashtags}

Struktur WAJIB:
1) THE GOLDEN HOOK (00:00-02:00) — cerita kasus/tokoh + cliff ke topik
2) BAB 1 (02:00-04:30) — teori + contoh kehidupan
3) BAB 2 (04:30-06:40) — cara lawan / reframing
4) BAB 3 (06:40-08:50) — 4 senjata/taktik praktis
5) PENUTUP STOIK (08:50-10:20) — CTA share + bumper

JSON SAJA:
{
  "title": "Episode: ...",
  "cover_theme": "JUDUL EPISODE",
  "story_concept": "ringkas 1-2 kalimat",
  "scenes": [
    {
      "section": "THE GOLDEN HOOK (00:00 - 02:00)",
      "timecode": "00:00-00:05",
      "visual_type": "ai",
      "image_prompt": "Cinematic ... OR (Video Stok 10 Detik): ... OR (Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut OR Bumper KEA Abbasy",
      "motion_prompt": "..." ,
      "vo": "...",
      "on_screen": "TEKS LAYAR SINGKAT"
    }
  ],
  "thumbnail_prompt": "English thumbnail clickbait prompt",
  "caption": "caption panjang ID + CTA + hashtags",
  "title_options": [
    "Opsi fokusik...",
    "Opsi rasa sakit...",
    "Opsi clickbait...",
    "Opsi tokoh..."
  ]
}
Minimal 40 scene beat 5 detik. Akhir tiap bab boleh bumper KEA Abbasy (visual_type bumper, VO kosong).
PROMPT;
    }

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,mixed>>,thumbnail_prompt:string,caption:string,title_options:array<int,string>}
     */
    protected function buildShortTemplate(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): array
    {
        $cover = Str::upper(Str::limit(preg_replace('/\s+/', ' ', $title) ?: 'TEMA VIRAL', 60, ''));
        $short = Str::limit($idea?->hook ?: $cover, 42, '');
        $char = $kit->character_visual ?: 'A stickman figure made of thin black wireframe lines in a sharp executive tuxedo with a yellow rubber duck prop';
        $style = $kit->visual_style ?: 'Cinematic 9:16 vertical, Jakarta neon night, high contrast';
        $font = $kit->on_screen_font ?: 'Anton';
        $voice = $kit->voice_perspective ?: 'Aku';
        $hashtags = $kit->caption_hashtags ?: '#keaabbasy #psikologigelap #reelsviral';

        $vos = [
            $idea?->hook ?: "{$voice} bilang begini: yang kelihatan tenang di luar, sering paling berantakan di dalam.",
            $idea?->angle ?: "Orang jarang sadar: {$topic} itu bukan soal orang lain — itu soal cara otakmu mencari kontrol.",
            'Kalau ini nyampe, berhenti pura-pura oke. Ubah pola sebelum pola itu yang ngubah hidupmu.',
        ];
        $locations = [
            'walking forward down a crowded, bustling Jakarta city street at night with glowing neon lights and bokeh traffic',
            'standing near a crowded outdoor cafe terrace under neon signs, dark comedy psychology aesthetic',
            'walking past a glowing storefront window in a crowded downtown walkway, grit urban texture',
        ];
        $shots = ['wide shot', 'medium shot', 'close-up shot'];
        $motions = [
            'The stickman character walks forward steadily toward the camera through the blurred, busy crowd, with neon lights reflecting smoothly on its tuxedo and a natural fluid walking cycle.',
            'The stickman character slowly turns its head toward the camera, while pedestrians blur softly in the background. Natural micro-movements.',
            'The camera tracks smoothly alongside the character walking forward, with natural lighting shifting across its frame and background reflections moving dynamically.',
        ];
        $onScreens = [
            "{$cover} 🛑\n\n(Font: {$font}, Warna: Merah & Putih)",
            Str::upper(Str::limit($short, 28, ''))." ⚠️\n\n(Font: {$font}, Warna: Kuning Emas)",
            "SADAR SEBELUM TERLAMBAT! 🔥\n\n(Font: {$font}, Warna: Kuning & Putih)",
        ];
        $timecodes = ['00:00 - 00:05', '00:05 - 00:10', '00:10 - 00:15'];

        $scenes = [];
        for ($i = 0; $i < 3; $i++) {
            $scenes[] = [
                'section' => 'REELS VO',
                'timecode' => $timecodes[$i],
                'visual_type' => 'ai',
                'vo' => $vos[$i],
                'on_screen' => $onScreens[$i],
                'image_prompt' => "A vertical {$kit->aspect_ratio} cinematic {$shots[$i]} of {$char} {$locations[$i]}. {$style}. Ultra-detailed, consistent character design. --ar 9:16",
                'motion_prompt' => "A cinematic 3D motion. {$motions[$i]}",
            ];
        }

        return [
            'title' => Str::limit($title, 120, ''),
            'cover_theme' => $cover,
            'story_concept' => "Karakter {$kit->character_name} berjas rapi berjalan di keramaian kota malam, membongkar sudut gelap dari: {$topic}.",
            'scenes' => $scenes,
            'thumbnail_prompt' => "A vertical {$kit->aspect_ratio} cinematic graphic design for a video thumbnail. {$char} in a bustling neon-lit Jakarta street at night. At the top of the image, the text \"{$cover}\" is boldly displayed in a large, modern, glowing yellow font with a dark drop shadow. High contrast, urban cinematic aesthetic, professional film grain, no offensive elements.",
            'caption' => trim($vos[0])."\n\nRealitanya, {$topic} sering jadi jebakan yang terasa biasa — sampai kamu sadar biayanya terlalu mahal.\n\nSetuju atau tidak? Tulis di komentar. 👇\n.\n.\n.\n".$hashtags,
            'title_options' => [],
        ];
    }

    /**
     * Template ringkas struktur Master Blueprint reels panjang (tanpa Gemini).
     *
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,mixed>>,thumbnail_prompt:string,caption:string,title_options:array<int,string>}
     */
    protected function buildLongTemplate(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): array
    {
        $cover = Str::upper(Str::limit(preg_replace('/\s+/', ' ', $title) ?: 'EPISODE DARK PSYCHOLOGY', 70, ''));
        $shortTopic = Str::limit($topic, 80, '');
        $hashtags = $kit->caption_hashtags ?: '#keaabbasy #darkpsychology #psikologigelap #manipulasi #reelsfb';
        $hookLine = $idea?->hook ?: "Orang jarang sadar: {$shortTopic} adalah penjara kata yang dibangun diam-diam.";

        $beats = [
            // GOLDEN HOOK
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:00-00:05', 'ai', 'Cinematic portrait. A sharp man in a dark suit sitting across a dimly lit luxury wooden table.', 'Extremely slow zoom in. Subtle smoke rising, warm lamp flicker.', $hookLine, 'KASUS PEMBUKA'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:05-00:10', 'stock', '(Video Stok 10 Detik): Micro expression of a person looking aggressive in shadows.', '-', 'Dengan nada menyudutkan, lawan bicara melemparkan pertanyaan jebakan.', 'PERTANYAAN JEBAKAN'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:10-00:15', 'stock', '(Lanjutan Video Stok Pexels di atas)', '-', '"Kenapa kamu membiarkan hal itu terjadi?" — asumsi beracun sudah ditanam.', '"KENAPA DIBIARKAN?"'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:15-00:20', 'ai', 'Abstract concept. A glowing steel trap snapping shut in slow motion.', 'The jaws of the trap slam shut violently, scattering golden sparks in the dark.', 'Perhatikan: dia sedang membangun sebuah penjara kata.', 'MEMBANGUN PENJARA KATA'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:20-00:25', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Orang biasa yang panik akan langsung sibuk memberi alasan.', 'SIBUK MEMBERI ALASAN'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:25-00:30', 'black', '(Lanjutan Layar Hitam di atas)', '-', '...menjelaskan detail, atau memohon agar dipercaya.', 'MEMOHON DIPERCAYA'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:30-00:35', 'ai', 'Bizarre macro. A chessboard where the black king stands perfectly still amidst chaos.', 'The board shakes violently, pieces fall, but the black king remains static.', 'Tapi yang paham psikologi gelap tidak membela diri — dia merebut bingkai.', 'KENDALI PERCAKAPAN'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:35-00:40', 'ai', 'Abstract destruction. A glass frame shattering into a million pieces.', 'Extreme slow motion. The glass shatters outwards, reflecting golden light.', 'Satu pertanyaan balik cukup untuk merusak seluruh bingkai tuduhan.', 'MERUSAK BINGKAI TUDUHAN'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:40-00:45', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', '"Kasih saya SATU bukti konkret. Satu saja."', '"SATU SAJA."'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:45-00:50', 'stock', '(Video Stok 10 Detik): Man in suit looking nervous in shadows.', '-', 'Lawan bicara mendadak gelagapan — narasinya hancur tanpa bentakan.', 'NARASI HANCUR TOTAL'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:50-00:55', 'ai', 'Abstract geometry. A glowing golden lock snapping shut on a black box.', 'The lock slams shut with a heavy, satisfying mechanical click.', "Ini disebut Frame Control — inti dari: {$shortTopic}.", 'TAKTIK FRAME CONTROL'],
            ['THE GOLDEN HOOK (00:00 - 02:00)', '00:55-01:00', 'bumper', 'Bumper KEA Abbasy (Video Intro / Logo KEA Abbasy)', '(SFX Bumper / Jeda VO)', '', 'BAB 1: BENTUK KEKALAHAN'],

            // BAB 1
            ['BAB 1 (02:00 - 04:30)', '02:00-02:05', 'ai', 'Cinematic surrealism. An old vintage book opening by itself in the dark.', 'The pages turn rapidly, revealing glowing golden text on black paper.', 'Filsuf dan ahli taktik perang kata sudah lama memetakan pola ini.', 'POLA LAMA, MASIH MEMATIKAN'],
            ['BAB 1 (02:00 - 04:30)', '02:05-02:10', 'stock', '(Video Stok 10 Detik): Abstract black ink swirling on parchment paper.', '-', 'Lawan yang terdesak akan memaksamu memberi penjelasan rinci.', 'MEMAKSA MEMBERI PENJELASAN'],
            ['BAB 1 (02:00 - 04:30)', '02:10-02:15', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Karena dalam penjelasan itulah terdapat kelemahanmu.', 'DI SITULAH KELEMAHANMU'],
            ['BAB 1 (02:00 - 04:30)', '02:15-02:20', 'stock', '(Video Stok 10 Detik): Office worker looking stressed under dramatic lighting.', '-', 'Di kantor, rekan intimidatif memojokkanmu di depan orang lain.', 'REKAN KERJA INTIMIDATIF'],
            ['BAB 1 (02:00 - 04:30)', '02:20-02:25', 'ai', 'Cinematic macro. A sharp pendulum swinging aggressively over a desk.', 'The pendulum swings fast, slicing the air in slow motion.', '"Kamu ini niat kerja atau tidak? Kenapa belum selesai?"', '"NIAT KERJA TIDAK SIH?"'],
            ['BAB 1 (02:00 - 04:30)', '02:25-02:30', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Dia menggabungkan dua hal yang tidak berhubungan: waktu dan integritasmu.', 'MENGGABUNGKAN 2 HAL'],
            ['BAB 1 (02:00 - 04:30)', '02:30-02:35', 'stock', '(Video Stok 10 Detik): Person holding head in hands panicking.', '-', 'Kalau kamu bereaksi lemah, refleksmu adalah panik dan menjelaskan panjang lebar.', 'PANIK & MEMBERI PENJELASAN'],
            ['BAB 1 (02:00 - 04:30)', '02:35-02:40', 'ai', 'Cinematic conceptual. A tiny silhouetted child crying in front of a giant.', 'The child shadow shrinks while the giant shadow grows larger and darker.', 'Di mata lawan, penjelasanmu terdengar seperti rengekan anak kecil.', 'SEPERTI RENGEKAN ANAK KECIL'],
            ['BAB 1 (02:00 - 04:30)', '02:40-02:45', 'ai', 'Surreal dark art. A mouth sealed shut with a heavy iron lock.', 'The lock shines coldly in the dark, remaining perfectly shut.', 'Semakin banyak alasan, semakin kamu terlihat mudah dimanipulasi.', 'TERLIHAT LEMAH'],
            ['BAB 1 (02:00 - 04:30)', '02:45-02:50', 'ai', 'Abstract geometry. A person handing over a glowing crown to a shadow.', 'The shadow snatches the crown, absorbing its golden light completely.', 'Saat kamu menjelaskan, kamu menyerahkan otoritas moral kepadanya.', 'MENYERAHKAN OTORITAS'],
            ['BAB 1 (02:00 - 04:30)', '02:50-03:00', 'bumper', 'Bumper KEA Abbasy', '(SFX Bumper)', '', 'BAB 2: CARA MELAWAN'],

            // BAB 2
            ['BAB 2 (04:30 - 06:40)', '04:30-04:35', 'ai', 'Golden scale balancing perfectly in darkness.', 'Scale finds equilibrium with a soft glow.', 'Sekarang: cara merebut kembali mahkota kendali percakapan.', 'REBUT KENDALI'],
            ['BAB 2 (04:30 - 06:40)', '04:35-04:40', 'stock', '(Video Stok 10 Detik): Cold staring eye without blinking.', '-', 'Tatap matanya. Tahan jeda 2 detik tanpa suara.', 'TATAP & JEDA 2 DETIK'],
            ['BAB 2 (04:30 - 06:40)', '04:40-04:45', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Lalu gunakan reframing dengan suara datar dan dingin.', 'GUNAKAN REFRAMING'],
            ['BAB 2 (04:30 - 06:40)', '04:45-04:50', 'ai', 'Macro text on vintage paper under spotlight.', 'Focus pull on glowing words.', $idea?->angle ?: "'Pertanyaan yang lebih tepat: apakah fokus kita kualitas — atau buru-buru berantakan?'", 'UBAH ARENA PERMAINAN'],
            ['BAB 2 (04:30 - 06:40)', '04:50-04:55', 'ai', 'Hourglass reversing flow of golden sand.', 'Sand flows upwards against gravity.', 'Dalam satu kalimat, bingkai tuduhannya remuk — posisi kalian berbalik.', 'POSISI BERBALIK 180 DERAJAT'],
            ['BAB 2 (04:30 - 06:40)', '04:55-05:00', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Kamu tidak membentak. Kamu hanya merebut mahkota kendali.', 'MEREBUT MAHKOTA KENDALI'],
            ['BAB 2 (04:30 - 06:40)', '05:00-05:10', 'bumper', 'Bumper KEA Abbasy', '(SFX Bumper)', '', 'BAB 3: 4 SENJATA GELAP'],

            // BAB 3
            ['BAB 3 (06:40 - 08:50)', '06:40-06:45', 'ai', 'Four glowing pillars standing in complete darkness.', 'Slow pan across the pillars.', 'Simpan 4 pola pembalikan narasi ini dalam memorimu.', '4 POLA PEMBALIKAN'],
            ['BAB 3 (06:40 - 08:50)', '06:45-06:50', 'ai', 'Dark mirror reflecting a distorted shadow.', 'Reflection distorts slightly.', 'Satu: Mirroring Motive — tanyakan balik motif pertanyaannya.', '1. MIRRORING MOTIVE'],
            ['BAB 3 (06:40 - 08:50)', '06:50-06:55', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', '"Ada hal di hidupmu sendiri yang sedang membuatmu cemas?"', '"KAMU LAGI CEMAS?"'],
            ['BAB 3 (06:40 - 08:50)', '06:55-07:00', 'ai', 'Poison drop hitting clear water.', 'Ripple effect in slow-mo.', 'Dua: Merusak Asumsi — tolak presupposisi di dalam pertanyaan.', '2. MERUSAK ASUMSI'],
            ['BAB 3 (06:40 - 08:50)', '07:00-07:05', 'stock', '(Video Stok 10 Detik): Knife cutting a knot.', '-', '"Saya menolak asumsi itu. Mari bicara berdasarkan data objektif."', 'TOLAK ASUMSINYA'],
            ['BAB 3 (06:40 - 08:50)', '07:05-07:10', 'ai', 'Two toxic vials swept off a table.', 'Vials crash and break.', 'Tiga: Counter-Constraint — jangan pilih dilema. Buat aturan main baru.', '3. KUNCI ATURAN MAIN'],
            ['BAB 3 (06:40 - 08:50)', '07:10-07:15', 'ai', 'Deep dark empty void with a cold statue face.', 'Slow zoom on face.', 'Empat: Cold Tactical Silence — diam 3–5 detik, tatap tanpa ekspresi.', '4. KEHENINGAN TAKTIS'],
            ['BAB 3 (06:40 - 08:50)', '07:15-07:20', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Keheningan pria tenang lebih menakutkan dari teriakan.', 'KEHENINGAN MENAKUTKAN'],
            ['BAB 3 (06:40 - 08:50)', '07:20-07:30', 'bumper', 'Bumper KEA Abbasy', '(SFX Bumper)', '', 'PENUTUP STOIK'],

            // PENUTUP
            ['PENUTUP (08:50 - 10:20)', '08:50-08:55', 'ai', 'Puppeteer hands manipulating strings in darkness.', 'Hands pull strings slowly.', 'Dunia ini penuh orang yang mendikte siapa dirimu lewat pertanyaan jebakan.', 'PERTANYAAN JEBAKAN'],
            ['PENUTUP (08:50 - 10:20)', '08:55-09:00', 'ai', 'Imposing stone fortress in the night.', 'Slow zoom on fortress.', 'Pikiranmu milikmu. Jangan serahkan Inner Citadel-mu.', 'INNER CITADEL'],
            ['PENUTUP (08:50 - 10:20)', '09:00-09:05', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Lepaskan kebutuhan untuk selalu membela diri.', 'STOP MEMBELA DIRI'],
            ['PENUTUP (08:50 - 10:20)', '09:05-09:10', 'stock', '(Video Stok 10 Detik): Calm confident man standing still.', '-', 'Kuasai bingkainya, kunci narasinya, tanyakan balik motif mereka.', 'KUASAI BINGKAINYA'],
            ['PENUTUP (08:50 - 10:20)', '09:10-09:15', 'black', '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.', '-', 'Terima kasih telah mendengarkan KEA Abbasy. Bagikan video ini.', 'SHARE VIDEO INI'],
            ['PENUTUP (08:50 - 10:20)', '09:15-09:20', 'bumper', 'Glowing logo KEA Abbasy / End Screen Outro', 'Subtle glow pulse', 'Tetap tenang, jaga bentengmu, pegang kendali hidupmu.', 'PEGGANG KENDALI HIDUPMU'],
        ];

        $scenes = array_map(fn ($b) => [
            'section' => $b[0],
            'timecode' => $b[1],
            'visual_type' => $b[2],
            'image_prompt' => $b[3],
            'motion_prompt' => $b[4],
            'vo' => $b[5],
            'on_screen' => $b[6],
        ], $beats);

        $titleOptions = [
            'JANGAN PERNAH Jawab Pertanyaan Mereka (Taktik Mengunci Lawan)',
            'Cara Elegan Membungkam Orang yang Suka Menyudutkanmu',
            '1 Kalimat Pembungkam: Taktik Psikologi Membalikkan Keadaan',
            'Tiru Cara Orang Tajam Menghancurkan Pertanyaan Jebakan — '.$cover,
        ];

        $caption = "Sering tiba-tiba merasa bersalah padahal kamu yang sedang diserang? Atau refleks panik dan sibuk membela diri saat dilempar pertanyaan yang menyudutkan?\n\n"
            ."Hati-hati — kamu bisa sedang masuk ke dalam penjara kata.\n\n"
            ."Di video ini aku membongkar anatomi {$shortTopic}: cara merebut bingkai, membalikkan keadaan, dan mengunci narasi lawan tanpa perlu marah.\n\n"
            ."Tonton sampai habis. Siapa yang sering berhadapan dengan orang yang hobi memutarbalikkan fakta? Tulis di komentar. 👇\n\n"
            .$hashtags;

        return [
            'title' => 'Episode: '.Str::limit($title, 100, ''),
            'cover_theme' => $cover,
            'story_concept' => "Master blueprint reels panjang tentang {$shortTopic}: Golden Hook → teori → reframing → 4 senjata → penutup stoik.",
            'scenes' => $scenes,
            'thumbnail_prompt' => "A vertical 9:16 cinematic dark psychology thumbnail. Moody high-contrast scene related to frame control and verbal traps. Bold glowing yellow title text \"{$cover}\" at the top with dark drop shadow. Professional film grain, no offensive elements.",
            'caption' => $caption,
            'title_options' => $titleOptions,
        ];
    }

    protected function coverTitle(string $topic, ?ContentIdea $idea): string
    {
        if ($idea?->title) {
            return $idea->title;
        }

        return Str::of($topic)->before('—')->trim()->limit(80, '')->toString() ?: 'Ide Viral Psikologi';
    }

    protected function normalizeFormat(string $format): string
    {
        return in_array($format, [self::FORMAT_REELS_VO, self::FORMAT_REELS_PANJANG], true)
            ? $format
            : self::FORMAT_REELS_VO;
    }
}
