<script setup>
import { computed, onMounted, ref } from 'vue';
import http from '../api/http';

const loading = ref(true);
const syncing = ref(false);
const analyzing = ref(false);
const connectingYt = ref(false);
const message = ref('');
const error = ref('');
const warnings = ref([]);
const data = ref(null);
const ai = ref(null);
const ytUrl = ref('');
const ytTag = ref('');
const addingYt = ref(false);

const summary = computed(() => data.value?.summary || {});
const formulas = computed(() => data.value?.winning_formulas || []);
const trend = computed(() => data.value?.daily_trend || []);
const movers = computed(() => data.value?.movers || []);
const youtube = computed(() => data.value?.youtube || []);
const ytOauth = computed(() => data.value?.youtube_oauth || null);
const maxTrendViews = computed(() => Math.max(1, ...trend.value.map((r) => Number(r.views || 0))));

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const res = await http.get('/analytics', { params: { days: 30 } });
        data.value = res.data.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat analytics.';
    } finally {
        loading.value = false;
    }
}

async function connectYoutube() {
    connectingYt.value = true;
    error.value = '';
    try {
        const { data: res } = await http.get('/youtube/redirect');
        if (res.url) {
            window.location.href = res.url;
            return;
        }
        error.value = 'URL OAuth YouTube kosong.';
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal mulai OAuth YouTube.';
    } finally {
        connectingYt.value = false;
    }
}

async function disconnectYoutube() {
    if (!confirm('Putuskan koneksi YouTube Analytics?')) return;
    await http.delete('/youtube/disconnect');
    message.value = 'YouTube OAuth diputus.';
    await load();
}

async function syncAll() {
    syncing.value = true;
    message.value = '';
    error.value = '';
    warnings.value = [];
    try {
        const res = await http.post('/analytics/sync', { days: 30 }, { timeout: 180000 });
        if (res.data.data) data.value = res.data.data;
        const r = res.data.result || {};
        message.value = `${res.data.message} · YT ${r.youtube_synced || 0} video · snapshot ${r.snapshots || 0}`;
        warnings.value = r.warnings || [];
        if ((r.warnings || []).length && (r.youtube_synced || 0) === 0) {
            error.value = r.warnings[0];
        }
    } catch (e) {
        const body = e.response?.data || {};
        error.value = body.message || e.message || 'Gagal sync analytics.';
        warnings.value = body.result?.warnings || [];
        if (body.data) data.value = body.data;
    } finally {
        syncing.value = false;
    }
}

async function addYoutube() {
    if (!ytUrl.value.trim()) return;
    addingYt.value = true;
    error.value = '';
    try {
        await http.post('/analytics/youtube', {
            url: ytUrl.value.trim(),
            formula_tag: ytTag.value.trim() || null,
        });
        ytUrl.value = '';
        ytTag.value = '';
        message.value = 'Video YouTube ditambahkan.';
        await load();
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal tambah video YouTube.';
    } finally {
        addingYt.value = false;
    }
}

async function removeYt(id) {
    if (!confirm('Hapus video dari tracker?')) return;
    await http.delete(`/analytics/youtube/${id}`);
    await load();
}

async function runAiAnalyze() {
    analyzing.value = true;
    error.value = '';
    message.value = '';
    try {
        const res = await http.post('/analytics/ai-analyze', {}, { timeout: 120000 });
        ai.value = res.data.data;
        message.value = res.data.message || 'Analisa AI siap.';
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal analisa AI.';
    } finally {
        analyzing.value = false;
    }
}

