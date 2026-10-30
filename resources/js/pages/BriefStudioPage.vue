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
const format = ref('reels_vo');
const voicePerspective = ref('Aku');
const durationSeconds = ref(15);
const durationMinutes = ref(10);
const textDurationSeconds = ref(8);
const preferStock = ref(true);

const formatLabel = {
    reels_vo: 'Reels VO',
    reels_panjang: 'Reels Panjang',
    reels_text: 'Reels Text Tempel',
    foto: 'Postingan Foto',
};

const voDurationOptions = [10, 15, 20, 30, 45, 60];
const longDurationOptions = [5, 8, 10, 12, 15];
const textDurationOptions = [5, 6, 8, 10, 12, 15];

const isVo = computed(() => format.value === 'reels_vo');
const isLong = computed(() => format.value === 'reels_panjang');
const isText = computed(() => format.value === 'reels_text');
const needsVoice = computed(() => isVo.value || isLong.value);

const resume = computed(() => brief.value?.resume || {});
const warnings = computed(() => sources.value?.warnings || brief.value?.inputs_snapshot?.warnings || []);
const available = computed(() => sources.value?.available || {});

async function loadSources() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/brief-studio/sources', {
            params: topic.value.trim() ? { topic: topic.value.trim() } : {},
        });
        sources.value = data.data;
        if (sources.value?.angle_finding?.topic && !topic.value) {
            topic.value = sources.value.angle_finding.topic;
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat sumber Brief Studio.';
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
            if (brief.value?.resume?.suggested_format) {
                format.value = brief.value.resume.suggested_format;
            }
        }
    } catch {
        /* ignore */
    }
}

async function compose() {
    composing.value = true;
    message.value = '';
    error.value = '';
    try {
        const payload = {};
        if (topic.value.trim()) payload.topic = topic.value.trim();
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
        if (brief.value?.resume?.suggested_format) {
            format.value = brief.value.resume.suggested_format;
        }
        message.value = data.message;
        await loadHistory();
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal compose resume.';
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
        if (needsVoice.value) {
            payload.voice_perspective = voicePerspective.value || 'Aku';
        }
        if (isLong.value) {
            payload.duration_minutes = Number(durationMinutes.value);
            payload.prefer_stock = !!preferStock.value;
        } else if (isText.value) {
            payload.duration_seconds = Number(textDurationSeconds.value);
        } else if (isVo.value) {
            payload.duration_seconds = Number(durationSeconds.value);
        }

        const { data } = await http.post(
            `/brief-studio/${brief.value.id}/blueprint`,
            payload,
            { timeout: 180000 }
        );
        message.value = data.message;
        brief.value = data.brief || brief.value;
        if (data.warning) error.value = data.warning;
        await loadHistory();
        if (data.data?.id) {
            router.push({ name: 'blueprint-detail', params: { id: data.data.id } });
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal generate blueprint dari resume.';
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
    if (row?.resume?.suggested_format) format.value = row.resume.suggested_format;
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
                    Gabungkan Auto Radar + Angle Finder + Analisa AI jadi 1 resume tajam, lalu generate Blueprint yang powerful.
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
                <span class="mb-1 block text-xs font-semibold text-slate-500">Seed topik (opsional)</span>
                <input
                    v-model="topic"
                    placeholder="Contoh: toxic parents / overthinking / gaslighting"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                />
            </label>
            <p class="mt-2 text-xs text-slate-500">
                Sumber tersedia: Radar {{ available.radar_count || 0 }} · Angle {{ available.angle_count || 0 }} · Analisa AI {{ available.analytics_count || 0 }}
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
                            <option v-for="m in longDurationOptions" :key="m" :value="m">{{ m }} mnt · ~{{ (m * 60) / 5 }} beat</option>
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
                        Utamakan stock
                    </label>
                    <button
                        type="button"
                        class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                        :disabled="generating"
                        @click="makeBlueprint"
                    >
                        {{ generating ? 'Generate Blueprint...' : 'Generate Blueprint dari Resume' }}
                    </button>
                </div>
            </div>

            <p class="text-sm font-medium text-slate-900">{{ resume.headline }}</p>
            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <p class="text-[11px] font-semibold uppercase text-slate-500">Judul kerja</p>
                    <p class="mt-1 text-sm text-slate-900">{{ resume.working_title }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <p class="text-[11px] font-semibold uppercase text-slate-500">Hook</p>
                    <p class="mt-1 text-sm text-slate-900">{{ resume.hook }}</p>
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="text-[11px] font-semibold uppercase text-slate-500">Angle</p>
                <p class="mt-1 text-sm text-slate-800">{{ resume.angle }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ resume.why_now }}</p>
            </div>

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
                <p class="text-xs font-semibold uppercase text-slate-500">Title options</p>
                <ul class="mt-2 space-y-1">
                    <li v-for="(t, i) in resume.title_options || []" :key="'t'+i" class="rounded bg-white px-3 py-2 text-sm text-slate-800 border border-slate-100">
                        {{ t }}
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
