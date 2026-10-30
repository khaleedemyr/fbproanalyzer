<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import http from '../api/http';

const router = useRouter();
const loading = ref(true);
const composing = ref(false);
const generating = ref(false);
const message = ref('');
const error = ref('');
const sources = ref(null);
const history = ref([]);
const brief = ref(null);
const topic = ref('');
const editTitle = ref('');
const editHook = ref('');
const editAngle = ref('');
const format = ref('reels_vo');
const voicePerspective = ref('Aku');
const durationSeconds = ref(15);
const durationMinutes = ref(10);
const textDurationSeconds = ref(8);
const visualBeatSeconds = ref(10);
const voBeatSeconds = ref(5);
const preferStock = ref(false);

const formatLabel = {
    reels_vo: 'Reels VO',
    reels_panjang: 'Reels Panjang',
    reels_text: 'Reels Text Tempel',
    foto: 'Postingan Foto',
};

const voDurationOptions = [10, 15, 20, 30, 45, 60];
const longDurationOptions = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60];
const visualBeatOptions = [5, 10, 15, 20, 30];
const voBeatOptions = [3, 5, 8, 10, 15];
const textDurationOptions = [5, 6, 8, 10, 12, 15];

function longBeatEstimate(secondsPerBeat) {
    const sec = Number(durationMinutes.value) * 60;
    const beat = Number(secondsPerBeat) || 1;
    return Math.max(1, Math.round(sec / beat));
}

const isVo = computed(() => format.value === 'reels_vo');
const isLong = computed(() => format.value === 'reels_panjang');
const isText = computed(() => format.value === 'reels_text');
const needsVoice = computed(() => isVo.value || isLong.value);

const resume = computed(() => brief.value?.resume || {});
const warnings = computed(() => sources.value?.warnings || brief.value?.inputs_snapshot?.warnings || []);
const available = computed(() => sources.value?.available || {});
const selectedTheme = ref('');

const themeSuggestions = computed(() => {
    const fromApi = sources.value?.theme_suggestions || [];
    const fromResume = (resume.value?.title_options || []).map((t) => ({
        label: String(t).slice(0, 72),
        source: 'Resume',
        value: String(t),
    }));
    const seen = new Set();
    const out = [];
    for (const item of [...fromApi, ...fromResume]) {
        const key = String(item.value || '').toLowerCase().trim();
        if (!key || seen.has(key)) continue;
        seen.add(key);
        out.push(item);
    }
    return out.slice(0, 20);
});

function apiError(e, fallback) {
    if (e?.code === 'ECONNABORTED' || String(e?.message || '').toLowerCase().includes('timeout')) {
        return 'Timeout di browser/hosting — generate video panjang pakai mode cepat (jangan centang AI), durasi 10–15 mnt dulu.';
    }
    const status = e?.response?.status;
    if (status === 502 || status === 504 || status === 524) {
        return `Server/proxy putus (HTTP ${status}) sebelum selesai. Biasanya Gemini terlalu lama — uncheck “Pakai AI Gemini”, generate ulang.`;
    }
    if (!e?.response) {
        return 'Koneksi putus tanpa respons server (sering timeout Hostinger). Coba mode cepat tanpa AI, durasi lebih pendek.';
    }
    const data = e.response.data;
    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }
    if (typeof data?.message === 'string' && data.message.trim()) {
        return data.message;
    }
    if (typeof data === 'string' && data.trim()) {
        return `HTTP ${status}: ${data.slice(0, 180)}`;
    }
    return `${fallback}${status ? ` (HTTP ${status})` : ''}`;
}

function syncEditFromBrief(row) {
    const r = row?.resume || {};
    editTitle.value = String(r.working_title || row?.title || '');
    editHook.value = String(r.hook || '');
    editAngle.value = String(r.angle || '');
    if (r.suggested_format) format.value = r.suggested_format;
    // Jangan overwrite seed topik otomatis — biar user bisa pilih tema saran.
}

function applyTitleOption(opt) {
    if (opt) editTitle.value = String(opt);
}