function formulaLabel(key) {
    if (!key) return '-';
    const [format, topic] = String(key).split('|');
    return `${(format || 'post').toUpperCase()} × ${topic || 'umum'}`;
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const yt = params.get('youtube');
    if (yt === 'connected') message.value = 'YouTube Analytics terhubung.';
    if (yt === 'error' || yt === 'token_failed' || yt === 'invalid_state') {
        error.value = params.get('msg') || `OAuth YouTube gagal (${yt}).`;
    }
    if (yt) {
        window.history.replaceState({}, '', window.location.pathname);
    }
    load();
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Analytics Tracker</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Tarik performa harian YouTube (views, retention, engagement) dari channel yang terhubung — deteksi formula yang lagi naik.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 disabled:opacity-60"
                    :disabled="analyzing || !youtube.length"
                    @click="runAiAnalyze"
                >
                    {{ analyzing ? 'AI menganalisa...' : 'Analisa AI → jutaan views' }}
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="syncing"
                    @click="syncAll"
                >
                    {{ syncing ? 'Sync...' : 'Sync performa sekarang' }}
                </button>
            </div>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <div v-for="w in warnings" :key="w" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ w }}</div>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat analytics...</div>

        <template v-else-if="data">
            <p class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">{{ data.insight }}</p>

            <section v-if="ai" class="space-y-4 rounded-xl border border-teal-200 bg-gradient-to-br from-teal-50/80 to-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="font-semibold text-slate-900">Analisa AI — jalan ke jutaan views</h3>
                        <p class="mt-1 text-xs text-slate-500">Engine: {{ ai.engine === 'gemini' ? 'Gemini' : 'template fallback' }}</p>
                    </div>
                    <button type="button" class="text-xs text-slate-500 underline" @click="runAiAnalyze" :disabled="analyzing">
                        Generate ulang
                    </button>
                </div>

                <p class="text-sm leading-relaxed text-slate-800">{{ ai.diagnosis }}</p>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Yang sudah jalan</p>
                        <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-slate-700">
                            <li v-for="(w, i) in ai.whats_working || []" :key="'w'+i">{{ w }}</li>
                        </ul>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-800">Yang menghambat</p>
                        <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-slate-700">
                            <li v-for="(w, i) in ai.whats_blocking || []" :key="'b'+i">{{ w }}</li>
                        </ul>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Playbook prioritas</p>
                    <ol class="mt-3 space-y-3">
                        <li
                            v-for="step in ai.million_view_playbook || []"
                            :key="step.priority + step.title"
                            class="rounded-lg border border-slate-200 bg-white p-3"
                        >
                            <p class="text-sm font-semibold text-slate-900">
                                <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-teal-700 text-[11px] text-white">{{ step.priority }}</span>
                                {{ step.title }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">{{ step.why }}</p>
                            <p class="mt-2 text-sm text-slate-700">{{ step.how }}</p>
                            <p v-if="step.example_title" class="mt-2 rounded bg-slate-50 px-2 py-1 text-xs text-slate-800">
                                Contoh judul: <strong>{{ step.example_title }}</strong>
                            </p>
                            <p v-if="step.expected_lift" class="mt-1 text-[11px] text-teal-800">Impact: {{ step.expected_lift }}</p>
                        </li>
                    </ol>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">7 hari ke depan</p>
                        <ol class="mt-2 list-decimal space-y-1 pl-4 text-sm text-slate-700">
                            <li v-for="(d, i) in ai.next_7_days || []" :key="'d'+i">{{ d }}</li>
                        </ol>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Hindari</p>
                        <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-slate-700">
                            <li v-for="(a, i) in ai.avoid || []" :key="'a'+i">{{ a }}</li>
                        </ul>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Hook / judul siap uji</p>
                    <ul class="mt-2 space-y-1">
                        <li
                            v-for="(h, i) in ai.title_hooks || []"
                            :key="'h'+i"
                            class="rounded-lg border border-slate-100 bg-white px-3 py-2 text-sm text-slate-800"
                        >
                            {{ h }}
                        </li>
                    </ul>
                </div>
            </section>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Total views</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ Number(summary.total_views || 0).toLocaleString('id-ID') }}</p>
                    <p class="text-xs text-slate-500">Δ hari ini {{ Number(summary.delta_views_today || 0).toLocaleString('id-ID') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Video tracked</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ Number(summary.youtube_tracked || 0).toLocaleString('id-ID') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Avg retention</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ summary.avg_retention || 0 }}%</p>
                    <p class="text-xs text-slate-500">dari YouTube Analytics (OAuth)</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Avg viral score</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ summary.avg_viral_score || 0 }}</p>
                    <p class="text-xs text-slate-500">{{ summary.youtube_tracked || 0 }} video YouTube</p>
                </div>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Formula yang disukai algoritma bulan ini</h3>
                <p class="mt-1 text-xs text-slate-500">Dikelompokkan dari format × topik. Bandingkan skor vs bulan lalu.</p>
                <div v-if="!formulas.length" class="mt-4 text-sm text-slate-500">Belum ada data formula. Hubungkan YouTube lalu sync.</div>
                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="border-b border-slate-100 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-2 py-2">Formula</th>
                                <th class="px-2 py-2">Posts</th>
                                <th class="px-2 py-2">Viral</th>
                                <th class="px-2 py-2">Δ vs bln lalu</th>
                                <th class="px-2 py-2">Views</th>
                                <th class="px-2 py-2">Share rate</th>
                                <th class="px-2 py-2">Retention</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="f in formulas" :key="f.formula_key">
                                <td class="px-2 py-2 font-medium text-slate-900">{{ formulaLabel(f.formula_key) }}</td>
                                <td class="px-2 py-2">{{ f.posts }}</td>
                                <td class="px-2 py-2 font-semibold text-teal-700">{{ f.avg_viral_score }}</td>
                                <td class="px-2 py-2">
                                    <span
                                        class="rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase"
                                        :class="f.trend === 'naik' ? 'bg-emerald-50 text-emerald-800' : f.trend === 'turun' ? 'bg-rose-50 text-rose-800' : 'bg-slate-100 text-slate-700'"
                                    >
                                        {{ f.trend }} {{ f.score_delta > 0 ? '+' : '' }}{{ f.score_delta }}
                                    </span>
                                </td>
                                <td class="px-2 py-2">{{ Number(f.total_views || 0).toLocaleString('id-ID') }}</td>
                                <td class="px-2 py-2">{{ f.share_rate }}%</td>
                                <td class="px-2 py-2">{{ f.avg_retention }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Tren views harian</h3>
                <div v-if="!trend.length" class="mt-4 text-sm text-slate-500">Belum ada snapshot. Klik sync — lalu sync lagi besok untuk lihat naik/turun.</div>
                <div v-else class="mt-4 flex items-end gap-1 overflow-x-auto pb-2" style="min-height: 140px">
                    <div
                        v-for="row in trend"
                        :key="row.snapshot_date"
                        class="flex w-8 flex-col items-center gap-1"
                        :title="`${row.snapshot_date}: ${row.views} views (Δ ${row.delta_views})`"
                    >
                        <div
                            class="w-full rounded-t bg-teal-600/80"
                            :style="{ height: `${Math.max(4, (Number(row.views || 0) / maxTrendViews) * 110)}px` }"
                        />
                        <span class="text-[9px] text-slate-400">{{ String(row.snapshot_date).slice(5) }}</span>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Movers hari ini (Δ views)</h3>
                <ul class="mt-3 divide-y divide-slate-100">
                    <li v-for="(m, i) in movers" :key="i" class="flex items-start justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <a
                                v-if="m.permalink"
                                :href="m.permalink"
                                target="_blank"
                                class="truncate text-sm font-medium text-slate-900 hover:underline"
                            >{{ m.title || m.external_id }}</a>
                            <p v-else class="truncate text-sm font-medium text-slate-900">{{ m.title || m.external_id }}</p>
                            <p class="text-xs text-slate-500">
                                {{ formulaLabel(m.formula_key) }}
                                <span v-if="m.retention_rate"> · ret {{ m.retention_rate }}%</span>
                            </p>
                        </div>
                        <span
                            class="shrink-0 text-sm font-semibold"
                            :class="m.delta_views >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                        >
                            {{ m.delta_views >= 0 ? '+' : '' }}{{ Number(m.delta_views).toLocaleString('id-ID') }}
                        </span>
                    </li>
                    <li v-if="!movers.length" class="py-3 text-sm text-slate-500">Belum ada perbandingan harian. Sync 2 hari berturut-turut.</li>
                </ul>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900">YouTube Analytics (OAuth)</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Hubungkan channel sendiri untuk retention (averageViewPercentage) + avg watch duration.
                            <span v-if="!data.youtube_oauth_configured" class="text-amber-700"> Isi YOUTUBE_CLIENT_ID / SECRET di .env dulu.</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="!ytOauth"
                            type="button"
                            class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            :disabled="connectingYt || !data.youtube_oauth_configured"
                            @click="connectYoutube"
                        >
                            {{ connectingYt ? 'Menghubungkan...' : 'Hubungkan YouTube' }}
                        </button>
                        <button
                            v-else
                            type="button"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                            @click="disconnectYoutube"
                        >
                            Putuskan
                        </button>
                    </div>
                </div>
                <div v-if="ytOauth" class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    Terhubung: <strong>{{ ytOauth.channel_title || ytOauth.channel_id }}</strong>
                    <span v-if="ytOauth.email" class="text-slate-500"> · {{ ytOauth.email }}</span>
                    <span v-if="ytOauth.last_synced_at" class="block text-xs text-slate-500">Last sync {{ ytOauth.last_synced_at }}</span>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Track video YouTube</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Manual track (Data API) atau otomatis dari OAuth Analytics. Retention muncul setelah OAuth sync.
                    <span v-if="!data.youtube_configured" class="text-amber-700"> YOUTUBE_API_KEY belum terbaca.</span>
                </p>
                <form class="mt-3 flex flex-wrap gap-2" @submit.prevent="addYoutube">
                    <input v-model="ytUrl" required placeholder="URL / ID YouTube" class="min-w-[220px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="ytTag" placeholder="Formula tag (opsional)" class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold disabled:opacity-60" :disabled="addingYt">
                        {{ addingYt ? '...' : 'Tambah' }}
                    </button>
                </form>
                <ul class="mt-4 divide-y divide-slate-100">
                    <li v-for="v in youtube" :key="v.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <a :href="v.url" target="_blank" class="text-sm font-medium text-slate-900 hover:underline">{{ v.title || v.video_id }}</a>
                            <p class="text-xs text-slate-500">
                                views {{ Number(v.views).toLocaleString('id-ID') }} · likes {{ v.likes }}
                                <span v-if="v.retention_rate"> · ret {{ v.retention_rate }}%</span>
                                <span v-if="v.avg_watch_seconds"> · avg watch {{ v.avg_watch_seconds }}s</span>
                                · {{ v.formula_tag || '-' }}
                            </p>
                        </div>
                        <button type="button" class="text-xs text-red-600" @click="removeYt(v.id)">Hapus</button>
                    </li>
                    <li v-if="!youtube.length" class="py-3 text-sm text-slate-500">Belum ada video YouTube di-track.</li>
                </ul>
            </section>
        </template>
    </div>
</template>
