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
    public function generateFromTopic(
        User $user,
        string $topic,
        ?BrandKit $brandKit = null,
        ?ContentIdea $idea = null,
        ?TrendSignal $signal = null,
        ?string $plannedDate = null,
    ): ContentBlueprint {
        $kit = $brandKit ?? BrandKit::defaultFor($user);
        $topic = trim($topic) !== '' ? trim($topic) : 'Topik psikologi viral';
        $title = $this->coverTitle($topic, $idea);

        $payload = $this->tryGemini($kit, $title, $topic, $idea)
            ?? $this->buildTemplate($kit, $title, $topic, $idea);

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
            'scenes' => $payload['scenes'],
            'thumbnail_prompt' => $payload['thumbnail_prompt'],
            'caption' => $payload['caption'],
            'planned_date' => $plannedDate,
        ]);
    }

    public function generateFromIdea(User $user, ContentIdea $idea, ?string $plannedDate = null): ContentBlueprint
    {
        $topic = $idea->title;
        if ($idea->hook) {
            $topic .= ' — '.$idea->hook;
        }

        return $this->generateFromTopic($user, $topic, null, $idea, null, $plannedDate);
    }

    public function generateFromSignal(User $user, TrendSignal $signal, ?string $plannedDate = null): ContentBlueprint
    {
        $topic = $signal->title ?: $signal->keyword;
        if ($signal->snippet) {
            $topic .= ' — '.Str::limit($signal->snippet, 120);
        }

        return $this->generateFromTopic($user, (string) $topic, null, null, $signal, $plannedDate);
    }

    public function toMarkdown(ContentBlueprint $bp, BrandKit $kit): string
    {
        $lines = [
            '🎬 Blueprint Reels Cinematic',
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

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,string>>,thumbnail_prompt:string,caption:string}|null
     */
    protected function tryGemini(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): ?array
    {
        $apiKey = trim((string) config('blueprint.gemini_api_key', ''));
        if ($apiKey === '') {
            return null;
        }

        $model = config('blueprint.gemini_model', 'gemini-2.0-flash');
        $prompt = $this->geminiPrompt($kit, $title, $topic, $idea);

        try {
            $response = Http::timeout(45)
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
                return null;
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            if (! is_string($text) || $text === '') {
                return null;
            }

            $decoded = json_decode($text, true);
            if (! is_array($decoded) || empty($decoded['scenes']) || ! is_array($decoded['scenes'])) {
                return null;
            }

            return [
                'title' => (string) ($decoded['title'] ?? $title),
                'cover_theme' => (string) ($decoded['cover_theme'] ?? Str::upper($title)),
                'story_concept' => (string) ($decoded['story_concept'] ?? ''),
                'scenes' => array_values(array_map(fn ($s) => [
                    'timecode' => (string) ($s['timecode'] ?? ''),
                    'vo' => (string) ($s['vo'] ?? ''),
                    'on_screen' => (string) ($s['on_screen'] ?? ''),
                    'image_prompt' => (string) ($s['image_prompt'] ?? ''),
                    'motion_prompt' => (string) ($s['motion_prompt'] ?? ''),
                ], $decoded['scenes'])),
                'thumbnail_prompt' => (string) ($decoded['thumbnail_prompt'] ?? ''),
                'caption' => (string) ($decoded['caption'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function geminiPrompt(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): string
    {
        $hook = $idea?->hook ?: '';
        $angle = $idea?->angle ?: '';

        return <<<PROMPT
Kamu adalah penulis blueprint Reels cinematic untuk brand "{$kit->name}" niche {$kit->niche}.
Karakter WAJIB konsisten di SEMUA prompt gambar/animasi:
- Nama: {$kit->character_name}
- Visual: {$kit->character_visual}
- Style: {$kit->visual_style}
- Aspect: {$kit->aspect_ratio}
- Font on-screen: {$kit->on_screen_font}
- Sudut VO: "{$kit->voice_perspective}" (bahasa Indonesia santai, tajam, psikologi gelap / insight praktis)
- Hashtag caption: {$kit->caption_hashtags}

Tema dari Auto Viral Radar / ide:
- Judul: {$title}
- Topik: {$topic}
- Hook: {$hook}
- Angle: {$angle}

Output JSON SAJA dengan skema:
{
  "title": "string pendek",
  "cover_theme": "JUDUL COVER HURUF BESAR",
  "story_concept": "1-2 kalimat konsep film",
  "scenes": [
    {
      "timecode": "00:00 - 00:05",
      "vo": "naskah VO",
      "on_screen": "TEKS CAPCUT + emoji\\n\\n(Font: Anton, Warna: ...)",
      "image_prompt": "English Leonardo prompt 9:16, MUST include stickman tuxedo + yellow rubber duck",
      "motion_prompt": "English I2V camera/character motion"
    }
  ],
  "thumbnail_prompt": "English thumbnail prompt with cover title text",
  "caption": "caption ID + hashtags"
}

Buat tepat 3 scene (00:00-00:05, 00:05-00:10, 00:10-00:15). Scene terakhir boleh nyambung ulang ke hook awal.
PROMPT;
    }

    /**
     * @return array{title:string,cover_theme:string,story_concept:string,scenes:array<int,array<string,string>>,thumbnail_prompt:string,caption:string}
     */
    protected function buildTemplate(BrandKit $kit, string $title, string $topic, ?ContentIdea $idea): array
    {
        $cover = Str::upper(Str::limit(preg_replace('/\s+/', ' ', $title) ?: 'TEMA VIRAL', 60, ''));
        $short = Str::limit($idea?->hook ?: $cover, 42, '');
        $char = $kit->character_visual ?: 'A stickman figure made of thin black wireframe lines in a sharp executive tuxedo with a yellow rubber duck prop';
        $style = $kit->visual_style ?: 'Cinematic 9:16 vertical, Jakarta neon night, high contrast';
        $font = $kit->on_screen_font ?: 'Anton';
        $voice = $kit->voice_perspective ?: 'Aku';
        $hashtags = $kit->caption_hashtags ?: '#keaabbasy #psikologigelap #reelsviral';

        $beat1Vo = $idea?->hook
            ?: "{$voice} bilang begini: yang kelihatan tenang di luar, sering paling berantakan di dalam.";
        $beat2Vo = $idea?->angle
            ?: "Orang jarang sadar: {$topic} itu bukan soal orang lain — itu soal cara otakmu mencari kontrol.";
        $beat3Vo = "Kalau ini nyampe, berhenti pura-pura oke. Ubah pola sebelum pola itu yang ngubah hidupmu.";

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
        $vos = [$beat1Vo, $beat2Vo, $beat3Vo];
        $timecodes = ['00:00 - 00:05', '00:05 - 00:10', '00:10 - 00:15'];

        $scenes = [];
        for ($i = 0; $i < 3; $i++) {
            $scenes[] = [
                'timecode' => $timecodes[$i],
                'vo' => $vos[$i],
                'on_screen' => $onScreens[$i],
                'image_prompt' => "A vertical {$kit->aspect_ratio} cinematic {$shots[$i]} of {$char} {$locations[$i]}. {$style}. Ultra-detailed, consistent character design. --ar 9:16",
                'motion_prompt' => "A cinematic 3D motion. {$motions[$i]}",
            ];
        }

        $thumbnail = "A vertical {$kit->aspect_ratio} cinematic graphic design for a video thumbnail. {$char} in a bustling neon-lit Jakarta street at night. At the top of the image, the text \"{$cover}\" is boldly displayed in a large, modern, glowing yellow font with a dark drop shadow. High contrast, urban cinematic aesthetic, professional film grain, no offensive elements.";

        $caption = trim($beat1Vo)."\n\n"
            ."Realitanya, {$topic} sering jadi jebakan yang terasa biasa — sampai kamu sadar biayanya terlalu mahal.\n\n"
            ."Setuju atau tidak? Tulis di komentar. 👇\n.\n.\n.\n"
            .$hashtags;

        return [
            'title' => Str::limit($title, 120, ''),
            'cover_theme' => $cover,
            'story_concept' => "Karakter {$kit->character_name} berjas rapi berjalan di keramaian kota malam, membongkar sudut gelap dari: {$topic}.",
            'scenes' => $scenes,
            'thumbnail_prompt' => $thumbnail,
            'caption' => $caption,
        ];
    }

    protected function coverTitle(string $topic, ?ContentIdea $idea): string
    {
        if ($idea?->title) {
            return $idea->title;
        }

        return Str::of($topic)->before('—')->trim()->limit(80, '')->toString() ?: 'Ide Viral Psikologi';
    }
}
