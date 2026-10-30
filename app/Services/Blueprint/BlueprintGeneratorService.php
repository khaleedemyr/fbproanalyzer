<?php

namespace App\Services\Blueprint;

use App\Models\AnalyticsAiAnalysis;
use App\Models\BrandKit;
use App\Models\ContentBrief;
use App\Models\ContentBlueprint;
use App\Models\ContentIdea;
use App\Models\TrendSignal;
use App\Models\User;
use App\Support\BlueprintProgress;
use App\Support\SequelContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    /** Visual plate duration (image changes). */
    protected int $beatSeconds = 5;

    /** Naskah / VO line duration. */
    protected int $voBeatSeconds = 5;

    protected bool $preferStock = true;

    protected string $currentFormat = self::FORMAT_REELS_VO;

    protected string $voicePerspective = 'Aku';

    protected ?int $progressBlueprintId = null;

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
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,visual_beat_seconds?:int,vo_beat_seconds?:int,prefer_stock?:bool,force?:bool,voice_perspective?:string,planned_time?:string|null,analytics_ai_analysis?:AnalyticsAiAnalysis|null,analytics_ai_analysis_id?:int|null,content_brief?:ContentBrief|null,content_brief_id?:int|null,angle_finding_id?:int|null}  $options
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
        $this->currentFormat = $format;
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

        $brief = $options['content_brief'] ?? null;
        if (! $brief instanceof ContentBrief && ! empty($options['content_brief_id'])) {
            $brief = ContentBrief::query()
                ->where('user_id', $user->id)
                ->find($options['content_brief_id']);
        }
        if ($brief instanceof ContentBrief && $brief->user_id !== $user->id) {
            $brief = null;
        }

        $angleFindingId = isset($options['angle_finding_id']) ? (int) $options['angle_finding_id'] : null;
        if (! $angleFindingId && $brief) {
            $angleFindingId = $brief->angle_finding_id ? (int) $brief->angle_finding_id : null;
        }

        $this->lastEngine = 'template';
        $this->lastWarning = null;
        $this->wasDuplicate = false;
        $this->progressBlueprintId = ! empty($options['existing_blueprint_id'])
            ? (int) $options['existing_blueprint_id']
            : null;

        $cleanTopic = $this->cleanTopic($topic, $idea, $signal);
        $cleanTitle = $this->coverTitle($cleanTopic, $idea);
        $sequel = $this->resolveSequelMeta($brief, $options);
        $extraContext = trim(implode("\n\n", array_filter([
            $this->briefContext($brief),
            SequelContext::promptBlock($sequel),
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

        $this->reportProgress(8, 'Menyiapkan kerangka naskah…');

        // Background job: AI default ON. Request sync HTTP: AI default OFF (hindari proxy timeout).
        $background = (bool) ($options['background'] ?? false);
        $useAi = array_key_exists('use_ai', $options)
            ? (bool) $options['use_ai']
            : ($background || $format !== self::FORMAT_REELS_PANJANG);
        $skipAi = (bool) ($options['skip_ai'] ?? false) || ! $useAi;

        $gemini = null;
        if (! $skipAi) {
            $this->reportProgress(18, 'Memanggil AI Gemini…');
            $gemini = $this->tryGemini($kit, $cleanTitle, $cleanTopic, $idea, $format, $extraContext);
        } elseif ($format === self::FORMAT_REELS_PANJANG && ! $background) {
            $this->lastWarning = 'Mode sync tanpa AI — untuk 1 jam pakai generate background (otomatis dari Brief Studio).';
        }

        if ($gemini !== null) {
            $this->lastEngine = 'gemini';
            $payload = $gemini;
            $this->reportProgress(40, 'Kerangka AI siap, menyesuaikan timeline…');
            if ($format === self::FORMAT_REELS_PANJANG && $this->voBeatCount() > count($gemini['scenes'] ?? [])) {
                $this->lastWarning = 'AI Gemini menulis kerangka naskah; sistem menyesuaikan ke durasi penuh (visual '.$this->beatSeconds.'s · naskah '.$this->voBeatSeconds.'s).';
            }
        } else {
            // Video panjang + AI wajib: jangan pernah jatuh ke template.
            if ($format === self::FORMAT_REELS_PANJANG && $useAi && ! $skipAi) {
                $why = $this->lastWarning ?: 'Gemini tidak mengembalikan kerangka naskah.';
                $this->reportProgress(0, 'AI gagal: '.$why, 'failed');
                throw new \RuntimeException('AI Gemini gagal — blueprint panjang tidak memakai template. '.$why);
            }

            $this->reportProgress(28, 'Menyusun template bab + naskah…');
            $payload = match ($format) {
                self::FORMAT_REELS_PANJANG => $this->buildLongTemplate($kit, $cleanTitle, $cleanTopic, $idea),
                self::FORMAT_REELS_TEXT => $this->buildReelsTextTemplate($kit, $cleanTitle, $cleanTopic, $idea),
                self::FORMAT_FOTO => $this->buildFotoTemplate($kit, $cleanTitle, $cleanTopic, $idea),
                default => $this->buildShortTemplate($kit, $cleanTitle, $cleanTopic, $idea),
            };
            $this->reportProgress(45, 'Template siap…');
        }

        $payload['cover_theme'] = Str::upper(trim((string) ($payload['cover_theme'] ?: $cleanTitle)));

        if ($this->isVisualPostFormat($format)) {
            $payload = $this->ensureVisualClarity($payload, $cleanTopic, $idea, $kit, $format);
        }

        $payload['scenes'] = $this->ensureSceneTimecodes($payload['scenes'] ?? []);

        if ($format === self::FORMAT_REELS_PANJANG) {
            $this->reportProgress(55, 'Merapikan prompt ilustrasi buku…');
            $payload['scenes'] = $this->enrichLongFormBookVisuals(
                $payload['scenes'],
                (string) $payload['cover_theme'],
                $cleanTopic
            );
            $this->reportProgress(75, 'Expand timeline ke durasi penuh…');
            $payload['scenes'] = $this->normalizeLongFormTimeline(
                $payload['scenes'],
                (string) $payload['cover_theme'],
                $cleanTopic
            );
            $this->reportProgress(88, 'Menyusun caption & thumbnail…');
        }

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
        if ($brief && $storyConcept !== '' && ! str_contains(Str::lower($storyConcept), 'brief studio')) {
            $storyConcept .= "\n\n[Brief Studio #{$brief->id}] ".($brief->title ?: 'Resume gabungan Radar×Angle×Analytics');
        } elseif ($analysis && $storyConcept !== '' && ! str_contains(Str::lower($storyConcept), 'analisa ai')) {
            $storyConcept .= "\n\n[Referensi Analisa AI #{$analysis->id}] ".$analysis->title;
        }

        $attrs = [
            'user_id' => $user->id,
            'brand_kit_id' => $kit->id,
            'content_idea_id' => $idea?->id,
            'trend_signal_id' => $signal?->id,
            'analytics_ai_analysis_id' => $analysis?->id ?: $brief?->analytics_ai_analysis_id,
            'content_brief_id' => $brief?->id,
            'angle_finding_id' => $angleFindingId,
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
        ];

        if (Schema::hasColumn('content_blueprints', 'vo_beat_seconds')) {
            $attrs['vo_beat_seconds'] = $format === self::FORMAT_REELS_PANJANG
                ? $this->voBeatSeconds
                : $this->beatSeconds;
        } else {
            $this->lastWarning = trim(($this->lastWarning ? $this->lastWarning.' ' : '')
                .'Kolom vo_beat_seconds belum ada di DB — jalankan: php artisan migrate --force');
        }

        if (Schema::hasColumn('content_blueprints', 'series_title')) {
            $attrs['series_title'] = $sequel['series_title'];
            $attrs['episode_number'] = $sequel['episode_number'];
            $attrs['parent_blueprint_id'] = $sequel['parent_blueprint_id'];
            $attrs['previous_episodes'] = $sequel['previous_episodes'] !== [] ? $sequel['previous_episodes'] : null;
        }

        $this->reportProgress(95, 'Menyimpan blueprint…');

        $existingId = (int) ($options['existing_blueprint_id'] ?? 0);
        if ($existingId > 0) {
            $existing = ContentBlueprint::query()
                ->where('user_id', $user->id)
                ->find($existingId);
            if ($existing) {
                unset($attrs['user_id']);
                $existing->forceFill($attrs)->save();
                BlueprintProgress::done($existing->id, 'Blueprint siap');
                $this->progressBlueprintId = null;

                return $existing->fresh();
            }
        }

        try {
            $created = ContentBlueprint::query()->create($attrs);
            BlueprintProgress::done($created->id, 'Blueprint siap');
            $this->progressBlueprintId = null;

            return $created;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $stripped = false;
            // Hostinger belum migrate: buang kolom baru lalu coba lagi.
            if (str_contains($msg, 'vo_beat_seconds') && isset($attrs['vo_beat_seconds'])) {
                unset($attrs['vo_beat_seconds']);
                $stripped = true;
            }
            foreach (['series_title', 'episode_number', 'parent_blueprint_id', 'previous_episodes'] as $col) {
                if (str_contains($msg, $col) && array_key_exists($col, $attrs)) {
                    unset($attrs[$col]);
                    $stripped = true;
                }
            }
            if ($stripped) {
                $this->lastWarning = trim(($this->lastWarning ? $this->lastWarning.' ' : '')
                    .'Simpan tanpa kolom baru — jalankan migrate / SQL sequel di server.');

                $created = ContentBlueprint::query()->create($attrs);
                BlueprintProgress::done($created->id, 'Blueprint siap');
                $this->progressBlueprintId = null;

                return $created;
            }
            throw $e;
        }
    }

    protected function reportProgress(int $percent, string $message, string $status = 'generating'): void
    {
        if (! $this->progressBlueprintId) {
            return;
        }
        BlueprintProgress::set($this->progressBlueprintId, $percent, $message, $status);
    }

    /**
     * Stub langsung (status generating) supaya HTTP response tidak nunggu 1 jam.
     *
     * @param  array<string, mixed>  $options
     */
    public function createGeneratingStub(
        User $user,
        string $topic,
        string $format,
        array $options = [],
        ?ContentBrief $brief = null,
        ?TrendSignal $signal = null,
    ): ContentBlueprint {
        $kit = BrandKit::defaultFor($user);
        $format = $this->normalizeFormat($format);
        $this->currentFormat = $format;
        $this->applyDurationOptions($format, $options);
        $this->voicePerspective = $this->normalizeVoicePerspective(
            (string) ($options['voice_perspective'] ?? ($kit->voice_perspective ?: 'Aku'))
        );
        $plannedDate = $options['planned_date'] ?? null;
        $plannedTime = $this->normalizePlannedTime($options['planned_time'] ?? null);
        $minutes = max(1, (int) round($this->durationSeconds / 60));
        $title = Str::limit(trim($topic) !== '' ? trim($topic) : 'Blueprint panjang', 120, '');

        $attrs = [
            'user_id' => $user->id,
            'brand_kit_id' => $kit->id,
            'trend_signal_id' => $signal?->id,
            'analytics_ai_analysis_id' => $brief?->analytics_ai_analysis_id ?? ($options['analytics_ai_analysis_id'] ?? null),
            'content_brief_id' => $brief?->id,
            'angle_finding_id' => $brief?->angle_finding_id ?? ($options['angle_finding_id'] ?? null),
            'title' => $title,
            'cover_theme' => Str::upper($title),
            'story_concept' => "Sedang menyusun blueprint ~{$minutes} menit di background. Biasanya 15–40 detik. Halaman ini refresh otomatis…",
            'source_topic' => Str::limit($topic, 255, ''),
            'status' => 'generating',
            'format' => $format,
            'engine' => (! empty($options['use_ai']) && empty($options['skip_ai'])) ? 'pending_ai' : 'pending',
            'duration_seconds' => $this->durationSeconds,
            'beat_seconds' => $this->beatSeconds,
            'prefer_stock' => $format === self::FORMAT_REELS_PANJANG ? (bool) ($options['prefer_stock'] ?? false) : false,
            'voice_perspective' => $this->voicePerspective,
            'scenes' => [],
            'thumbnail_prompt' => '',
            'caption' => '',
            'title_options' => null,
            'planned_date' => $plannedDate,
            'planned_time' => $plannedDate ? $plannedTime : null,
        ];

        if (Schema::hasColumn('content_blueprints', 'vo_beat_seconds')) {
            $attrs['vo_beat_seconds'] = $this->voBeatSeconds;
        }

        if (Schema::hasColumn('content_blueprints', 'series_title')) {
            $sequel = $this->resolveSequelMeta($brief, $options);
            $attrs['series_title'] = $sequel['series_title'];
            $attrs['episode_number'] = $sequel['episode_number'];
            $attrs['parent_blueprint_id'] = $sequel['parent_blueprint_id'];
            $attrs['previous_episodes'] = $sequel['previous_episodes'] !== [] ? $sequel['previous_episodes'] : null;
        }

        $bp = ContentBlueprint::query()->create($attrs);
        BlueprintProgress::set($bp->id, 5, 'Antrian generate dimulai…', 'generating');

        return $bp;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{series_title:?string,episode_number:?int,parent_blueprint_id:?int,previous_episodes:list<array{n:int,title:string,hook_or_angle?:string}>}
     */
    protected function resolveSequelMeta(?ContentBrief $brief, array $options = []): array
    {
        return SequelContext::normalize([
            'series_title' => $options['series_title'] ?? $brief?->series_title,
            'episode_number' => $options['episode_number'] ?? $brief?->episode_number,
            'parent_blueprint_id' => $options['parent_blueprint_id'] ?? $brief?->parent_blueprint_id,
            'previous_episodes' => $options['previous_episodes']
                ?? $brief?->previous_episodes
                ?? [],
        ]);
    }

    /**
     * Isi stub generating (dipanggil dari job afterResponse).
     *
     * @param  array<string, mixed>  $options
     */
    public function fillExistingBlueprint(
        ContentBlueprint $stub,
        User $user,
        string $topic,
        string $format,
        array $options = [],
    ): ContentBlueprint {
        $options['existing_blueprint_id'] = $stub->id;
        $options['background'] = true;
        $options['force'] = true;
        if (! array_key_exists('use_ai', $options)) {
            $wantAi = $stub->engine === 'pending_ai';
            $options['use_ai'] = $wantAi;
            $options['skip_ai'] = ! $wantAi;
        } else {
            $options['skip_ai'] = ! (bool) $options['use_ai'];
        }

        $brief = null;
        if (! empty($options['content_brief_id'])) {
            $brief = ContentBrief::query()->find($options['content_brief_id']);
        } elseif ($stub->content_brief_id) {
            $brief = ContentBrief::query()->find($stub->content_brief_id);
            $options['content_brief'] = $brief;
        }

        $signal = null;
        if ($stub->trend_signal_id) {
            $signal = TrendSignal::query()->find($stub->trend_signal_id);
        }

        if ($topic === '' || $topic === 'Konten viral') {
            $topic = (string) ($stub->source_topic ?: $stub->cover_theme ?: $stub->title);
        }

        return $this->generateFromTopic(
            $user,
            $topic,
            null,
            null,
            $signal,
            $stub->planned_date?->format('Y-m-d'),
            $format,
            true,
            $options
        );
    }

    /**
     * Selesaikan stub generating. Pakai AI kalau diminta / engine pending_ai.
     *
     * @param  array{use_ai?:bool}  $extra
     */
    public function finishGeneratingBlueprint(ContentBlueprint $stub, User $user, array $extra = []): ContentBlueprint
    {
        if ($stub->status !== 'generating' && $stub->status !== 'failed') {
            return $stub;
        }

        @set_time_limit(180);
        @ini_set('memory_limit', '512M');
        ignore_user_abort(true);

        $topic = (string) ($stub->source_topic ?: $stub->cover_theme ?: $stub->title ?: 'Konten viral');
        $minutes = max(5, (int) round(((int) ($stub->duration_seconds ?: 600)) / 60));
        $wantAi = array_key_exists('use_ai', $extra)
            ? (bool) $extra['use_ai']
            : ($stub->engine === 'pending_ai');

        $baseOptions = [
            'duration_minutes' => $minutes,
            'duration_seconds' => (int) ($stub->duration_seconds ?: $minutes * 60),
            'visual_beat_seconds' => (int) ($stub->beat_seconds ?: 10),
            'vo_beat_seconds' => (int) ($stub->vo_beat_seconds ?: 5),
            'prefer_stock' => (bool) $stub->prefer_stock,
            'voice_perspective' => (string) ($stub->voice_perspective ?: 'Aku'),
            'content_brief_id' => $stub->content_brief_id,
            'analytics_ai_analysis_id' => $stub->analytics_ai_analysis_id,
            'angle_finding_id' => $stub->angle_finding_id,
            'force' => true,
            'background' => true,
            'use_ai' => $wantAi,
            'skip_ai' => ! $wantAi,
        ];

        // AI dicentang = wajib Gemini. Gagal = throw (tidak pernah template).
        if ($wantAi) {
            BlueprintProgress::set($stub->id, 15, 'Memanggil AI Gemini untuk kerangka naskah…', 'generating');
            $stub->forceFill(['engine' => 'pending_ai', 'status' => 'generating'])->save();
        } else {
            BlueprintProgress::set($stub->id, 35, 'Expand timeline…', 'generating');
        }

        try {
            return $this->fillExistingBlueprint(
                $stub,
                $user,
                $topic,
                (string) ($stub->format ?: self::FORMAT_REELS_PANJANG),
                $baseOptions
            );
        } catch (\Throwable $e) {
            if ($wantAi) {
                $stub->forceFill([
                    'status' => 'failed',
                    'engine' => 'failed',
                    'story_concept' => 'AI Gemini gagal: '.$e->getMessage(),
                ])->save();
                BlueprintProgress::set($stub->id, 0, 'AI gagal: '.$e->getMessage(), 'failed');
            }
            throw $e;
        }
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
        $topic = trim((string) ($options['override_topic'] ?? ''));
        if ($topic === '') {
            $topic = (string) ($signal->keyword ?: $signal->title ?: 'psikologi');
        }

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
        $visualBeat = (int) ($bp->beat_seconds ?: 10);
        $voBeat = (int) ($bp->vo_beat_seconds ?: $visualBeat ?: 5);

        $lines = [
            $bp->title,
            '🎬 BLUEPRINT REELS PANJANG / VOD (rangkaian ilustrasi bergaya buku)',
            'Tema: "'.$bp->cover_theme.'"',
            'Konsep: '.$bp->story_concept,
            'Durasi total: '.$durLabel.' · visual tiap '.$visualBeat.'s · naskah tiap '.$voBeat.'s · Sudut pandang: "'.$voice.'"',
            'Style visual AI: textbook watercolor diagram — plate gambar boleh dipakai beberapa baris VO dalam 1 window visual.',
            '',
            'Durasi | Visual (prompt ilustrasi buku / stock / black) | Motion | Naskah VO & Teks Layar',
            '',
        ];

        $currentSection = null;
        foreach ($bp->scenes ?? [] as $scene) {
            $section = $scene['section'] ?? null;
            if ($section && $section !== $currentSection) {
                $currentSection = $section;
                $lines[] = '—— '.Str::upper($currentSection).' ——';
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
            $this->lastWarning = 'GEMINI_API_KEY belum terisi / belum kebaca config.';

            return null;
        }

        $prompt = match ($format) {
            self::FORMAT_REELS_PANJANG => $this->geminiLongPrompt($kit, $title, $topic, $idea, $radarContext),
            self::FORMAT_REELS_TEXT => $this->geminiReelsTextPrompt($kit, $title, $topic, $idea, $radarContext),
            self::FORMAT_FOTO => $this->geminiFotoPrompt($kit, $title, $topic, $idea, $radarContext),
            default => $this->geminiShortPrompt($kit, $title, $topic, $idea, $radarContext),
        };

        // Video panjang: 1 model saja (jangan cascade fallback × timeout — Hostinger bunuh request).
        $primary = (string) config('blueprint.gemini_model', 'gemini-3.8-flash');
        $fallbacks = (array) config('blueprint.gemini_model_fallbacks', []);
        $models = $format === self::FORMAT_REELS_PANJANG
            ? array_values(array_unique(array_filter([$primary])))
            : array_values(array_unique(array_filter([$primary, ...$fallbacks])));

        $errors = [];

        // Konteks riset (brief+sequel+analisa) sering kebanyakan → JSON output terpotong.
        if ($format === self::FORMAT_REELS_PANJANG && strlen($radarContext) > 2800) {
            $radarContext = Str::limit($radarContext, 2800, "\n…(dipotong)");
            $prompt = match ($format) {
                self::FORMAT_REELS_PANJANG => $this->geminiLongPrompt($kit, $title, $topic, $idea, $radarContext),
                default => $prompt,
            };
        }

        foreach ($models as $model) {
            try {
                // CLI/background: timeout & token lebih longgar supaya JSON tidak terpotong.
                $httpTimeout = $format === self::FORMAT_REELS_PANJANG ? 90 : 45;
                $maxTokens = $format === self::FORMAT_REELS_PANJANG ? 8192 : 4096;
                Log::info('blueprint.gemini.request', [
                    'model' => $model,
                    'format' => $format,
                    'timeout' => $httpTimeout,
                    'max_tokens' => $maxTokens,
                    'prompt_chars' => strlen($prompt),
                ]);
                $started = microtime(true);
                $response = Http::timeout($httpTimeout)
                    ->connectTimeout(10)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                        [
                            'contents' => [[
                                'parts' => [['text' => $prompt]],
                            ]],
                            'generationConfig' => [
                                'temperature' => 0.75,
                                'topP' => 0.9,
                                'maxOutputTokens' => $maxTokens,
                                'responseMimeType' => 'application/json',
                            ],
                        ]
                    );
                $finishReason = (string) data_get($response->json(), 'candidates.0.finishReason', '');
                Log::info('blueprint.gemini.response', [
                    'model' => $model,
                    'status' => $response->status(),
                    'seconds' => round(microtime(true) - $started, 2),
                    'finish_reason' => $finishReason,
                ]);

                if (! $response->successful()) {
                    $err = data_get($response->json(), 'error.message') ?: ('HTTP '.$response->status());
                    $errors[] = "{$model}: {$err}";

                    continue;
                }

                // Gabung semua parts (kadang teks pecah).
                $parts = data_get($response->json(), 'candidates.0.content.parts', []);
                $text = '';
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                            $text .= $part['text'];
                        }
                    }
                }
                if ($text === '') {
                    $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
                }
                if ($text === '') {
                    $block = $finishReason
                        ?: data_get($response->json(), 'promptFeedback.blockReason');
                    $errors[] = "{$model}: respons kosong".($block ? " ({$block})" : '');

                    continue;
                }

                $decoded = $this->decodeGeminiBlueprintJson($text);
                if (! is_array($decoded)) {
                    Log::warning('blueprint.gemini.json_invalid', [
                        'model' => $model,
                        'finish_reason' => $finishReason,
                        'json_error' => json_last_error_msg(),
                        'snippet' => Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, 400),
                    ]);
                    $why = $finishReason === 'MAX_TOKENS'
                        ? 'JSON terpotong (MAX_TOKENS)'
                        : 'JSON blueprint tidak valid';
                    $errors[] = "{$model}: {$why}";

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

                // Outline panjang: isi VO kosong di scene non-bumper dari judul (bumper boleh kosong).
                if ($format === self::FORMAT_REELS_PANJANG) {
                    foreach ($scenes as &$sc) {
                        $type = Str::lower((string) ($sc['visual_type'] ?? 'ai'));
                        if (in_array($type, ['bumper', 'black'], true)) {
                            continue;
                        }
                        if (trim((string) ($sc['vo'] ?? '')) === '') {
                            $sc['vo'] = Str::limit($title.' — lanjut bahas '.$topic, 120, '');
                        }
                    }
                    unset($sc);
                }

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

        $this->lastWarning = 'Gemini gagal ('.implode(' | ', $errors).').';

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

Karakter visual WAJIB konsisten (LOCK — jangan diganti orang real):
- Nama: {$kit->character_name}
- Visual: {$kit->character_visual}
- Style lingkungan: {$kit->visual_style}
- Aspect: {$kit->aspect_ratio}
- Font on-screen: {$kit->on_screen_font}
- Sudut VO WAJIB: "{$this->voicePerspective}" — semua kalimat VO pakai sudut ini (bukan ceramah orang ketiga)
- Hashtag caption: {$kit->caption_hashtags}
- image_prompt / thumbnail_prompt WAJIB stickman Brand Kit. Photorealism hanya untuk background. DILARANG manusia fotoreal.

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
        $visualBeat = $this->beatSeconds;
        $voBeat = $this->voBeatSeconds;
        // Kerangka sangat padat — JSON panjang sering MAX_TOKENS / invalid; expand di PHP.
        $fullBeats = $this->voBeatCount();
        $minutes = max(1, (int) round($totalSec / 60));
        $beatCount = min(8, max(6, 5 + (int) ceil($minutes / 15)));
        $preferStock = $this->preferStock
            ? 'Campur AI ilustrasi buku + stock jarang + bumper antar bab.'
            : 'UTAMAKAN visual_type "ai" ilustrasi buku. Bumper hanya antar bab.';
        $banned = $this->bannedHookPhrasesForPrompt();
        $bookStyle = $this->bookIllustrationStyleGuide();
        $voWords = $this->targetVoWords();

        return <<<PROMPT
Kamu menulis KERANGKA RINGKAS VOD psikologi untuk "{$kit->name}" (~{$minutes} mnt).
Beat visual {$visualBeat}s · naskah {$voBeat}s · sistem expand ke ~{$fullBeats} baris.
{$preferStock}

WAJIB: tepat {$beatCount} scene SAJA. JSON harus lengkap & valid (jangan potong di tengah).
Field:
- section: wajib bertitel, contoh "GOLDEN HOOK: Jebakan Empati", "BAB 1: Frame Control", "BAB 2: ...", "BAB 3: ...", "PENUTUP: Aturan Baru", "BUMPER"
- vo: 1–2 kalimat, {$voWords['min']}-{$voWords['max']} kata (sesuai beat {$voBeat}s — jangan terlalu pendek, nanti VO kosong)
- on_screen: max 6 kata (judul singkat bab/beat)
- image_prompt: 1 kalimat metafora buku
- motion_prompt: "slow Ken Burns"

Style image: {$bookStyle}
DILARANG stickman tuxedo/bebek; DILARANG wajah fotoreal.

Tema: {$title}
Topik: {$topic}
Hook: {$hook}
Angle: {$angle}
Sudut VO: "{$this->voicePerspective}"
Hashtag: {$kit->caption_hashtags}
{$radarBlock}
Struktur: HOOK → BAB1 → bumper(10s) → BAB2 → bumper(10s) → BAB3 → PENUTUP.
Bumper visual_type "bumper", vo "", durasi 10 detik.
Hook = cerita nyata tokoh/kasus (tahun + twist).

DILARANG pembuka:
{$banned}

JSON SAJA keys: title, cover_theme, story_concept, scenes, thumbnail_prompt, caption, title_options.
Setiap scene: section, timecode, visual_type, vo, on_screen, image_prompt, motion_prompt.
Bumper vo "". Scene lain vo terisi {$voWords['min']}+ kata. caption WAJIB. JANGAN markdown.
PROMPT;
    }

    /**
     * Target kata VO per beat naskah (~2.3 kata/detik, bicara jelas).
     *
     * @return array{min:int,ideal:int,max:int}
     */
    protected function targetVoWords(?int $voBeatSeconds = null): array
    {
        $sec = max(3, $voBeatSeconds ?? $this->voBeatSeconds);
        $ideal = (int) round($sec * 2.3);
        $ideal = max(8, min(48, $ideal));

        return [
            'min' => max(6, $ideal - 4),
            'ideal' => $ideal,
            'max' => min(56, $ideal + 6),
        ];
    }

    /**
     * Sesuaikan panjang naskah ke beat (hindari VO pendek di beat 10s).
     */
    protected function fitVoToBeat(string $vo, string $topic, string $section = ''): string
    {
        $vo = $this->sanitizeSpokenVo($vo);
        if ($vo === '' || $this->looksLikeMetaVo($vo)) {
            $vo = $this->sectionSeedLine($section !== '' ? $section : 'BAB', $topic, $topic);
        }

        $t = $this->targetVoWords();
        $words = preg_split('/\s+/u', $vo) ?: [];
        $vp = $this->voicePerspective;

        if (count($words) < $t['min']) {
            $fillers = [
                "Ini pola yang sering {$vp} alami tanpa sadar.",
                'Perhatikan detailnya pelan-pelan supaya bingkai tidak digeser diam-diam.',
                "Jangan buru-buru bereaksi; amati dulu siapa yang diuntungkan dari rasa iba {$vp}.",
                "Kalau dilewatkan, {$vp} bisa kehilangan kendali percakapan dalam hitungan detik.",
                'Lapisan berikutnya masih satu cerita yang sama, jangan putus di tengah.',
            ];
            $i = 0;
            while (count($words) < $t['min'] && $i < 12) {
                $vo = rtrim($vo, " \t.").'. '.$fillers[$i % count($fillers)];
                $words = preg_split('/\s+/u', $vo) ?: [];
                $i++;
            }
        }

        if (count($words) > $t['max']) {
            $vo = implode(' ', array_slice($words, 0, $t['max']));
            if (! preg_match('/[.!?…]$/u', $vo)) {
                $vo .= '.';
            }
        }

        return trim($vo);
    }

    /**
     * Buang label internal (GOLDEN HOOK / BAB / Lanjut benang) dari teks yang dibaca VO.
     */
    protected function sanitizeSpokenVo(string $vo): string
    {
        $vo = trim(preg_replace('/\s+/u', ' ', $vo) ?? $vo);
        $vo = preg_replace('/^\s*lanjut\s+benang\s*[:\-]?\s*/iu', '', $vo) ?? $vo;
        $vo = preg_replace('/\b(GOLDEN\s*HOOK|BAB\s*\d+|PENUTUP|BUMPER)\s*:\s*/iu', '', $vo) ?? $vo;
        $vo = preg_replace('/\buji satu situasi nyata\b.*$/iu', '', $vo) ?? $vo;
        $vo = preg_replace('/\bsupaya polanya kebaca\.?\s*$/iu', '', $vo) ?? $vo;

        return trim($vo, " \t:-");
    }

    /**
     * Judul bab dari on_screen / VO / default.
     */
    protected function makeChapterTitle(
        string $sectionKey,
        array $voLines,
        string $onScreen,
        string $topic,
    ): string {
        $key = $this->normalizeLongFormSection($sectionKey);
        if ($key === 'BUMPER') {
            return 'BUMPER';
        }

        $title = trim($onScreen);
        // Kalau section sudah "BAB 1: Judul", ambil judulnya.
        if (str_contains($sectionKey, ':')) {
            $parts = explode(':', $sectionKey, 2);
            $maybe = trim($parts[1] ?? '');
            if ($maybe !== '' && ! preg_match('/^BAB\s*\d+$/i', $maybe)) {
                $title = $maybe;
            }
        }

        if ($title === '' || mb_strlen($title) < 4 || preg_match('/^(GOLDEN HOOK|BAB\s*\d+|PENUTUP|BUMPER)$/iu', $title)) {
            $firstVo = trim((string) ($voLines[0] ?? ''));
            $title = $this->onScreenFromVo($firstVo, '', $topic, false);
        }

        if ($title === '' || mb_strlen($title) < 3) {
            $title = match (true) {
                str_contains($key, 'HOOK') => 'Cerita Pembuka',
                str_contains($key, 'BAB 1') => 'Pola Dasar',
                str_contains($key, 'BAB 2') => 'Cara Kerja Manipulasi',
                str_contains($key, 'BAB 3') => 'Cara Merebut Bingkai',
                str_contains($key, 'PENUTUP') => 'Aturan Baru',
                default => Str::limit($topic, 28, ''),
            };
        }

        $title = Str::title(Str::lower(Str::limit($title, 42, '')));

        return $key.': '.$title;
    }

    /**
     * Decode JSON dari Gemini; perbaiki truncation ringan.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeGeminiBlueprintJson(string $text): ?array
    {
        $jsonText = trim($text);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $jsonText, $m)) {
            $jsonText = trim($m[1]);
        }

        $start = strpos($jsonText, '{');
        if ($start === false) {
            return null;
        }
        $jsonText = substr($jsonText, $start);

        $decoded = json_decode($jsonText, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $repaired = $this->repairTruncatedJson($jsonText);
        $decoded = json_decode($repaired, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Tutup kutip/kurung yang terbuka bila output Gemini terpotong.
     */
    protected function repairTruncatedJson(string $json): string
    {
        $json = rtrim($json);

        // Buang trailing koma / key tanpa value.
        $json = preg_replace('/,\s*$/', '', $json) ?? $json;
        $json = preg_replace('/,\s*"[^"]*"\s*:\s*$/', '', $json) ?? $json;
        $json = preg_replace('/,\s*\{[^}]*$/', '', $json) ?? $json;

        // Tutup string terbuka (jumlah " ganjil di luar escape kasar).
        $quoteCount = preg_match_all('/(?<!\\\\)"/', $json) ?: 0;
        if ($quoteCount % 2 === 1) {
            $json .= '"';
        }

        $json = preg_replace('/,\s*$/', '', $json) ?? $json;

        // Tutup array/object yang masih terbuka.
        $stack = [];
        $len = strlen($json);
        $inString = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            $prev = $i > 0 ? $json[$i - 1] : '';
            if ($ch === '"' && $prev !== '\\') {
                $inString = ! $inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === '{') {
                $stack[] = '}';
            } elseif ($ch === '[') {
                $stack[] = ']';
            } elseif ($ch === '}' || $ch === ']') {
                array_pop($stack);
            }
        }
        while ($stack !== []) {
            $json .= array_pop($stack);
        }

        // Jika scenes terpotong di tengah objek, pastikan key scenes ada sebagai array.
        if (! str_contains($json, '"scenes"')) {
            return $json;
        }

        return $json;
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

Karakter WAJIB (LOCK): {$kit->character_name} — {$char}
Style lingkungan (bukan tubuh karakter): {$kit->visual_style}
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
2) image_prompt = 1 prompt lengkap. MULAI dengan deskripsi stickman Brand Kit di atas. Bake judul 2 baris BESAR + footer KECIL. User TIDAK edit teks.
3) motion_prompt = image-to-video {$dur}s, semua teks tetap tajam/tidak bergeser. Karakter tetap stickman.
4) caption WAJIB lengkap (solusi + ajakan like/share/follow) + hashtag.
5) 1 scene: image_prompt + motion_prompt + on_screen = cover_theme (pakai newline atau " | ").