function pickTheme(item) {
    const value = String(item?.value || item || '').trim();
    if (!value) return;
    selectedTheme.value = value;
    topic.value = value;
    editTitle.value = value.slice(0, 120);
}

async function composeWithTheme(item) {
    pickTheme(item);
    await compose();
}

async function loadSources() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/brief-studio/sources', {
            params: topic.value.trim() ? { topic: topic.value.trim() } : {},
        });
        sources.value = data.data;
    } catch (e) {
        error.value = apiError(e, 'Gagal memuat sumber Brief Studio.');
    } finally {
        loading.value = false;
    }
}

async function loadHistory() {
    try {
        const { data } = await http.get('/brief-studio', { params: { limit: 15 } });
        history.value = data.data || [];
        if (!brief.value && history.value.length) {
            brief.value = history.value[0];
            syncEditFromBrief(brief.value);
        }
    } catch {
        /* ignore */
    }
}

async function compose() {
    if (!topic.value.trim()) {
        error.value = 'Pilih tema saran atau ketik topik dulu, baru Compose Resume.';
        return;
    }
    composing.value = true;
    message.value = '';
    error.value = '';
    try {
        const payload = { topic: topic.value.trim() };
        if (sources.value?.radar?.length) {
            payload.trend_signal_ids = sources.value.radar.map((r) => r.id);
        }
        if (sources.value?.angle_finding?.id) {
            payload.angle_finding_id = sources.value.angle_finding.id;
            payload.angle_indexes = (sources.value.angle_finding.angles || [])
                .map((a) => a._index)
                .filter((i) => i !== undefined && i !== null);
        }
        if (sources.value?.analytics?.id) {
            payload.analytics_ai_analysis_id = sources.value.analytics.id;
        }
        const { data } = await http.post('/brief-studio/compose', payload, { timeout: 120000 });
        brief.value = data.data;
        syncEditFromBrief(brief.value);
        message.value = data.message || 'Resume baru disusun. Edit judul/hook di bawah kalau mau, lalu Generate Blueprint.';
        await loadHistory();
    } catch (e) {
        error.value = apiError(e, 'Gagal compose resume.');
    } finally {
        composing.value = false;
    }
}

async function makeBlueprint() {
    if (!brief.value?.id) return;
    generating.value = true;
    message.value = '';
    error.value = '';
    try {
        const payload = {
            format: format.value,
            force: true,
        };
        if (editTitle.value.trim()) payload.working_title = editTitle.value.trim();
        if (editHook.value.trim()) payload.hook = editHook.value.trim();
        if (editAngle.value.trim()) payload.angle = editAngle.value.trim();
        if (topic.value.trim()) payload.topic = topic.value.trim();
        if (needsVoice.value) {
            payload.voice_perspective = voicePerspective.value || 'Aku';
        }
        payload.use_ai = true;
        if (isLong.value) {
            payload.duration_minutes = Number(durationMinutes.value);
            payload.visual_beat_seconds = Number(visualBeatSeconds.value);
            payload.vo_beat_seconds = Number(voBeatSeconds.value);
            payload.prefer_stock = !!preferStock.value;
        } else if (isText.value) {
            payload.duration_seconds = Number(textDurationSeconds.value);
        } else if (isVo.value) {
            payload.duration_seconds = Number(durationSeconds.value);
        }

        // Panjang = async: server langsung balas stub, generate jalan setelah response.
        const { data } = await http.post(
            `/brief-studio/${brief.value.id}/blueprint`,
            payload,
            { timeout: 60000 }
        );
        message.value = data.message;
        brief.value = data.brief || brief.value;
        if (data.warning) {
            message.value = `${data.message || ''} ${data.warning}`.trim();
        }
        await loadHistory();
        if (data.data?.id) {
            router.push({ name: 'blueprint-detail', params: { id: data.data.id } });
        }
    } catch (e) {
        error.value = apiError(e, 'Gagal generate blueprint dari resume.');
    } finally {
        generating.value = false;
    }
}

