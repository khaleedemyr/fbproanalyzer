<?php

namespace App\Services\Brief;

use App\Jobs\GenerateBlueprintJob;
use App\Models\AnalyticsAiAnalysis;
use App\Models\AngleFinding;
use App\Models\BrandKit;
use App\Models\ContentBrief;
use App\Models\ContentBlueprint;
use App\Models\TrendSignal;
use App\Models\User;
use App\Services\Blueprint\BlueprintGeneratorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BriefStudioService
{
    public function __construct(
        private BlueprintGeneratorService $blueprints,
    ) {
    }

    /**
     * Preview default picks from Radar + Angle + Analytics.
     *
     * @param  array{topic?:string|null,trend_signal_ids?:array<int,int>|null,angle_finding_id?:int|null,analytics_ai_analysis_id?:int|null}  $filters
     * @return array<string, mixed>
     */
    public function sources(User $user, array $filters = []): array
    {
        $picked = $this->pickSources($user, $filters);

        $radarCards = $picked['radar']->map(fn (TrendSignal $s) => $this->signalCard($s))->values()->all();
        $angleCard = $picked['angle']
            ? $this->angleCard($picked['angle'], $picked['angle_indexes'])
            : null;
        $analyticsArr = $picked['analytics']?->toAnalysisArray();

        return [
            'radar' => $radarCards,
            'angle_finding' => $angleCard,
            'analytics' => $analyticsArr,
            'theme_suggestions' => $this->themeSuggestions($radarCards, $angleCard, $analyticsArr, $picked['seed_topic'] ?? ''),
            'available' => [
                'radar_count' => $this->safeCount(fn () => TrendSignal::query()
                    ->where('niche', config('radar.niche', 'psikologi'))
                    ->where(function ($q) {
                        $q->where('observed_at', '>=', now()->subDays(14))->orWhereNull('observed_at');
                    })
                    ->count()),
                'angle_count' => $this->safeCount(fn () => AngleFinding::query()->where('user_id', $user->id)->count()),
                'analytics_count' => $this->safeCount(fn () => AnalyticsAiAnalysis::query()->where('user_id', $user->id)->count()),
            ],
            'warnings' => $picked['warnings'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $radar
     * @param  array<string, mixed>|null  $angle
     * @param  array<string, mixed>|null  $analytics
     * @return array<int, array{label:string,source:string,value:string}>
     */
    protected function themeSuggestions(array $radar, ?array $angle, ?array $analytics, string $seed): array
    {
        $out = [];
        $seen = [];

        $push = function (string $value, string $source, ?string $label = null) use (&$out, &$seen): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            $key = Str::lower(preg_replace('/\s+/', ' ', $value) ?? $value);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'label' => $label ?: Str::limit($value, 72, '…'),
                'source' => $source,
                'value' => Str::limit($value, 200, ''),
            ];
        };

        foreach (array_slice((array) data_get($analytics, 'title_hooks', []), 0, 8) as $hook) {
            $push((string) $hook, 'Analisa AI');
        }
        foreach ((array) data_get($angle, 'angles', []) as $a) {
            $push((string) data_get($a, 'title', ''), 'Angle', (string) data_get($a, 'title', ''));
            $hook = trim((string) data_get($a, 'hook', ''));
            if ($hook !== '') {
                $push($hook, 'Angle hook', Str::limit($hook, 64, '…'));
            }
        }
        foreach ($radar as $r) {
            $push((string) ($r['keyword'] ?: $r['title'] ?? ''), 'Radar');
        }
        if ($seed !== '') {
            $push($seed, 'Seed');
        }

        return array_slice($out, 0, 16);
    }

    protected function safeCount(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable $e) {
            if ($this->isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    protected function isMissingTable(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Base table or view not found')
            || str_contains($msg, "doesn't exist");
    }

    /**
     * Compose a merged resume (brief) from the 3 sources.
     *
     * @param  array{topic?:string|null,trend_signal_ids?:array<int,int>|null,angle_finding_id?:int|null,angle_indexes?:array<int,int>|null,analytics_ai_analysis_id?:int|null}  $filters
     */
    public function compose(User $user, array $filters = []): ContentBrief
    {
        $picked = $this->pickSources($user, $filters);
        if ($picked['radar']->isEmpty() && ! $picked['angle'] && ! $picked['analytics']) {
            throw new \RuntimeException(
                'Belum ada data gabungan. Sync Auto Radar, jalankan Angle Finder, dan buat Analisa AI dulu.'
            );
        }

        $kit = BrandKit::defaultFor($user);
        $topic = trim((string) ($filters['topic'] ?? $picked['seed_topic'] ?? ''));
        if ($topic === '') {
            $topic = 'psikologi gelap viral';
        }

        $snapshot = [
            'radar' => $picked['radar']->map(fn (TrendSignal $s) => $this->signalCard($s))->values()->all(),
            'angle_finding' => $picked['angle']
                ? $this->angleCard($picked['angle'], $picked['angle_indexes'])
                : null,
            'analytics' => $picked['analytics']?->toAnalysisArray(),
            'warnings' => $picked['warnings'],
        ];

        $gemini = $this->composeWithGemini($kit, $topic, $snapshot);
        if ($gemini !== null) {
            $resume = $gemini['resume'];
            $engine = 'gemini';
            $warning = null;
        } else {
            $resume = $this->composeTemplate($topic, $snapshot);
            $engine = 'template';
            $warning = 'Gemini tidak tersedia — resume pakai template gabungan.';
        }

        $markdown = $this->resumeToMarkdown($resume, $snapshot);

        return ContentBrief::query()->create([
            'user_id' => $user->id,
            'title' => Str::limit((string) ($resume['working_title'] ?? $resume['headline'] ?? $topic), 160, ''),
            'status' => 'ready',
            'engine' => $engine,
            'source_topic' => $topic,
            'trend_signal_ids' => $picked['radar']->pluck('id')->values()->all(),
            'angle_finding_id' => $picked['angle']?->id,
            'angle_indexes' => $picked['angle_indexes'],
            'analytics_ai_analysis_id' => $picked['analytics']?->id,
            'inputs_snapshot' => $snapshot,
            'resume' => $resume,
            'resume_markdown' => $markdown,
            'warning' => $warning,
        ]);
    }

    /**
     * @param  array{format?:string|null,planned_date?:string|null,planned_time?:string|null,duration_seconds?:int|null,duration_minutes?:int|null,visual_beat_seconds?:int|null,vo_beat_seconds?:int|null,beat_seconds?:int|null,prefer_stock?:bool|null,voice_perspective?:string|null,force?:bool,topic?:string|null,working_title?:string|null,hook?:string|null,angle?:string|null}  $options
     */
    public function generateBlueprint(User $user, ContentBrief $brief, array $options = []): ContentBlueprint
    {
        abort_unless($brief->user_id === $user->id, 403);

        $resume = $brief->resume ?? [];
        $workingTitle = trim((string) ($options['working_title'] ?? $resume['working_title'] ?? $brief->title ?? $brief->source_topic ?? 'Konten viral'));
        $hook = trim((string) ($options['hook'] ?? $resume['hook'] ?? ''));
        $angle = trim((string) ($options['angle'] ?? $resume['angle'] ?? ''));
        $seedTopic = trim((string) ($options['topic'] ?? $brief->source_topic ?? ''));

        // Persist edit ringan ke resume supaya generate berikutnya tidak “balik” ke judul lama.
        $resume['working_title'] = $workingTitle !== '' ? $workingTitle : ($resume['working_title'] ?? '');
        if ($hook !== '') {
            $resume['hook'] = $hook;
        }
        if ($angle !== '') {
            $resume['angle'] = $angle;
        }
        $brief->resume = $resume;
        if ($workingTitle !== '') {
            $brief->title = Str::limit($workingTitle, 160, '');
        }
        if ($seedTopic !== '') {
            $brief->source_topic = Str::limit($seedTopic, 255, '');
        }
        $brief->save();

        $topic = $workingTitle !== '' ? $workingTitle : ($seedTopic !== '' ? $seedTopic : 'Konten viral');
        if ($seedTopic !== '' && ! str_contains(Str::lower($topic), Str::lower($seedTopic))) {
            $topic = $seedTopic.' — '.$topic;
        }
        if ($hook !== '') {
            $topic .= ' — '.$hook;
        } elseif ($angle !== '') {
            $topic .= ' — '.$angle;
        }

        $format = (string) ($options['format'] ?? $resume['suggested_format'] ?? BlueprintGeneratorService::FORMAT_REELS_VO);
        $primarySignalId = collect($brief->trend_signal_ids ?? [])->first();
        $signal = $primarySignalId
            ? TrendSignal::query()->find($primarySignalId)
            : null;

        $visualBeat = $options['visual_beat_seconds'] ?? $options['beat_seconds'] ?? null;
        $isLong = $format === BlueprintGeneratorService::FORMAT_REELS_PANJANG;
        // Video panjang: AI wajib (tidak ada fallback template).
        $useAi = $isLong
            ? true
            : (array_key_exists('use_ai', $options) ? (bool) $options['use_ai'] : true);

        $bpOptions = array_filter([
            'duration_seconds' => $options['duration_seconds'] ?? null,
            'duration_minutes' => $options['duration_minutes'] ?? null,
            'visual_beat_seconds' => $visualBeat,
            'vo_beat_seconds' => $options['vo_beat_seconds'] ?? null,
            'beat_seconds' => $visualBeat,
            'prefer_stock' => array_key_exists('prefer_stock', $options) ? (bool) $options['prefer_stock'] : null,
            'voice_perspective' => $options['voice_perspective'] ?? null,
            'planned_date' => $options['planned_date'] ?? null,
            'planned_time' => $options['planned_time'] ?? null,
            'force' => true,
            'use_ai' => $useAi,
            'skip_ai' => ! $useAi,
            'analytics_ai_analysis_id' => $brief->analytics_ai_analysis_id,
            'content_brief_id' => $brief->id,
            'angle_finding_id' => $brief->angle_finding_id,
        ], fn ($v) => $v !== null);

        // Reels panjang (5–60 mnt): stub + generate AFTER response → proxy tidak putus.
        if ($isLong) {
            $bp = $this->blueprints->createGeneratingStub(
                $user,
                $topic,
                $format,
                $bpOptions,
                $brief,
                $signal
            );

            GenerateBlueprintJob::dispatchAfterResponse(
                $bp->id,
                $user->id,
                $topic,
                $format,
                $bpOptions + ['background' => true]
            );

            $brief->forceFill([
                'content_blueprint_id' => $bp->id,
                'status' => 'used',
            ])->save();

            return $bp->fresh(['brandKit', 'analyticsAiAnalysis', 'contentBrief']);
        }

        $bpOptions['content_brief'] = $brief;

        if ($signal) {
            $bp = $this->blueprints->generateFromSignal(
                $user,
                $signal,
                $options['planned_date'] ?? null,
                $format,
                true,
                $bpOptions + ['override_topic' => $topic]
            );
        } else {
            $bp = $this->blueprints->generateFromTopic(
                $user,
                $topic,
                null,
                null,
                null,
                $options['planned_date'] ?? null,
                $format,
                true,
                $bpOptions
            );
        }

        // Ensure brief/analysis FKs stick even if generateFromSignal path set signal only.
        $bp->forceFill([
            'content_brief_id' => $brief->id,
            'analytics_ai_analysis_id' => $brief->analytics_ai_analysis_id ?: $bp->analytics_ai_analysis_id,
            'angle_finding_id' => $brief->angle_finding_id,
            'trend_signal_id' => $signal?->id ?: $bp->trend_signal_id,
        ])->save();

        $brief->update([
            'status' => 'used',
            'content_blueprint_id' => $bp->id,
        ]);

        return $bp->fresh(['brandKit', 'analyticsAiAnalysis', 'contentBrief']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ContentBrief>
     */
    public function history(User $user, int $limit = 20)
    {
        return ContentBrief::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(max(1, min(50, $limit)))
            ->get();
    }

    /**
     * @param  array{topic?:string|null,trend_signal_ids?:array<int,int>|null,angle_finding_id?:int|null,angle_indexes?:array<int,int>|null,analytics_ai_analysis_id?:int|null}  $filters
     * @return array{radar:Collection<int,TrendSignal>,angle:?AngleFinding,angle_indexes:array<int,int>,analytics:?AnalyticsAiAnalysis,seed_topic:string,warnings:array<int,string>}
     */
    protected function pickSources(User $user, array $filters): array
    {
        $warnings = [];

        $radarIds = array_values(array_filter(array_map('intval', $filters['trend_signal_ids'] ?? [])));
        if ($radarIds !== []) {
            $radar = TrendSignal::query()->whereIn('id', $radarIds)->orderByDesc('viral_score')->limit(5)->get();
        } else {
            $radar = TrendSignal::query()
                ->where('niche', config('radar.niche', 'psikologi'))
                ->where(function ($q) {
                    $q->where('observed_at', '>=', now()->subDays(14))->orWhereNull('observed_at');
                })
                ->orderByDesc('viral_score')
                ->limit(5)
                ->get();
        }
        if ($radar->isEmpty()) {
            $warnings[] = 'Radar kosong — sync Auto Radar dulu untuk sinyal tren.';
        }

        $angle = null;
        if (! empty($filters['angle_finding_id'])) {
            $angle = AngleFinding::query()
                ->where('user_id', $user->id)
                ->find($filters['angle_finding_id']);
        } else {
            $angle = AngleFinding::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->first();
        }
        if (! $angle) {
            $warnings[] = 'Belum ada Angle Finder — analisa komentar YouTube dulu.';
        }

        $angleIndexes = array_values(array_filter(array_map('intval', $filters['angle_indexes'] ?? [])));
        if ($angle && $angleIndexes === []) {
            $sorted = collect($angle->angles ?? [])
                ->values()
                ->map(fn ($a, $i) => ['i' => $i, 'score' => (int) data_get($a, 'controversy_score', 0)])
                ->sortByDesc('score')
                ->take(3)
                ->pluck('i')
                ->all();
            $angleIndexes = $sorted;
        }

        $analytics = null;
        try {
            if (! empty($filters['analytics_ai_analysis_id'])) {
                $analytics = AnalyticsAiAnalysis::query()
                    ->where('user_id', $user->id)
                    ->find($filters['analytics_ai_analysis_id']);
            } else {
                $analytics = AnalyticsAiAnalysis::query()
                    ->where('user_id', $user->id)
                    ->orderByDesc('id')
                    ->first();
            }
        } catch (\Throwable $e) {
            if ($this->isMissingTable($e)) {
                $warnings[] = 'Tabel analytics_ai_analyses belum ada — jalankan: php artisan migrate --force';
            } else {
                throw $e;
            }
        }
        if (! $analytics && ! collect($warnings)->contains(fn ($w) => str_contains($w, 'analytics_ai_analyses'))) {
            $warnings[] = 'Belum ada Analisa AI Analytics — sync YouTube lalu generate analisa.';
        }

        $seed = trim((string) ($filters['topic'] ?? ''));
        if ($seed === '') {
            $seed = (string) (
                data_get($angle, 'topic')
                ?: $radar->first()?->keyword
                ?: $radar->first()?->title
                ?: data_get($analytics?->title_hooks, '0')
                ?: 'psikologi'
            );
        }

        return [
            'radar' => $radar,
            'angle' => $angle,
            'angle_indexes' => $angleIndexes,
            'analytics' => $analytics,
            'seed_topic' => $seed,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function signalCard(TrendSignal $s): array
    {
        return [
            'id' => $s->id,
            'source' => $s->source,
            'keyword' => $s->keyword,
            'title' => $s->title,
            'snippet' => Str::limit((string) $s->snippet, 160),
            'viral_score' => (float) $s->viral_score,
            'metric_value' => $s->metric_value,
            'url' => $s->url,
            'format' => $s->format,
            'observed_at' => optional($s->observed_at)?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<int, int>  $indexes
     * @return array<string, mixed>
     */
    protected function angleCard(AngleFinding $finding, array $indexes): array
    {
        $all = array_values($finding->angles ?? []);
        $selected = [];
        foreach ($indexes as $i) {
            if (isset($all[$i]) && is_array($all[$i])) {
                $selected[] = $all[$i] + ['_index' => $i];
            }
        }
        if ($selected === [] && $all !== []) {
            $selected = [($all[0] ?? []) + ['_index' => 0]];
        }

        return [
            'id' => $finding->id,
            'topic' => $finding->topic,
            'engine' => $finding->engine,
            'summary' => $finding->summary,
            'pain_points' => array_slice($finding->pain_points ?? [], 0, 8),
            'angles' => $selected,
            'created_at' => optional($finding->created_at)?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{resume:array<string,mixed>}|null
     */
    protected function composeWithGemini(BrandKit $kit, string $topic, array $snapshot): ?array
    {
        $apiKey = trim((string) config('blueprint.gemini_api_key', ''), " \t\n\r\0\x0B\"'");
        if ($apiKey === '') {
            return null;
        }

        $radarBlock = collect($snapshot['radar'] ?? [])
            ->map(fn ($r) => sprintf(
                '- #%d [%s] %s / %s (viral %.1f)',
                $r['id'],
                $r['source'],
                $r['keyword'] ?: '-',
                Str::limit((string) ($r['title'] ?: $r['snippet']), 80),
                (float) ($r['viral_score'] ?? 0)
            ))
            ->implode("\n") ?: '- (kosong)';

        $angle = $snapshot['angle_finding'] ?? null;
        $angleBlock = '- (kosong)';
        if (is_array($angle)) {
            $lines = ['Topic: '.($angle['topic'] ?? '-'), 'Summary: '.Str::limit((string) ($angle['summary'] ?? ''), 240)];
            foreach ($angle['pain_points'] ?? [] as $p) {
                $lines[] = 'Pain: '.$p;
            }
            foreach ($angle['angles'] ?? [] as $a) {
                $lines[] = sprintf(
                    'Angle: %s | hook: %s | %s | emosi %s | skor %s',
                    $a['title'] ?? '-',
                    $a['hook'] ?? '-',
                    $a['angle'] ?? '-',
                    $a['emotion'] ?? '-',
                    $a['controversy_score'] ?? '-'
                );
            }
            $angleBlock = implode("\n", $lines);
        }

        $an = $snapshot['analytics'] ?? null;
        $analyticsBlock = '- (kosong)';
        if (is_array($an)) {
            $play = collect($an['million_view_playbook'] ?? [])->take(4)->map(function ($s) {
                return '- '.($s['title'] ?? '').': '.($s['how'] ?? '').' | contoh: '.($s['example_title'] ?? '');
            })->implode("\n");
            $hooks = collect($an['title_hooks'] ?? [])->take(6)->map(fn ($h) => '- '.$h)->implode("\n");
            $analyticsBlock = "Diagnosis: ".Str::limit((string) ($an['diagnosis'] ?? ''), 400)
                ."\nWorking:\n".collect($an['whats_working'] ?? [])->take(4)->map(fn ($x) => '- '.$x)->implode("\n")
                ."\nBlocking:\n".collect($an['whats_blocking'] ?? [])->take(4)->map(fn ($x) => '- '.$x)->implode("\n")
                ."\nPlaybook:\n{$play}\nHooks:\n{$hooks}\nAvoid:\n"
                .collect($an['avoid'] ?? [])->take(4)->map(fn ($x) => '- '.$x)->implode("\n");
        }

        $prompt = <<<PROMPT
Kamu Content Strategist untuk brand "{$kit->name}" niche {$kit->niche}.
Tugas: GABUNGKAN 3 sumber data jadi 1 RESUME konten yang tajam, siap jadi blueprint viral.

Seed topik user: {$topic}

=== AUTO RADAR (tren pasar) ===
{$radarBlock}

=== ANGLE FINDER (celah dari komentar penonton) ===
{$angleBlock}

=== ANALISA AI ANALYTICS (apa yang jalan di channel sendiri) ===
{$analyticsBlock}

Aturan:
- Resume harus menyatukan sinyal tren + sudut komentar + data performa channel.
- Pilih 1 sudut paling tajam (bukan rata-rata 3 sumber).
- Hook harus konfrontatif / emosional, bukan edukasi generic.
- JANGAN masukkan angka metrik mentah ke hook/judul yang akan dibaca VO.
- Bahasa Indonesia.

Keluarkan JSON SAJA:
{
  "headline": "1 kalimat brief strategis",
  "working_title": "judul kerja pendek tajam",
  "hook": "hook 1 kalimat pembuka",
  "angle": "sudut naskah 1-2 kalimat",
  "why_now": "kenapa sekarang — gabungan 3 sumber",
  "audience_pain": ["max 5"],
  "must_include": ["elemen wajib dari data, max 5"],
  "avoid": ["max 5"],
  "suggested_format": "reels_vo|reels_panjang|reels_text|foto",
  "title_options": ["5-8 opsi judul"],
  "cta": "CTA singkat"
}
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
                                'temperature' => 0.8,
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
                if (! is_array($decoded) || blank($decoded['working_title'] ?? $decoded['hook'] ?? null)) {
                    continue;
                }

                return ['resume' => $this->normalizeResume($decoded, $topic)];
            } catch (\Throwable $e) {
                Log::warning("BriefStudio Gemini {$model}: ".$e->getMessage());
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    protected function composeTemplate(string $topic, array $snapshot): array
    {
        $radarTop = $snapshot['radar'][0] ?? null;
        $angleTop = data_get($snapshot, 'angle_finding.angles.0');
        $hookFromAngle = trim((string) data_get($angleTop, 'hook', ''));
        $titleFromAngle = trim((string) data_get($angleTop, 'title', ''));
        $analyticsHook = (string) data_get($snapshot, 'analytics.title_hooks.0', '');
        $playbookTitle = (string) data_get($snapshot, 'analytics.million_view_playbook.0.example_title', '');

        $working = $titleFromAngle
            ?: $playbookTitle
            ?: ($radarTop['keyword'] ?? $radarTop['title'] ?? $topic);

        $hook = $hookFromAngle
            ?: $analyticsHook
            ?: 'Kalau kamu masih menyalahkan diri sendiri, dengarkan ini.';

        $pains = array_values(array_unique(array_filter(array_merge(
            data_get($snapshot, 'angle_finding.pain_points', []) ?: [],
            data_get($snapshot, 'analytics.whats_blocking', []) ?: []
        ))));

        return $this->normalizeResume([
            'headline' => 'Gabungan tren radar + sudut komentar + sinyal channel untuk 1 konten tajam.',
            'working_title' => $working,
            'hook' => $hook,
            'angle' => (string) (data_get($angleTop, 'angle') ?: data_get($snapshot, 'analytics.diagnosis', 'Sudut emosional dari data channel + tren.')),
            'why_now' => trim(
                ($radarTop ? 'Tren: '.($radarTop['keyword'] ?: $radarTop['title']).'. ' : '')
                .(data_get($snapshot, 'angle_finding.summary') ? 'Komentar: '.Str::limit((string) data_get($snapshot, 'angle_finding.summary'), 120).'. ' : '')
                .(data_get($snapshot, 'analytics.diagnosis') ? 'Channel: '.Str::limit((string) data_get($snapshot, 'analytics.diagnosis'), 120) : '')
            ),
            'audience_pain' => array_slice($pains, 0, 5),
            'must_include' => array_slice(array_filter([
                data_get($snapshot, 'analytics.whats_working.0'),
                data_get($snapshot, 'analytics.million_view_playbook.0.title'),
                data_get($angleTop, 'why_it_works'),
            ]), 0, 5),
            'avoid' => array_slice((array) data_get($snapshot, 'analytics.avoid', []), 0, 5),
            'suggested_format' => 'reels_vo',
            'title_options' => array_values(array_unique(array_filter(array_merge(
                [$working, $hook],
                array_slice((array) data_get($snapshot, 'analytics.title_hooks', []), 0, 6)
            )))),
            'cta' => 'Simpan & bagikan ke yang sedang merasakan ini.',
        ], $topic);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    protected function normalizeResume(array $raw, string $fallbackTopic): array
    {
        $format = (string) ($raw['suggested_format'] ?? 'reels_vo');
        if (! in_array($format, BlueprintGeneratorService::FORMATS, true)) {
            $format = 'reels_vo';
        }

        $list = fn ($key, int $max) => array_values(array_slice(array_filter(array_map(
            fn ($x) => is_string($x) ? trim($x) : '',
            $raw[$key] ?? []
        )), 0, $max));

        return [
            'headline' => trim((string) ($raw['headline'] ?? 'Resume konten gabungan 3 sumber')),
            'working_title' => trim((string) ($raw['working_title'] ?? $fallbackTopic)),
            'hook' => trim((string) ($raw['hook'] ?? '')),
            'angle' => trim((string) ($raw['angle'] ?? '')),
            'why_now' => trim((string) ($raw['why_now'] ?? '')),
            'audience_pain' => $list('audience_pain', 5),
            'must_include' => $list('must_include', 5),
            'avoid' => $list('avoid', 5),
            'suggested_format' => $format,
            'title_options' => $list('title_options', 8),
            'cta' => trim((string) ($raw['cta'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $resume
     * @param  array<string, mixed>  $snapshot
     */
    protected function resumeToMarkdown(array $resume, array $snapshot): string
    {
        $lines = [
            '# Resume Konten (Radar × Angle × Analytics)',
            '',
            '## Headline',
            $resume['headline'] ?? '-',
            '',
            '## Judul kerja',
            $resume['working_title'] ?? '-',
            '',
            '## Hook',
            $resume['hook'] ?? '-',
            '',
            '## Angle',
            $resume['angle'] ?? '-',
            '',
            '## Why now',
            $resume['why_now'] ?? '-',
            '',
            '## Audience pain',
            ...array_map(fn ($x) => '- '.$x, $resume['audience_pain'] ?? []),
            '',
            '## Must include',
            ...array_map(fn ($x) => '- '.$x, $resume['must_include'] ?? []),
            '',
            '## Avoid',
            ...array_map(fn ($x) => '- '.$x, $resume['avoid'] ?? []),
            '',
            '## Format saran: '.($resume['suggested_format'] ?? 'reels_vo'),
            '## CTA: '.($resume['cta'] ?? '-'),
            '',
            '## Title options',
            ...array_map(fn ($x) => '- '.$x, $resume['title_options'] ?? []),
            '',
            '## Sumber',
            '- Radar IDs: '.implode(', ', collect($snapshot['radar'] ?? [])->pluck('id')->all() ?: ['-']),
            '- Angle Finding: '.(data_get($snapshot, 'angle_finding.id') ?: '-'),
            '- Analytics AI: '.(data_get($snapshot, 'analytics.id') ?: '-'),
        ];

        return implode("\n", $lines);
    }
}