LOCK KARAKTER: photorealism HANYA untuk background/kota/cahaya. Karakter = stickman wireframe tuxedo + bebek kuning. DILARANG orang real / wajah manusia / kulit fotoreal.
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

Karakter WAJIB (LOCK): {$kit->character_name} — {$char}
Style lingkungan (bukan tubuh karakter): {$kit->visual_style}
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
2) image_prompt = 1 prompt generate image. MULAI dengan deskripsi stickman Brand Kit. Bake judul 2 baris BESAR + footer KECIL. User TIDAK edit teks.
3) motion_prompt = "-"
4) caption WAJIB ada SOLUSI konkret + ajakan like/share/follow + hashtag.
5) 1 scene saja.

LOCK KARAKTER: photorealism HANYA untuk background/kota/cahaya. Karakter = stickman wireframe tuxedo + bebek kuning. DILARANG orang real / wajah manusia / kulit fotoreal.
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

        $visualBeat = max(5, $this->beatSeconds);
        $voBeat = max(3, $this->voBeatSeconds);
        $total = $this->durationSeconds;
        // Satu baris per beat naskah (mis. 5s). Visual plate digabung di normalize.
        $count = max(2, min(720, intdiv($total, $voBeat)));

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
            '(Video Stok 10 Detik): Slow pan across open psychology textbook diagrams on a desk.',
            '(Video Stok 10 Detik): Hands flipping educational illustrated notebook pages.',
            '(Video Stok 10 Detik): Macro of ink sketching arrows on paper.',
            '(Video Stok 10 Detik): Soft focus library shelves with warm light.',
            '(Video Stok 10 Detik): Close-up of handwritten notes and mind-map doodles.',
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
        $lastPlate = -1;
        $plateVisual = '';
        $plateType = 'ai';
        $plateMotion = 'Slow Ken Burns push-in on the illustration plate, keep labels sharp and readable, subtle paper texture motion.';

        foreach ($sectionDefs as $sIdx => $def) {
            $name = $def[0];
            $n = $alloc[$sIdx];
            $sectionStart = $t;

            for ($i = 0; $i < $n; $i++) {
                $start = $t;
                $end = $t + $voBeat;
                $mod = $i % 5;

                $vo = $vosPool[$voIdx % count($vosPool)];
                $voIdx++;
                $on = Str::upper(Str::limit(preg_replace('/[^\pL\pN\s]+/u', '', $vo) ?: $cover, 34, ''));

                // Plate visual diganti tiap visualBeat (bisa mencakup beberapa baris VO).
                $visualPlateIndex = intdiv($start, $visualBeat);
                if ($visualPlateIndex !== $lastPlate) {
                    $lastPlate = $visualPlateIndex;
                    if ($this->preferStock && $mod === 1) {
                        $plateType = 'stock';
                        $plateVisual = $stocks[$stockIdx++ % count($stocks)];
                        $plateMotion = '-';
                    } elseif ($mod === 4) {
                        $plateType = 'black';
                        $plateVisual = '(Layar Hitam 10 Detik): Warna Solid Hitam dari CapCut.';
                        $plateMotion = '-';
                    } else {
                        $plateType = 'ai';
                        $plateVisual = $this->bookIllustrationPrompt($vo, $shortTopic, $on);
                        $plateMotion = 'Slow Ken Burns push-in on the illustration plate, keep labels sharp and readable, subtle paper texture motion.';
                    }
                }

                $scenes[] = [
                    'section' => $name.' ('.$this->formatTimecode($sectionStart, $sectionStart + ($n * $voBeat)).')',
                    'timecode' => $this->formatTimecode($start, $end),
                    'visual_type' => $plateType,
                    'image_prompt' => $plateVisual,
                    'motion_prompt' => $plateMotion,
                    'vo' => $vo,
                    'on_screen' => $on,
                    'visual_plate' => $visualPlateIndex,
                    'beat_seconds' => $voBeat,
                ];
                $t = $end;
            }

            $start = $t;
            $end = $t + $voBeat;
            $scenes[] = [
                'section' => $name.' ('.$this->formatTimecode($sectionStart, $end).')',
                'timecode' => $this->formatTimecode($start, $end),
                'visual_type' => 'bumper',
                'image_prompt' => 'Bumper KEA Abbasy (Video Intro / Logo KEA Abbasy)',
                'motion_prompt' => '(SFX Bumper / Jeda VO)',
                'vo' => '',
                'on_screen' => $sIdx < count($sectionDefs) - 1 ? $sectionDefs[$sIdx + 1][0] : 'OUTRO',
                'beat_seconds' => $voBeat,
            ];
            $t = $end;
        }

        $minutes = max(1, (int) round($this->durationSeconds / 60));
        $voice = $this->voicePerspective;
        $caption = "Sering tiba-tiba merasa bersalah padahal kamu yang sedang diserang?\n\n"
            ."Di video ini (~{$minutes} menit) {$voice} membongkar anatomi {$shortTopic}: merebut bingkai, membalikkan keadaan, mengunci narasi lawan.\n\n"
            ."Tonton sampai habis. Tulis di komentar. 👇\n\n".$hashtags;

        return [
            'title' => 'Episode: '.Str::limit($theme, 100, ''),
            'cover_theme' => $cover,
            'story_concept' => "Master blueprint {$minutes} mnt (visual {$visualBeat}s · naskah {$voBeat}s) tentang {$shortTopic}: rangkaian ilustrasi bergaya buku. Hook → Bab → Penutup + bumper.",
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
     * @param  array{duration_seconds?:int,duration_minutes?:int,beat_seconds?:int,visual_beat_seconds?:int,vo_beat_seconds?:int,prefer_stock?:bool}  $options
     */
    protected function applyDurationOptions(string $format, array $options): void
    {
        $this->beatSeconds = 5;
        $this->voBeatSeconds = 5;
        $this->preferStock = array_key_exists('prefer_stock', $options)
            ? (bool) $options['prefer_stock']
            : true;

        if ($format === self::FORMAT_FOTO) {
            $this->durationSeconds = 0;
            $this->beatSeconds = 0;
            $this->voBeatSeconds = 0;

            return;
        }

        if ($format === self::FORMAT_REELS_TEXT) {
            $sec = (int) ($options['duration_seconds'] ?? 8);
            $this->durationSeconds = max(5, min(30, $sec));
            $this->beatSeconds = $this->durationSeconds;
            $this->voBeatSeconds = $this->durationSeconds;

            return;
        }

        if ($format === self::FORMAT_REELS_PANJANG) {
            $visualBeat = (int) ($options['visual_beat_seconds'] ?? $options['beat_seconds'] ?? 10);
            $voBeat = (int) ($options['vo_beat_seconds'] ?? 5);
            $this->beatSeconds = max(5, min(30, $visualBeat));
            $this->voBeatSeconds = max(3, min(30, $voBeat));

            if (! empty($options['duration_seconds'])) {
                $sec = (int) $options['duration_seconds'];
            } elseif (! empty($options['duration_minutes'])) {
                $sec = ((int) $options['duration_minutes']) * 60;
            } else {
                $sec = 10 * 60;
            }
            // 5 menit … 60 menit
            $sec = max(5 * 60, min(60 * 60, $sec));
            $step = max(1, min($this->voBeatSeconds, $this->beatSeconds));
            $this->durationSeconds = (int) (round($sec / $step) * $step);

            return;
        }

        $sec = (int) ($options['duration_seconds'] ?? 15);
        $sec = max(10, min(90, $sec));
        $this->durationSeconds = (int) (round($sec / $this->beatSeconds) * $this->beatSeconds);
        $this->voBeatSeconds = $this->beatSeconds;
    }

    protected function beatCount(): int
    {
        $beat = $this->currentFormat === self::FORMAT_REELS_PANJANG
            ? $this->voBeatSeconds
            : $this->beatSeconds;
        if ($beat <= 0) {
            return 1;
        }

        return max(2, intdiv($this->durationSeconds, $beat));
    }

    protected function voBeatCount(): int
    {
        if ($this->voBeatSeconds <= 0) {
            return $this->beatCount();
        }

        return max(2, intdiv($this->durationSeconds, $this->voBeatSeconds));
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

    protected function briefContext(?ContentBrief $brief): string
    {
        if (! $brief) {
            return '';
        }

        $resume = $brief->resume ?? [];
        if ($resume === [] && filled($brief->resume_markdown)) {
            return "RESUME BRIEF STUDIO (Radar × Angle × Analytics) — inspirasi sudut, JANGAN dibaca mentah di VO:\n"
                .Str::limit((string) $brief->resume_markdown, 1800);
        }

        $lines = [
            'RESUME BRIEF STUDIO (gabungan Auto Radar + Angle Finder + Analisa AI) — inspirasi WAJIB, jangan dibaca mentah di VO/caption:',
            'Headline: '.($resume['headline'] ?? $brief->title),
            'Judul kerja: '.($resume['working_title'] ?? '-'),
            'Hook: '.($resume['hook'] ?? '-'),
            'Angle: '.($resume['angle'] ?? '-'),
            'Why now: '.Str::limit((string) ($resume['why_now'] ?? ''), 300),
        ];

        foreach (array_slice($resume['audience_pain'] ?? [], 0, 5) as $p) {
            $lines[] = 'Pain: '.$p;
        }
        foreach (array_slice($resume['must_include'] ?? [], 0, 5) as $p) {
            $lines[] = 'Must include: '.$p;
        }
        foreach (array_slice($resume['avoid'] ?? [], 0, 5) as $p) {
            $lines[] = 'Avoid: '.$p;
        }
        foreach (array_slice($resume['title_options'] ?? [], 0, 5) as $p) {
            $lines[] = 'Title option: '.$p;
        }
        $lines[] = 'CTA: '.($resume['cta'] ?? '-');
        $lines[] = 'Blueprint HARUS selaras dengan resume gabungan ini (tren + komentar + data channel), bukan generic.';

        $sequel = SequelContext::normalize([
            'series_title' => $brief->series_title,
            'episode_number' => $brief->episode_number,
            'parent_blueprint_id' => $brief->parent_blueprint_id,
            'previous_episodes' => $brief->previous_episodes ?? [],
        ]);
        $sequelBlock = SequelContext::promptBlock($sequel);
        if ($sequelBlock !== '') {
            $lines[] = '';
            $lines[] = $sequelBlock;
        }

        return implode("\n", $lines);
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
        $firstContentVo = '';
        foreach ($scenes as $scene) {
            $type = Str::lower((string) ($scene['visual_type'] ?? 'ai'));
            $vo = trim((string) ($scene['vo'] ?? ''));
            if ($format === self::FORMAT_REELS_VO) {
                $required++;
                if ($vo !== '') {
                    $withVo++;
                }
            } elseif ($type !== 'bumper' && $type !== 'black') {
                $required++;
                if ($vo !== '') {
                    $withVo++;
                    if ($firstContentVo === '') {
                        $firstContentVo = $vo;
                    }
                }
            }
        }

        if ($format === self::FORMAT_REELS_VO) {
            // Minimal 80% scene harus punya naskah; beat pertama wajib.
            $firstVo = trim((string) ($scenes[0]['vo'] ?? ''));

            return $firstVo !== '' && $withVo >= max(2, (int) ceil(count($scenes) * 0.8));
        }

        // Video panjang: outline sengaja ringkas + ada bumper kosong.
        // Naskah penuh di-expand belakangan — jangan wajib ≥5 scene VO.
        if ($format === self::FORMAT_REELS_PANJANG) {
            return $required > 0
                && $firstContentVo !== ''
                && $withVo >= max(2, (int) ceil($required * 0.5));
        }

        return $required > 0 && $withVo >= max(5, (int) ceil($required * 0.6));
    }

    protected function ensureImagePromptHasText(string $prompt, string $coverTheme, BrandKit $kit, string $format): string
    {
        $withTitle = $this->ensureThumbnailHasTitle($prompt, $coverTheme, $kit, $format);

        // Video panjang = ilustrasi buku (bukan stickman lock).
        if ($format === self::FORMAT_REELS_PANJANG) {
            return $withTitle;
        }

        return $this->lockBrandCharacterInPrompt($withTitle, $kit, $format);
    }

    protected function bookIllustrationStyleGuide(): string
    {
        return 'Educational hand-drawn textbook / popular-science book plate: fine black ink outlines, soft muted watercolor washes (tan, light blue, pale yellow), clean white paper background, conceptual psychology/science infographic with arrows, icons, and labeled metaphors. Consistent series look across frames like consecutive book pages.';
    }

    protected function bookIllustrationPrompt(string $vo, string $topic, string $onScreen = ''): string
    {
        $concept = trim($vo !== '' ? $vo : $topic);
        $concept = Str::limit(preg_replace('/\s+/', ' ', $concept) ?? $concept, 200, '');
        $label = trim($onScreen);
        $labelClause = $label !== ''
            ? ' Small neat handwritten Indonesian label: "'.Str::limit($label, 48, '').'".'
            : '';

        return $this->bookIllustrationStyleGuide()
            .' Visual metaphor that clearly illustrates this narration beat: '.$concept.'.'
            .$labelClause
            .' No photorealistic humans, no stickman tuxedo character, no cinematic live-action still, no 3D CGI person. Keep diagram readable. --ar 9:16';
    }

    /**
     * Pastikan scene AI panjang punya image_prompt yang nyambung ke VO (style buku).
     *
     * @param  array<int, array<string, mixed>>  $scenes
     * @return array<int, array<string, mixed>>
     */
    protected function enrichLongFormBookVisuals(array $scenes, string $coverTheme, string $topic): array
    {
        foreach ($scenes as &$scene) {
            $type = Str::lower((string) ($scene['visual_type'] ?? 'ai'));
            if (in_array($type, ['bumper', 'black', 'stock'], true)) {
                continue;
            }

            $vo = trim((string) ($scene['vo'] ?? ''));
            $on = trim((string) ($scene['on_screen'] ?? ''));
            $img = trim((string) ($scene['image_prompt'] ?? ''));
            $looksBook = (bool) preg_match('/textbook|watercolor|hand-drawn|illustration plate|infographic|wireframe brain|book plate|sketched/i', $img);
            $mentionsStickman = (bool) preg_match('/stick\s*-?\s*man|tuxedo|rubber duck/i', $img);
            $genericStockish = str_starts_with($img, '(Video Stok') || str_starts_with($img, '(Layar Hitam');

            if ($img === '' || $mentionsStickman || $genericStockish || ! $looksBook || ($vo !== '' && ! $this->promptRelatesToVo($img, $vo))) {
                $scene['visual_type'] = 'ai';
                $scene['image_prompt'] = $this->bookIllustrationPrompt($vo !== '' ? $vo : $coverTheme, $topic, $on);
                if (trim((string) ($scene['motion_prompt'] ?? '')) === '' || $scene['motion_prompt'] === '-') {
                    $scene['motion_prompt'] = 'Slow Ken Burns push-in on the illustration plate, keep labels sharp and readable.';
                }
            }
        }
        unset($scene);

        return $scenes;
    }

    protected function promptRelatesToVo(string $prompt, string $vo): bool
    {
        $words = preg_split('/\s+/u', Str::lower(preg_replace('/[^\pL\pN\s]+/u', ' ', $vo) ?? $vo)) ?: [];
        $words = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 5));
        if ($words === []) {
            return true;
        }
        $hay = Str::lower($prompt);
        $hits = 0;
        foreach (array_slice($words, 0, 8) as $w) {
            if (str_contains($hay, $w)) {
                $hits++;
            }
        }

        // Relaks: cukup 1 kata kunci, atau prompt sudah style buku (dicek pemanggil).
        return $hits >= 1;
    }

    /**
     * Timeline panjang berurutan: Hook → Bab1 → bumper → Bab2 → bumper → Bab3 → Penutup.
     * 1 baris per vo_beat; plate gambar per visual_beat (dalam section yang sama).
     *
     * @param  array<int, array<string, mixed>>  $scenes
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeLongFormTimeline(array $scenes, string $coverTheme, string $topic): array
    {
        $voBeat = max(3, (int) $this->voBeatSeconds);
        $visualBeat = max(5, (int) $this->beatSeconds);
        $total = max($voBeat * 2, (int) $this->durationSeconds);
        $target = max(2, min(720, intdiv($total, $voBeat)));

        $source = array_values(array_filter($scenes, fn ($s) => is_array($s)));
        if ($source === []) {
            return [];
        }

        $segments = $this->buildOrderedLongFormSegments($source, $coverTheme, $topic);
        if ($segments === []) {
            return [];
        }

        $bumperSeconds = 10; // Bumper antar bab selalu 10 detik.
        $bumperBeatUnits = max(1, (int) ceil($bumperSeconds / $voBeat));

        $bumperIdx = [];
        $contentIdx = [];
        foreach ($segments as $i => $seg) {
            if (($seg['kind'] ?? '') === 'bumper') {
                $bumperIdx[] = $i;
            } else {
                $contentIdx[] = $i;
            }
        }

        $bumperBeats = count($bumperIdx) * $bumperBeatUnits;
        $contentBeats = max(count($contentIdx), $target - $bumperBeats);
        $alloc = $this->allocateSegmentBeats($segments, $contentIdx, $contentBeats);

        // Timeline butuh 1 kalimat / beat, tapi Gemini hanya diminta SPINE pendek (cepat).
        // Sisanya diisi PHP bridge — jangan minta 30+ kalimat/bab (itu yang bikin macet 10+ menit).
        $expandJobs = [];
        $spineJobs = [];
        foreach ($contentIdx as $i) {
            $beatsHere = max(1, (int) ($alloc[$i] ?? 1));
            $linesNeeded = min(36, max(count($segments[$i]['vo_lines'] ?? []), $beatsHere, 2));
            $spineNeed = min(8, max(3, count($segments[$i]['vo_lines'] ?? []) + 2));
            $sectionKey = (string) ($segments[$i]['section_key'] ?? $this->normalizeLongFormSection((string) ($segments[$i]['section'] ?? 'BAB')));
            $expandJobs[$i] = [
                'section' => (string) ($segments[$i]['section'] ?? 'BAB'),
                'section_key' => $sectionKey,
                'need' => $linesNeeded,
                'seed' => $segments[$i]['vo_lines'] ?? [],
            ];
            $spineJobs[$i] = [
                'section' => $sectionKey,
                'need' => $spineNeed,
                'seed' => $segments[$i]['vo_lines'] ?? [],
            ];
        }

        $this->reportProgress(78, 'Menulis kerangka naskah (cepat)…');
        $batch = $this->expandAllSectionsNarrationWithGemini($spineJobs, $topic, $coverTheme);
        $this->reportProgress(82, 'Melengkapi naskah per beat…');
        $batchNorm = [];
        foreach ($batch as $k => $lines) {
            $batchNorm[$this->normalizeLongFormSection((string) $k)] = $lines;
            $batchNorm[Str::upper(trim((string) $k))] = $lines;
        }
        foreach ($expandJobs as $i => $job) {
            $key = (string) ($job['section_key'] ?? $this->normalizeLongFormSection($job['section']));
            $fromBatch = $batchNorm[$key] ?? $batchNorm[Str::upper($key)] ?? null;
            $seed = is_array($fromBatch) && $fromBatch !== []
                ? $fromBatch
                : ($job['seed'] ?? []);
            // Selalu pad lokal (cepat) ke jumlah beat — tidak menunggu Gemini panjang.
            $segments[$i]['vo_lines'] = $this->padNarrationBridge(
                $seed,
                $job['need'],
                $job['section'],
                $topic,
                $coverTheme
            );
        }

        $out = [];
        $globalBeat = 0;
        $sectionPlateBase = 0;

        foreach ($segments as $si => $seg) {
            $kind = (string) ($seg['kind'] ?? 'content');
            $section = (string) ($seg['section'] ?? 'BAB');
            $beatsHere = $kind === 'bumper' ? 1 : max(1, (int) ($alloc[$si] ?? 1));
            $voLines = $kind === 'bumper' ? [] : array_values($seg['vo_lines'] ?? []);
            $voCount = max(1, count($voLines));
            $onSeed = trim((string) ($seg['on_screen'] ?? ''));

            $sectionPlateBase = $globalBeat > 0 ? (int) (($out[count($out) - 1]['visual_plate'] ?? 0) + 1) : 0;

            for ($b = 0; $b < $beatsHere; $b++) {
                $start = $globalBeat * $voBeat;
                if ($start >= $total) {
                    break 2;
                }

                if ($kind === 'bumper') {
                    $end = min($total, $start + $bumperSeconds);
                    $out[] = [
                        'section' => 'BUMPER',
                        'timecode' => $this->formatTimecode($start, $end),
                        'visual_type' => 'bumper',
                        'image_prompt' => 'Bumper KEA Abbasy 10 detik — logo sting + SFX brand, layar bersih.',
                        'motion_prompt' => 'Logo sting, hold ~10s, soft fade out.',
                        'vo' => '',
                        'on_screen' => 'KEA ABBASY',
                        'beat_seconds' => $bumperSeconds,
                        'visual_plate' => $sectionPlateBase,
                    ];
                    $globalBeat += $bumperBeatUnits;
                    continue;
                }

                $end = min($total, $start + $voBeat);
                // 1:1 kalau jumlah kalimat cukup; kalau tidak, sebar merata tanpa stuck di index 0.
                $lineIdx = $voCount >= $beatsHere
                    ? $b
                    : (int) min($voCount - 1, intdiv($b * $voCount, max(1, $beatsHere)));
                $vo = $this->fitVoToBeat(
                    $this->sanitizeSpokenVo((string) ($voLines[$lineIdx] ?? '')),
                    $topic,
                    $section
                );
                if ($this->looksLikeMetaVo($vo)) {
                    $vo = $this->fitVoToBeat(
                        $this->continueStoryFromPrevious(
                            (string) ($voLines[max(0, $lineIdx - 1)] ?? $topic),
                            $topic,
                            $coverTheme,
                            $lineIdx + 1
                        ),
                        $topic,
                        $section
                    );
                }
                $on = $this->onScreenFromVo($vo, $onSeed, $coverTheme, $lineIdx === 0);
                $localPlate = intdiv($b * $voBeat, $visualBeat);

                $out[] = [
                    'section' => $section,
                    'chapter_title' => $section,
                    'timecode' => $this->formatTimecode($start, $end),
                    'visual_type' => 'ai',
                    'image_prompt' => '',
                    'motion_prompt' => 'Slow Ken Burns push-in on the illustration plate, keep labels sharp and readable.',
                    'vo' => $vo,
                    'on_screen' => $on,
                    'beat_seconds' => $voBeat,
                    'visual_plate' => $sectionPlateBase + $localPlate,
                    'vo_line_index' => $lineIdx,
                ];
                $globalBeat++;
            }
        }

        // Safety: beat berurutan jangan naskah kembar (perbaiki sisa bug expand).
        $out = $this->dedupeConsecutiveVoRows($out, $topic, $coverTheme);
        // Gabung HANYA kalau benar-benar sisa kembar setelah dedupe (jarang).
        $out = $this->collapseIdenticalVoBeats($out, $total);

        // Image prompt per plate dari VO plate itu (setelah collapse, pakai vo baris).
        $platePrompt = [];
        foreach ($out as $idx => $sc) {
            $type = Str::lower((string) ($sc['visual_type'] ?? 'ai'));
            if (in_array($type, ['bumper', 'black'], true)) {
                continue;
            }
            $plate = (int) ($sc['visual_plate'] ?? 0);
            if (! isset($platePrompt[$plate])) {
                $vo = (string) ($sc['vo'] ?? '');
                $on = (string) ($sc['on_screen'] ?? '');
                $platePrompt[$plate] = $this->bookIllustrationPrompt(
                    $vo !== '' ? $vo : $coverTheme,
                    $topic,
                    $on
                );
            }
            $out[$idx]['image_prompt'] = $platePrompt[$plate];
            $out[$idx]['visual_type'] = 'ai';
            $out[$idx]['motion_prompt'] = 'Slow Ken Burns push-in on the illustration plate, keep labels sharp and readable.';
        }

        return $out;
    }

    /**
     * Ganti naskah kembar berurutan jadi kalimat lanjut (visual ikut beda).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function dedupeConsecutiveVoRows(array $rows, string $topic, string $coverTheme): array
    {
        $prevFp = null;
        $step = 0;
        foreach ($rows as $i => $row) {
            $type = Str::lower((string) ($row['visual_type'] ?? 'ai'));
            if (in_array($type, ['bumper', 'black'], true)) {
                $prevFp = null;
                continue;
            }
            $vo = trim((string) ($row['vo'] ?? ''));
            $fp = $this->voFingerprint($vo);
            if ($prevFp !== null && ($fp === $prevFp || $this->voTooSimilarToAny($vo, [($rows[$i - 1]['vo'] ?? '')]))) {
                $step++;
                $section = (string) ($row['section'] ?? 'BAB');
                $fresh = $this->bridgeAfterVo(
                    (string) ($rows[$i - 1]['vo'] ?? ''),
                    $section,
                    $topic,
                    $coverTheme,
                    $step
                );
                $rows[$i]['vo'] = $fresh;
                $rows[$i]['on_screen'] = $this->onScreenFromVo($fresh, '', $coverTheme, false);
                // Plate baru supaya visual tidak ikut kembar.
                $rows[$i]['visual_plate'] = (int) ($rows[$i - 1]['visual_plate'] ?? 0) + 1;
                $prevFp = $this->voFingerprint($fresh);
            } else {
                $prevFp = $fp;
                $step = 0;
            }
        }

        return $rows;
    }

    protected function bridgeAfterVo(
        string $prevVo,
        string $section,
        string $topic,
        string $coverTheme,
        int $step,
    ): string {
        return $this->continueStoryFromPrevious($prevVo, $topic, $coverTheme, max(1, $step));
    }

    /**
     * Lanjutkan cerita dari kalimat sebelumnya — tanpa label bab / meta internal.
     */
    protected function continueStoryFromPrevious(
        string $prevVo,
        string $topic,
        string $coverTheme,
        int $step,
    ): string {
        $vp = $this->voicePerspective;
        $prev = $this->sanitizeSpokenVo($prevVo);
        $clip = Str::limit($this->voFingerprint($prev), 40, '');
        $shortTopic = Str::limit($topic, 40, '');

        $options = [
            "Dari momen itu, {$vp} mulai melihat siapa yang sebenarnya memegang kendali.",
            'Yang tampak seperti kelembutan justru menutupi motif yang lebih gelap.',
            "Begitu empati {$vp} terbuka, permintaan berikutnya datang pelan tapi mengikat.",
            "Kalau {$vp} telusuri urutannya, tipuannya tidak lagi terasa kebetulan.",
            "Di titik ini {$shortTopic} bekerja: membuat {$vp} merasa wajib menolong.",
            "{$vp} tidak salah berempati — yang berbahaya adalah empati tanpa batas.",
            $clip !== ''
                ? "Setelah \"{$clip}\", penjaga {$vp} seharusnya naik, bukan turun."
                : "Penjaga {$vp} seharusnya naik di sini, bukan turun.",
            'Cerita ini masih satu benang: rasa iba dipakai sebagai pintu masuk kontrol.',
            "Perhatikan siapa yang paling tenang saat {$vp} mulai panik menjelaskan diri.",
            "Jangan putus di tengah: ingat motif di balik kerapuhan yang dipamerkan.",
        ];

        return $options[max(0, $step - 1) % count($options)];
    }

    /**
     * Gabung baris berurutan yang VO-nya sama → 1 scene dengan durasi lebih panjang.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function collapseIdenticalVoBeats(array $rows, int $totalSeconds): array
    {
        if ($rows === []) {
            return [];
        }
        $out = [];
        $cur = null;
        foreach ($rows as $row) {
            $type = Str::lower((string) ($row['visual_type'] ?? 'ai'));
            if ($type === 'bumper' || $type === 'black') {
                if ($cur !== null) {
                    $out[] = $cur;
                    $cur = null;
                }
                $out[] = $row;
                continue;
            }
            $vo = trim((string) ($row['vo'] ?? ''));
            if ($cur === null) {
                $cur = $row;
                continue;
            }
            $sameVo = $this->voFingerprint((string) ($cur['vo'] ?? '')) === $this->voFingerprint($vo)
                && (string) ($cur['section'] ?? '') === (string) ($row['section'] ?? '');
            if ($sameVo) {
                if (preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', (string) ($cur['timecode'] ?? ''), $m1)
                    && preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', (string) ($row['timecode'] ?? ''), $m2)
                ) {
                    $cur['timecode'] = $m1[1].' - '.$m2[2];
                }
                $cur['beat_seconds'] = (int) ($cur['beat_seconds'] ?? 0) + (int) ($row['beat_seconds'] ?? 0);
                continue;
            }
            $out[] = $cur;
            $cur = $row;
        }
        if ($cur !== null) {
            $out[] = $cur;
        }

        return $out;
    }

    /**
     * Susun segmen berurutan dari outline Gemini + sisip bumper antar bab.
     *
     * @param  array<int, array<string, mixed>>  $source
     * @return list<array{kind:string,section:string,vo_lines:list<string>,on_screen:string}>
     */
    protected function buildOrderedLongFormSegments(array $source, string $coverTheme, string $topic): array
    {
        $buckets = [];
        foreach ($source as $s) {
            $type = Str::lower((string) ($s['visual_type'] ?? 'ai'));
            $section = $this->normalizeLongFormSection((string) ($s['section'] ?? ''), $type);
            if ($type === 'bumper' || $section === 'BUMPER') {
                $buckets[] = [
                    'kind' => 'bumper',
                    'section' => 'BUMPER',
                    'vo_lines' => [],
                    'on_screen' => 'KEA ABBASY',
                ];
                continue;
            }
            if ($type === 'black') {
                // Black jarang: treat as short content pause with empty VO → skip as filler
                continue;
            }

            $vo = trim((string) ($s['vo'] ?? ''));
            $on = trim((string) ($s['on_screen'] ?? ''));
            $rawSection = (string) ($s['section'] ?? '');
            $last = $buckets !== [] ? count($buckets) - 1 : -1;
            if ($last >= 0
                && ($buckets[$last]['kind'] ?? '') === 'content'
                && ($buckets[$last]['section'] ?? '') === $section
            ) {
                if ($vo !== '') {
                    $buckets[$last]['vo_lines'][] = $vo;
                }
                if ($on !== '' && ($buckets[$last]['on_screen'] ?? '') === '') {
                    $buckets[$last]['on_screen'] = $on;
                }
                if (($buckets[$last]['raw_section'] ?? '') === '' && $rawSection !== '') {
                    $buckets[$last]['raw_section'] = $rawSection;
                }
            } else {
                $buckets[] = [
                    'kind' => 'content',
                    'section' => $section,
                    'raw_section' => $rawSection,
                    'vo_lines' => $vo !== '' ? [$vo] : [],
                    'on_screen' => $on,
                ];
            }
        }

        // Pastikan urutan standar + bumper antar bab.
        $orderedKeys = ['GOLDEN HOOK', 'BAB 1', 'BAB 2', 'BAB 3', 'PENUTUP'];
        $bySection = [];
        foreach ($buckets as $b) {
            if (($b['kind'] ?? '') !== 'content') {
                continue;
            }
            $sec = $b['section'];
            if (! isset($bySection[$sec])) {
                $bySection[$sec] = $b;
            } else {
                $bySection[$sec]['vo_lines'] = array_merge(
                    $bySection[$sec]['vo_lines'],
                    $b['vo_lines']
                );
                if (($bySection[$sec]['on_screen'] ?? '') === '' && ($b['on_screen'] ?? '') !== '') {
                    $bySection[$sec]['on_screen'] = $b['on_screen'];
                }
            }
        }

        // Fallback kalau outline Gemini tidak pakai label bab yang jelas.
        if ($bySection === []) {
            $allVo = $this->flattenLongFormVoLines($source, $coverTheme, $topic);
            $chunk = max(1, (int) ceil(count($allVo) / 4));
            $labels = ['GOLDEN HOOK', 'BAB 1', 'BAB 2', 'PENUTUP'];
            foreach ($labels as $i => $label) {
                $slice = array_slice($allVo, $i * $chunk, $chunk) ?: [Str::limit($topic, 80)];
                $bySection[$label] = [
                    'kind' => 'content',
                    'section' => $label,
                    'vo_lines' => array_values($slice),
                    'on_screen' => Str::upper(Str::limit($label, 34, '')),
                ];
            }
        }

        $segments = [];
        $prevContent = null;
        foreach ($orderedKeys as $key) {
            if (! isset($bySection[$key])) {
                continue;
            }
            $seg = $bySection[$key];
            $flat = $this->splitVoLinesOnly($seg['vo_lines'] ?? []);
            $seg['vo_lines'] = $flat !== [] ? $flat : ["Bagian {$key}: {$topic}."];
            $seg['section'] = $this->makeChapterTitle(
                (string) ($seg['raw_section'] ?? $key),
                $seg['vo_lines'],
                (string) ($seg['on_screen'] ?? ''),
                $topic
            );
            $seg['section_key'] = $key;

            // Bumper antar bab (setelah hook/bab, sebelum bab berikutnya / penutup).
            if ($prevContent !== null && $prevContent !== 'PENUTUP') {
                $segments[] = [
                    'kind' => 'bumper',
                    'section' => 'BUMPER',
                    'section_key' => 'BUMPER',
                    'vo_lines' => [],
                    'on_screen' => 'KEA ABBASY',
                ];
            }

            $segments[] = $seg;
            $prevContent = $key;
        }

        // Sisakan section lain (kalau ada label custom) di tengah sebelum penutup.
        foreach ($bySection as $key => $seg) {
            if (in_array($key, $orderedKeys, true)) {
                continue;
            }
            $flat = $this->splitVoLinesOnly($seg['vo_lines'] ?? []);
            $seg['vo_lines'] = $flat !== [] ? $flat : ["Bagian {$key}."];
            $insertAt = count($segments);
            foreach ($segments as $i => $existing) {
                if (($existing['section'] ?? '') === 'PENUTUP') {
                    $insertAt = $i;
                    break;
                }
            }
            array_splice($segments, $insertAt, 0, [[
                'kind' => 'bumper',
                'section' => 'BUMPER',
                'vo_lines' => [],
                'on_screen' => 'KEA ABBASY',
            ], $seg]);
        }

        return $segments;
    }

    /**
     * Pecah VO jadi kalimat utuh. Fragmen pendek digabung ke kalimat sebelumnya.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    protected function splitVoLinesOnly(array $lines): array
    {
        $raw = [];
        foreach ($lines as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            $parts = preg_split('/(?<=[.!?…])\s+/u', $v) ?: [$v];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                $words = preg_split('/\s+/u', $p) ?: [];
                if (count($words) > 28) {
                    for ($w = 0; $w < count($words); $w += 14) {
                        $chunk = trim(implode(' ', array_slice($words, $w, 14)));
                        if ($chunk !== '') {
                            $raw[] = $chunk.(str_ends_with($chunk, '.') ? '' : '.');
                        }
                    }
                } else {
                    $raw[] = $p;
                }
            }
        }

        // Gabung fragmen < 5 kata ke baris sebelumnya (hindari "derita.").
        $out = [];
        foreach ($raw as $p) {
            $wordCount = count(preg_split('/\s+/u', $p) ?: []);
            if ($out !== [] && $wordCount < 5) {
                $out[count($out) - 1] = rtrim($out[count($out) - 1], " \t.").' '.$p;
                continue;
            }
            if ($this->looksLikeMetaVo($p)) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }

    protected function normalizeLongFormSection(string $raw, string $visualType = 'ai'): string
    {
        if ($visualType === 'bumper') {
            return 'BUMPER';
        }
        $s = Str::upper(trim($raw));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        // "BAB 1: JUDUL" → pakai bagian sebelum colon untuk key
        if (str_contains($s, ':')) {
            $s = trim(explode(':', $s, 2)[0]);
        }
        if ($s === '' || $s === 'BLUEPRINT') {
            return 'BAB 1';
        }
        if (str_contains($s, 'BUMPER')) {
            return 'BUMPER';
        }
        if (preg_match('/HOOK|GOLDEN/', $s)) {
            return 'GOLDEN HOOK';
        }
        if (preg_match('/PENUTUP|CLOSING|CTA|AKHIR/', $s)) {
            return 'PENUTUP';
        }
        if (preg_match('/BAB\s*3|CHAPTER\s*3|BAGIAN\s*3/', $s)) {
            return 'BAB 3';
        }
        if (preg_match('/BAB\s*2|CHAPTER\s*2|BAGIAN\s*2/', $s)) {
            return 'BAB 2';
        }
        if (preg_match('/BAB\s*1|CHAPTER\s*1|BAGIAN\s*1/', $s)) {
            return 'BAB 1';
        }
        if (preg_match('/BAB|CHAPTER|BAGIAN/', $s)) {
            return 'BAB 1';
        }

        return Str::limit($s, 40, '');
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  list<int>  $contentIdx
     * @return array<int, int> index segment => jumlah beat
     */
    protected function allocateSegmentBeats(array $segments, array $contentIdx, int $contentBeats): array
    {
        $alloc = [];
        $n = count($contentIdx);
        if ($n === 0) {
            return $alloc;
        }

        $weights = [];
        foreach ($contentIdx as $i) {
            $sec = Str::upper((string) ($segments[$i]['section_key'] ?? $segments[$i]['section'] ?? ''));
            $weights[$i] = match (true) {
                str_contains($sec, 'HOOK') => 18,
                str_contains($sec, 'BAB 1') => 24,
                str_contains($sec, 'BAB 2') => 24,
                str_contains($sec, 'BAB 3') => 20,
                str_contains($sec, 'PENUTUP') => 14,
                default => 16,
            };
        }
        $sumW = array_sum($weights) ?: 1;
        $assigned = 0;
        $last = $contentIdx[$n - 1];
        foreach ($contentIdx as $i) {
            if ($i === $last) {
                continue;
            }
            $beats = max(2, (int) floor($contentBeats * ($weights[$i] / $sumW)));
            $alloc[$i] = $beats;
            $assigned += $beats;
        }
        $alloc[$last] = max(2, $contentBeats - $assigned);

        // Kalau total kurang dari contentBeats karena floor, tambah ke bab tengah.
        $gap = $contentBeats - array_sum($alloc);
        $cursor = 0;
        while ($gap > 0 && $n > 0) {
            $i = $contentIdx[$cursor % $n];
            $alloc[$i]++;
            $gap--;
            $cursor++;
        }

        return $alloc;
    }

    /**
     * @param  array<int, array<string, mixed>>  $source
     * @return list<string>
     */
    protected function flattenLongFormVoLines(array $source, string $coverTheme, string $topic): array
    {
        $lines = [];
        foreach ($source as $s) {
            $v = trim((string) ($s['vo'] ?? ''));
            if ($v === '') {
                continue;
            }
            // Pecah kalau sebelumnya sudah digabung jadi paragraf panjang.
            $parts = preg_split('/(?<=[.!?…])\s+/u', $v) ?: [$v];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                // Kalau masih kebanyakan (gabungan spasi tanpa titik), potong per ~12 kata.
                $words = preg_split('/\s+/u', $p) ?: [];
                if (count($words) > 22) {
                    for ($w = 0; $w < count($words); $w += 12) {
                        $chunk = trim(implode(' ', array_slice($words, $w, 12)));
                        if ($chunk !== '') {
                            $lines[] = $chunk.(str_ends_with($chunk, '.') ? '' : '.');
                        }
                    }
                } else {
                    $lines[] = $p;
                }
            }
        }

        if ($lines === []) {
            $lines = [
                "Mari bedah: {$topic}.",
                'Pola ini sering tidak terasa, sampai biayanya sudah mahal.',
                'Kunci pertama: sadari bingkai percakapan.',
                'Kunci kedua: jangan langsung membela diri.',
                'Kunci ketiga: tanya balik motifnya.',
            ];
        }

        // Pastikan cukup variasi untuk cycle panjang.
        $extra = [
            "Ini bukan soal menang argumen — soal merebut kendali narasi tentang {$topic}.",
            'Kalau kamu buru-buru menjelaskan, bingkai lawan yang menang.',
            'Diam 2 detik sering lebih kuat dari 20 kalimat pembelaan.',
            'Catat polanya. Besok kamu akan ketemu lagi.',
            'Ubah satu kebiasaan kecil hari ini, sebelum pola itu yang mengubahmu.',
        ];
        while (count($lines) < 40) {
            foreach ($extra as $e) {
                $lines[] = $e;
                if (count($lines) >= 40) {
                    break;
                }
            }
        }

        return array_values($lines);
    }

    /**
     * Expand seed jadi N kalimat naskah yang NYAMBUNG (cerita maju), bukan template acak.
     *
     * @param  list<string>  $seed
     * @return list<string>
     */
    protected function expandSectionNarration(
        array $seed,
        int $need,
        string $section,
        string $topic,
        string $coverTheme,
    ): array {
        $need = max(1, $need);
        $seed = $this->splitVoLinesOnly($seed);
        if ($seed === []) {
            $seed = [$this->sectionSeedLine($section, $topic, $coverTheme)];
        }

        if (count($seed) >= $need) {
            return array_slice($seed, 0, $need);
        }

        $gemini = $this->expandNarrationWithGemini($seed, $need, $section, $topic);
        if ($gemini !== null && count($gemini) >= min($need, 3)) {
            return $this->padNarrationBridge($gemini, $need, $section, $topic, $coverTheme);
        }

        return $this->padNarrationBridge($seed, $need, $section, $topic, $coverTheme);
    }

    /**
     * Satu request: expand semua bab jadi naskah berkesinambungan.
     *
     * @param  array<int, array{section:string,need:int,seed:list<string>}>  $jobs
     * @return array<string, list<string>>
     */
    protected function expandAllSectionsNarrationWithGemini(array $jobs, string $topic, string $coverTheme): array
    {
        $apiKey = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        if ($apiKey === '' || $jobs === []) {
            return [];
        }

        $totalNeed = 0;
        foreach ($jobs as $job) {
            $totalNeed += (int) ($job['need'] ?? 0);
        }
        // Terlalu berat → skip (pakai seed + PHP). Hindari macet Hostinger.
        if ($totalNeed > 40) {
            Log::info('blueprint.narration_expand.skip_heavy', ['total_need' => $totalNeed]);

            return [];
        }

        $model = (string) config('blueprint.gemini_model', 'gemini-3.8-flash');
        $vp = $this->voicePerspective;
        $voWords = $this->targetVoWords();
        $voMin = $voWords['min'];
        $voMax = $voWords['max'];
        $voBeatSec = $this->voBeatSeconds;
        $blocks = [];
        foreach ($jobs as $job) {
            $seed = $this->splitVoLinesOnly($job['seed'] ?? []);
            $seedTxt = $seed === []
                ? '(kosong — buat benih baru yang kuat)'
                : collect($seed)->take(4)->map(fn ($l, $i) => ($i + 1).'. '.$l)->implode("\n");
            $need = min(8, max(3, (int) ($job['need'] ?? 3)));
            $blocks[] = "### {$job['section']} (tepat {$need} kalimat SPINE)\nBenih:\n{$seedTxt}";
        }
        $blockText = implode("\n\n", $blocks);

        $prompt = <<<PROMPT
Kamu ghostwriter naskah VO (Bahasa Indonesia), sudut "{$vp}".
Topik: {$topic}
Cover: {$coverTheme}

{$blockText}

Tulis SPINE pendek per bagian (bukan naskah penuh). Kalimat nyambung antar bab seperti cerita lisan.
DILARANG keras di VO: "Lanjut benang", "GOLDEN HOOK:", "BAB 1:", "uji satu situasi", "Poin/Latihan/Catatan N".
VO hanya narasi untuk penonton, tanpa label internal.
Tiap kalimat {$voMin}-{$voMax} kata (sesuai beat naskah {$voBeatSec}s).

JSON SAJA:
{"sections":{"GOLDEN HOOK":["..."],"BAB 1":["..."],"BAB 2":["..."],"BAB 3":["..."],"PENUTUP":["..."]}}
PROMPT;

        try {
            Log::info('blueprint.narration_expand.request', [
                'sections' => count($jobs),
                'total_need' => $totalNeed,
            ]);
            $response = Http::timeout(28)
                ->connectTimeout(8)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                    [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'temperature' => 0.65,
                            'maxOutputTokens' => 3072,
                            'responseMimeType' => 'application/json',
                        ],
                    ]
                );
            if (! $response->successful()) {
                Log::warning('blueprint.narration_expand.http', ['status' => $response->status()]);

                return [];
            }
            $text = '';
            foreach ((array) data_get($response->json(), 'candidates.0.content.parts', []) as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }
            $decoded = $this->decodeGeminiBlueprintJson($text);
            $sections = $decoded['sections'] ?? $decoded;
            if (! is_array($sections)) {
                return [];
            }
            $out = [];
            foreach ($sections as $key => $lines) {
                if (! is_array($lines)) {
                    continue;
                }
                $clean = $this->splitVoLinesOnly(array_map('strval', $lines));
                $clean = array_values(array_filter($clean, fn ($l) => ! $this->looksLikeMetaVo($l)));
                if ($clean !== []) {
                    $out[(string) $key] = $clean;
                }
            }
            Log::info('blueprint.narration_expand.ok', ['keys' => array_keys($out)]);

            return $out;
        } catch (\Throwable $e) {
            Log::warning('blueprint.narration_expand_fail', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  list<string>  $seed
     * @return list<string>|null
     */
    protected function expandNarrationWithGemini(
        array $seed,
        int $need,
        string $section,
        string $topic,
    ): ?array {
        $batch = $this->expandAllSectionsNarrationWithGemini([
            0 => ['section' => $section, 'need' => $need, 'seed' => $seed],
        ], $topic, $section);

        return $batch[$section] ?? null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    protected function padNarrationBridge(
        array $lines,
        int $need,
        string $section,
        string $topic,
        string $coverTheme,
    ): array {
        $lines = array_values(array_filter(array_map(
            fn ($l) => $this->sanitizeSpokenVo((string) $l),
            $lines
        ), fn ($l) => $l !== '' && ! $this->looksLikeMetaVo($l)));

        if ($lines === []) {
            $lines = [$this->sectionSeedLine($section, $topic, $coverTheme)];
        }

        $out = [];
        foreach ($lines as $line) {
            if (count($out) >= $need) {
                break;
            }
            $fitted = $this->fitVoToBeat($line, $topic, $section);
            if ($this->looksLikeMetaVo($fitted) || $this->voTooSimilarToAny($fitted, $out)) {
                continue;
            }
            $out[] = $fitted;
        }

        $guard = 0;
        while (count($out) < $need && $guard < ($need * 3)) {
            $prev = $out !== [] ? $out[count($out) - 1] : ($lines[0] ?? '');
            $line = $this->fitVoToBeat(
                $this->continueStoryFromPrevious($prev, $topic, $coverTheme, count($out) + 1),
                $topic,
                $section
            );
            if (! $this->looksLikeMetaVo($line) && ! $this->voTooSimilarToAny($line, $out)) {
                $out[] = $line;
            } else {
                $out[] = $this->fitVoToBeat(
                    $this->continueStoryFromPrevious($prev.' '.$topic, $topic, $coverTheme, $guard + 7),
                    $topic,
                    $section
                );
            }
            $guard++;
        }

        return array_slice($out, 0, $need);
    }

    protected function looksLikeMetaVo(string $line): bool
    {
        $l = Str::lower($line);

        return (bool) preg_match(
            '/lanjut\s+benang|golden\s*hook\s*:|^\s*bab\s*\d+\s*:|^\s*penutup\s*:|uji satu situasi|polanya kebaca|^\s*(poin|langkah|latihan|catatan|detail|peringatan)\s*\d+/iu',
            $l
        );
    }

    protected function sectionSeedLine(string $section, string $topic, string $coverTheme): string
    {
        $sec = Str::upper($this->normalizeLongFormSection($section));
        $vp = $this->voicePerspective;
        $short = Str::limit($topic, 50, '');

        if (str_contains($sec, 'HOOK')) {
            return "Ada cerita nyata yang membuka pola gelap di balik {$short}, dan {$vp} perlu mendengarnya sampai selesai.";
        }
        if (str_contains($sec, 'PENUTUP')) {
            return "Ringkasnya: {$vp} jaga bingkai dan batas, bukan sibuk membuktikan diri soal {$short}.";
        }

        return "Dari sini {$vp} bedah bagaimana {$short} bekerja diam-diam dalam percakapan sehari-hari.";
    }

    /**
     * @param  list<string>  $existing
     */
    protected function voTooSimilarToAny(string $line, array $existing): bool
    {
        $a = $this->voFingerprint($line);
        if ($a === '') {
            return false;
        }
        foreach ($existing as $other) {
            $b = $this->voFingerprint((string) $other);
            if ($b === '') {
                continue;
            }
            similar_text($a, $b, $pct);
            if ($pct >= 72) {
                return true;
            }
        }

        return false;
    }

    protected function voFingerprint(string $line): string
    {
        $line = Str::lower(preg_replace('/[^\pL\pN\s]+/u', ' ', $line) ?? $line);
        $line = preg_replace('/\s+/', ' ', trim($line)) ?? '';
        // Buang suffix lemah lama kalau masih nempel.
        $line = preg_replace('/\b(perhatikan lagi|sudut berbeda|lapisan ke|kaitkan dengan|jangan lewati|simpan poin).*$/u', '', $line) ?? $line;

        return trim($line);
    }

    protected function onScreenFromVo(string $vo, string $sectionSeed, string $coverTheme, bool $preferSeed): string
    {
        if ($preferSeed && $sectionSeed !== '' && ! str_contains(Str::lower($sectionSeed), 'tahun 1974')) {
            return Str::upper(Str::limit($sectionSeed, 34, ''));
        }

        // Ambil 3–6 kata bermakna dari VO (bukan menempel seluruh kalimat).
        $words = preg_split('/\s+/u', trim(preg_replace('/[^\pL\pN\s]+/u', ' ', $vo) ?? $vo)) ?: [];
        $stop = ['yang', 'dan', 'atau', 'dengan', 'untuk', 'dari', 'ini', 'itu', 'ada', 'di', 'ke', 'pada', 'oleh', 'karena', 'kalau', 'tapi', 'sudah', 'akan', 'dalam', 'adalah', 'the', 'a', 'an'];
        $picked = [];
        foreach ($words as $w) {
            $lw = Str::lower($w);
            if (mb_strlen($lw) < 4 || in_array($lw, $stop, true)) {
                continue;
            }
            $picked[] = $w;
            if (count($picked) >= 5) {
                break;
            }
        }
        $label = implode(' ', $picked);
        if ($label === '') {
            $label = $sectionSeed !== '' ? $sectionSeed : $coverTheme;
        }

        return Str::upper(Str::limit($label, 34, ''));
    }

    protected function varyLongFormVoLine(string $vo, string $topic, int $index, int $cycle): string
    {
        return $this->sectionSeedLine('BAB', $topic, $topic);
    }

    /**
     * Paksa karakter Brand Kit (stickman) selalu menang atas "photorealistic" style.
     * Hanya untuk Reels pendek / foto / text — bukan video panjang.
     */
    protected function lockBrandCharacterInPrompt(string $prompt, BrandKit $kit, string $format = self::FORMAT_REELS_VO): string
    {
        if ($format === self::FORMAT_REELS_PANJANG) {
            return trim($prompt);
        }

        $prompt = trim($prompt);
        $char = trim((string) ($kit->character_visual ?: ''));
        if ($char === '') {
            $char = 'A stickman figure made of thin black wireframe lines, wearing a sharp black executive tuxedo with white shirt and black bowtie, small yellow rubber duck prop, minimal face, no detailed facial features';
        }

        $isStickman = (bool) preg_match('/stick\s*-?\s*man|wireframe|stick\s*figure/i', $char.' '.$kit->character_name);

        // Hapus cue yang bikin model AI jatuh ke orang real.
        if ($isStickman) {
            $prompt = preg_replace(
                '/\b(photorealistic\s+(human|person|man|woman|people|face|skin)|realistic\s+(human|person|man|woman)|real\s+human|live[\s-]?action\s+person|portrait\s+of\s+a\s+(man|woman|person))\b/iu',
                'stylized stickman character',
                $prompt
            ) ?? $prompt;
            // "Photorealistic cinematic photo" → environment only
            $prompt = preg_replace(
                '/\bPhotorealistic\b/i',
                'Photorealistic environment only (character remains stickman wireframe)',
                $prompt
            ) ?? $prompt;
        }

        $lock = $isStickman
            ? 'CHARACTER LOCK (NON-NEGOTIABLE): The ONLY character is the brand stickman — '.$char.'. ABSOLUTELY NO real human, NO photorealistic person, NO detailed human face/skin/hair, NO 3D CGI human. Stick-figure wireframe body only. Photorealism applies ONLY to the city/background/lighting, never to the character.'
            : 'CHARACTER LOCK (NON-NEGOTIABLE): The ONLY character is "'.$kit->character_name.'" — '.$char.'. Do not replace with a different person or generic model.';

        // Kalau sudah ada lock, refresh; kalau belum, prepend.
        if (str_contains(Str::lower($prompt), 'character lock')) {
            $prompt = preg_replace('/CHARACTER LOCK \(NON-NEGOTIABLE\):.*?(?=\.\s+[A-Z"]|\z)/si', '', $prompt) ?? $prompt;
            $prompt = trim($prompt);
        }

        if ($prompt === '') {
            $style = trim((string) ($kit->visual_style ?: 'Cinematic urban night, neon lights, dramatic rim lighting'));
            $ratio = $format === self::FORMAT_FOTO ? ($kit->aspect_ratio ?: '4:5') : ($kit->aspect_ratio ?: '9:16');

            return $lock.' A vertical '.$ratio.' cinematic social media frame. '.$style.'.';
        }

        // Pastikan deskripsi karakter brand muncul di depan.
        if (! str_contains(Str::lower($prompt), 'stickman') && $isStickman) {
            $prompt = $char.'. '.$prompt;
        }

        return $lock.' '.$prompt;
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
                    $payload['scenes'][0]['image_prompt'] = $this->lockBrandCharacterInPrompt(
                        rtrim($img, " \t\n\r\0\x0B.").'. '.$textClause.'.',
                        $kit,
                        $format
                    );
                } else {
                    $char = $kit->character_visual ?: 'stickman wireframe executive tuxedo with yellow rubber duck';
                    $style = $kit->visual_style ?: 'Cinematic high contrast urban night environment (character remains stickman)';
                    $ratio = $format === self::FORMAT_FOTO ? ($kit->aspect_ratio ?: '4:5') : ($kit->aspect_ratio ?: '9:16');
                    $payload['scenes'][0]['image_prompt'] = $this->lockBrandCharacterInPrompt(
                        "A vertical {$ratio} cinematic social media frame. {$char}. {$style}. {$textClause}. High contrast, readable typography, film grain, no offensive elements.",
                        $kit,
                        $format
                    );
                }
            } else {
                $payload['scenes'][0]['image_prompt'] = $this->lockBrandCharacterInPrompt($img, $kit, $format);
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
            return $this->lockBrandCharacterInPrompt($prompt, $kit, $format);
        }

        // Single-line legacy cover still accepted if exact quote present.
        $legacy = Str::upper(trim(str_replace('|', ' ', $coverTheme)));
        if ($prompt !== '' && $legacy !== '' && str_contains($prompt, '"'.$legacy.'"')) {
            return $this->lockBrandCharacterInPrompt(
                rtrim($prompt, " \t\n\r\0\x0B.").'. '.$titleClause.'.',
                $kit,
                $format
            );
        }

        if ($prompt !== '') {
            // Buang frasa generik tanpa judul, lalu sisipkan judul exact.
            $prompt = preg_replace('/,?\s*(bold\s+)?(moody\s+)?(anton\s+)?title overlay aesthetic\.?/i', '', $prompt) ?? $prompt;

            return $this->lockBrandCharacterInPrompt(
                rtrim($prompt, " \t\n\r\0\x0B.").'. '.$titleClause.'.',
                $kit,
                $format
            );
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