async function removeBrief(id) {
    if (!confirm('Hapus resume ini?')) return;
    await http.delete(`/brief-studio/${id}`);
    if (brief.value?.id === id) brief.value = null;
    await loadHistory();
}

function openBrief(row) {
    brief.value = row;
    syncEditFromBrief(row);
}

onMounted(async () => {
    await loadSources();
    await loadHistory();
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Brief Studio</h2>
                <p class="mt-1 text-sm text-slate-500">
                    1) Isi seed topik → Compose Resume (judul baru). 2) Edit judul/hook di resume. 3) Generate Blueprint.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold disabled:opacity-60"
                    :disabled="loading"
                    @click="loadSources"
                >
                    Refresh sumber
                </button>
                <button
                    type="button"
                    class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="composing || loading"
                    @click="compose"
                >
                    {{ composing ? 'Menyusun resume...' : 'Compose Resume' }}
                </button>
            </div>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <div v-for="w in warnings" :key="w" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ w }}</div>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold text-slate-500">Topik pembahasan (wajib dipilih / diketik sebelum Compose)</span>
                <input
                    v-model="topic"
                    placeholder="Ketik topik ATAU klik tema saran di bawah"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                />
            </label>
            <div class="mt-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tema saran — klik untuk pilih, dobel-aksi Compose</p>
                <div v-if="themeSuggestions.length" class="mt-2 flex flex-wrap gap-2">
                    <button
                        v-for="(item, i) in themeSuggestions"
                        :key="i"
                        type="button"
                        class="max-w-full rounded-lg border px-3 py-1.5 text-left text-xs transition"
                        :class="selectedTheme === item.value || topic === item.value
                            ? 'border-teal-600 bg-teal-700 text-white'
                            : 'border-slate-200 bg-white text-slate-800 hover:border-teal-300 hover:bg-teal-50'"
                        :title="item.source + ': ' + item.value"
                        @click="pickTheme(item)"
                        @dblclick.prevent="composeWithTheme(item)"
                    >
                        <span class="block font-semibold">{{ item.label }}</span>
                        <span class="block opacity-70">{{ item.source }}</span>
                    </button>
                </div>
                <p v-else class="mt-2 text-xs text-slate-500">Belum ada saran — sync Radar / Angle / Analisa AI dulu, lalu Refresh sumber.</p>
            </div>
            <p class="mt-2 text-xs text-slate-500">
                Resume history kamu sering sama karena seed kosong → selalu ambil Angle/Analisa lama. Pilih tema di sini dulu, baru Compose Resume.
                Sumber: Radar {{ available.radar_count || 0 }} · Angle {{ available.angle_count || 0 }} · Analisa AI {{ available.analytics_count || 0 }}
            </p>
        </section>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat sumber...</div>

        <div v-else class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h3 class="font-semibold text-slate-900">Auto Radar</h3>
                <p class="mt-1 text-xs text-slate-500">Top sinyal tren yang dipakai</p>
                <ul class="mt-3 max-h-64 space-y-2 overflow-y-auto text-sm">
                    <li v-for="r in sources?.radar || []" :key="r.id" class="rounded-lg bg-slate-50 px-3 py-2">
                        <p class="font-medium text-slate-900">{{ r.keyword || r.title || ('#' + r.id) }}</p>
                        <p class="text-xs text-slate-500">{{ r.source }} · viral {{ r.viral_score }}</p>
                    </li>
                    <li v-if="!(sources?.radar || []).length" class="text-xs text-slate-500">Kosong — sync Auto Radar dulu.</li>
                </ul>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h3 class="font-semibold text-slate-900">Angle Finder</h3>
                <p class="mt-1 text-xs text-slate-500">
                    {{ sources?.angle_finding ? ('#' + sources.angle_finding.id + ' · ' + sources.angle_finding.topic) : 'Belum ada' }}
                </p>
                <ul class="mt-3 max-h-64 space-y-2 overflow-y-auto text-sm">
                    <li v-for="(a, i) in sources?.angle_finding?.angles || []" :key="i" class="rounded-lg bg-slate-50 px-3 py-2">
                        <p class="font-medium text-slate-900">{{ a.title }}</p>
                        <p class="text-xs text-slate-600">{{ a.hook }}</p>
                        <p class="text-[11px] text-slate-500">{{ a.emotion }} · skor {{ a.controversy_score }}</p>
                    </li>
                    <li v-if="!(sources?.angle_finding?.angles || []).length" class="text-xs text-slate-500">Kosong — jalankan Angle Finder dulu.</li>
                </ul>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4">
                <h3 class="font-semibold text-slate-900">Analisa AI</h3>
                <p class="mt-1 text-xs text-slate-500">
                    {{ sources?.analytics ? ('#' + sources.analytics.id + ' · ' + (sources.analytics.engine || '')) : 'Belum ada' }}
                </p>
                <p v-if="sources?.analytics?.diagnosis" class="mt-3 line-clamp-6 text-sm text-slate-700">
                    {{ sources.analytics.diagnosis }}
                </p>
                <ul v-if="(sources?.analytics?.title_hooks || []).length" class="mt-3 max-h-40 space-y-1 overflow-y-auto text-xs text-slate-600">
                    <li v-for="(h, i) in sources.analytics.title_hooks.slice(0, 5)" :key="i">• {{ h }}</li>
                </ul>
                <p v-if="!sources?.analytics" class="mt-3 text-xs text-slate-500">Kosong — generate Analisa AI di Analytics dulu.</p>
            </section>
        </div>

        <section v-if="brief" class="space-y-4 rounded-xl border border-teal-200 bg-gradient-to-br from-teal-50/70 to-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-slate-900">Resume #{{ brief.id }}</h3>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ brief.engine === 'gemini' ? 'Gemini' : 'template' }} · {{ brief.created_at }} · status {{ brief.status }}
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-500">Format</span>
                        <select v-model="format" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option v-for="(label, key) in formatLabel" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>
                    <label v-if="isVo" class="text-sm">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-500">Durasi (detik)</span>
                        <select v-model.number="durationSeconds" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option v-for="s in voDurationOptions" :key="s" :value="s">{{ s }} dtk · {{ s / 5 }} beat</option>
                        </select>
                    </label>
                    <label v-else-if="isLong" class="text-sm">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-500">Durasi (menit)</span>
                        <select v-model.number="durationMinutes" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option v-for="m in longDurationOptions" :key="m" :value="m">{{ m }} mnt</option>
                        </select>
                    </label>
                    <label v-if="isLong" class="text-sm">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-500">Beat visual</span>
                        <select v-model.number="visualBeatSeconds" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option v-for="s in visualBeatOptions" :key="s" :value="s">{{ s }}s · ~{{ longBeatEstimate(s) }} plate</option>
                        </select>
                    </label>
                    <label v-if="isLong" class="text-sm">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-500">Beat naskah</span>
                        <select v-model.number="voBeatSeconds" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option v-for="s in voBeatOptions" :key="s" :value="s">{{ s }}s · ~{{ longBeatEstimate(s) }} baris</option>
                        </select>
                    </label>
                    <label v-else-if="isText" class="text-sm">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-500">Durasi i2v</span>
                        <select v-model.number="textDurationSeconds" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option v-for="s in textDurationOptions" :key="s" :value="s">{{ s }} detik</option>
                        </select>
                    </label>
                    <label v-if="needsVoice" class="text-sm">
                        <span class="mb-1 block text-[11px] font-semibold text-slate-500">Sudut VO</span>
                        <select v-model="voicePerspective" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                            <option>Aku</option>
                            <option>Saya</option>
                            <option>Gue</option>
                            <option>Kamu</option>
                            <option>Kita</option>
                        </select>
                    </label>
                    <label v-if="isLong" class="flex items-center gap-2 pb-1.5 text-sm text-slate-700">
                        <input v-model="preferStock" type="checkbox" class="rounded border-slate-300" />
                        Campur stock
                    </label>
                    <p v-if="isLong" class="pb-1.5 text-xs font-medium text-teal-800">
                        Reels Panjang = AI Gemini wajib (tanpa fallback template)
                    </p>
                    <button
                        type="button"
                        class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="generating"
                        @click="makeBlueprint"
                    >
                        {{ generating ? 'Memulai…' : (isLong ? 'Generate 5–60 mnt (background)' : 'Generate Blueprint dari Resume') }}
                    </button>
                </div>
            </div>

            <p class="text-sm font-medium text-slate-900">{{ resume.headline }}</p>
            <p class="text-xs text-amber-900 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                Tombol <strong>Generate Blueprint</strong> hanya mengubah durasi/beat — judul tetap dari field di bawah.
                Mau topik beda? Isi seed di atas → <strong>Compose Resume</strong>, atau edit judul/hook di sini.
            </p>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="rounded-lg border border-slate-200 bg-white p-3 block">
                    <span class="text-[11px] font-semibold uppercase text-slate-500">Judul kerja (bisa diedit)</span>
                    <input
                        v-model="editTitle"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-900"
                        placeholder="Judul cover / tema episode"
                    />
                </label>
                <label class="rounded-lg border border-slate-200 bg-white p-3 block">
                    <span class="text-[11px] font-semibold uppercase text-slate-500">Hook (bisa diedit)</span>
                    <textarea
                        v-model="editHook"
                        rows="3"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900"
                        placeholder="Kalimat pembuka"
                    />
                </label>
            </div>
            <label class="rounded-lg border border-slate-200 bg-white p-3 block">
                <span class="text-[11px] font-semibold uppercase text-slate-500">Angle / arah pembahasan (bisa diedit)</span>
                <textarea
                    v-model="editAngle"
                    rows="3"
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800"
                    placeholder="Sudut pembongkaran / thesis video"
                />
                <p v-if="resume.why_now" class="mt-2 text-xs text-slate-500">{{ resume.why_now }}</p>
            </label>

            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <p class="text-xs font-semibold uppercase text-emerald-800">Audience pain</p>
                    <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-slate-700">
                        <li v-for="(p, i) in resume.audience_pain || []" :key="'p'+i">{{ p }}</li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-teal-800">Must include</p>
                    <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-slate-700">
                        <li v-for="(p, i) in resume.must_include || []" :key="'m'+i">{{ p }}</li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-rose-800">Avoid</p>
                    <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-slate-700">
                        <li v-for="(p, i) in resume.avoid || []" :key="'a'+i">{{ p }}</li>
                    </ul>
                </div>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase text-slate-500">Title options — klik untuk pakai sebagai judul kerja</p>
                <ul class="mt-2 space-y-1">
                    <li v-for="(t, i) in resume.title_options || []" :key="'t'+i">
                        <button
                            type="button"
                            class="w-full rounded border border-slate-100 bg-white px-3 py-2 text-left text-sm text-slate-800 hover:border-teal-300 hover:bg-teal-50"
                            @click="applyTitleOption(t)"
                        >
                            {{ t }}
                        </button>
                    </li>
                </ul>
            </div>

            <p v-if="brief.content_blueprint_id" class="text-xs text-teal-800">
                Sudah jadi blueprint
                <button type="button" class="underline" @click="router.push({ name: 'blueprint-detail', params: { id: brief.content_blueprint_id } })">
                    #{{ brief.content_blueprint_id }}
                </button>
            </p>
        </section>

        <section v-if="history.length" class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="font-semibold text-slate-900">History resume</h3>
            <ul class="mt-3 divide-y divide-slate-100">
                <li v-for="row in history" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <button type="button" class="min-w-0 flex-1 text-left" @click="openBrief(row)">
                        <p class="truncate text-sm font-medium" :class="brief?.id === row.id ? 'text-teal-800' : 'text-slate-900'">
                            #{{ row.id }} · {{ row.title || 'Resume' }}
                        </p>
                        <p class="text-xs text-slate-500">{{ row.created_at }} · {{ row.engine }} · {{ row.status }}</p>
                    </button>
                    <button type="button" class="text-xs text-red-600" @click="removeBrief(row.id)">Hapus</button>
                </li>
            </ul>
        </section>
    </div>
</template>
