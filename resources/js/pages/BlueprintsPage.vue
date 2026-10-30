<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import http from '../api/http';

const route = useRoute();
const router = useRouter();
const loading = ref(true);
const generating = ref(false);
const items = ref([]);
const message = ref('');
const error = ref('');
const topic = ref('');
const plannedDate = ref('');
const plannedTime = ref('09:00');
const format = ref('reels_vo');
const durationSeconds = ref(15);
const durationMinutes = ref(10);
const textDurationSeconds = ref(8);
const preferStock = ref(true);
const voicePerspective = ref('Aku');
const analyses = ref([]);
const analysisId = ref('');

const selectedAnalysis = computed(() => analyses.value.find((a) => String(a.id) === String(analysisId.value)) || null);

const formatLabel = {
    reels_vo: 'Reels VO',
    reels_panjang: 'Reels Panjang',
    reels_text: 'Reels Text Tempel',
    foto: 'Postingan Foto',
};

const voDurationOptions = [10, 15, 20, 30, 45, 60];
const longDurationOptions = [5, 8, 10, 12, 15];
const textDurationOptions = [5, 6, 8, 10, 12, 15];
const voiceOptions = ['Aku', 'Saya', 'Gue', 'Kamu', 'Kita', 'Dia', 'Orang ketiga'];

const isVo = computed(() => format.value === 'reels_vo');
const isLong = computed(() => format.value === 'reels_panjang');
const isText = computed(() => format.value === 'reels_text');
const isFoto = computed(() => format.value === 'foto');
const needsVoice = computed(() => isVo.value || isLong.value);

function durationLabel(seconds) {
    const s = Number(seconds) || 0;
    if (!s) return '-';
    if (s < 60) return `${s} detik`;
    const m = Math.floor(s / 60);
    const r = s % 60;
    return r ? `${m} menit ${r} detik` : `${m} menit`;
}

function scheduleLabel(bp) {
    if (!bp.planned_date) return 'Belum dijadwalkan';
    const d = String(bp.planned_date).slice(0, 10);
    const t = bp.planned_time ? String(bp.planned_time).slice(0, 5) : null;
    return t ? `Kalender: ${d} ${t}` : `Kalender: ${d}`;
}

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/blueprints');
        items.value = data.data || [];
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat blueprint.';
    } finally {
        loading.value = false;
    }
}

async function loadAnalyses() {
    try {
        const { data } = await http.get('/analytics/ai-analyses', { params: { limit: 20 } });
        analyses.value = data.data || [];
    } catch {
        analyses.value = [];
    }
}

function applyHook(hook) {
    if (hook) topic.value = String(hook);
}

async function generate() {
    if (!topic.value.trim()) {
        error.value = 'Isi tema / ide konten dulu.';
        return;
    }
    generating.value = true;
    message.value = '';
    error.value = '';
    try {
        const payload = {
            topic: topic.value.trim(),
            planned_date: plannedDate.value || null,
            planned_time: plannedDate.value ? (plannedTime.value || null) : null,
            format: format.value,
        };
        if (analysisId.value) {
            payload.analytics_ai_analysis_id = Number(analysisId.value);
        }
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
        const { data } = await http.post('/blueprints/generate', payload);
        message.value = data.duplicate
            ? `${data.message} Judul: "${data.data?.cover_theme || data.data?.title}".`
            : data.message;
        if (data.warning) {
            error.value = data.warning;
        }
        if (!data.duplicate) {
            topic.value = '';
        }
        await load();
        if (data.data?.id) {
            router.push({ name: 'blueprint-detail', params: { id: data.data.id } });
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal generate blueprint.';
    } finally {
        generating.value = false;
    }
}

async function removeItem(id) {
    if (!confirm('Hapus blueprint ini permanen?')) return;
    await http.delete(`/blueprints/${id}`);
    await load();
}

onMounted(async () => {
    if (route.query.topic) {
        topic.value = String(route.query.topic);
    }
    if (route.query.format && ['reels_vo', 'reels_panjang', 'reels_text', 'foto'].includes(String(route.query.format))) {
        format.value = String(route.query.format);
    }
    await loadAnalyses();
    if (route.query.analysis_id) {
        analysisId.value = String(route.query.analysis_id);
        if (!topic.value && selectedAnalysis.value?.title_hooks?.length) {
            topic.value = selectedAnalysis.value.title_hooks[0];
        }
    }
    load();
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Blueprint Konten</h2>
            <p class="mt-1 text-sm text-slate-500">
                Pilih mode: Reels VO, Panjang, Text Tempel, atau Foto. Bisa pakai referensi Analisa AI dari Analytics.
            </p>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="font-semibold text-slate-900">Buat dari tema / ide</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="(label, key) in formatLabel"
                    :key="key"
                    type="button"
                    class="rounded-lg px-3 py-2 text-sm font-semibold"
                    :class="format === key ? 'bg-teal-700 text-white' : 'border border-slate-200 text-slate-700'"
                    @click="format = key"
                >
                    {{ label }}
                </button>
            </div>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label v-if="isVo" class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Durasi (detik)</span>
                    <select v-model.number="durationSeconds" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option v-for="s in voDurationOptions" :key="s" :value="s">{{ s }} detik · {{ s / 5 }} beat</option>
                    </select>
                </label>
                <label v-else-if="isLong" class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Durasi (menit)</span>
                    <select v-model.number="durationMinutes" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option v-for="m in longDurationOptions" :key="m" :value="m">{{ m }} menit · ~{{ (m * 60) / 5 }} beat</option>
                    </select>
                </label>
                <label v-else-if="isText" class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Durasi image→video</span>
                    <select v-model.number="textDurationSeconds" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option v-for="s in textDurationOptions" :key="s" :value="s">{{ s }} detik</option>
                    </select>
                </label>
                <label v-if="needsVoice" class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Sudut pandang VO</span>
                    <select v-model="voicePerspective" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option v-for="v in voiceOptions" :key="v" :value="v">{{ v }}</option>
                    </select>
                </label>
                <label v-if="isLong" class="flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="preferStock" type="checkbox" class="rounded border-slate-300" />
                    Utamakan stock video (AI minimal)
                </label>
            </div>

            <p class="mt-2 text-xs text-slate-500">
                <template v-if="isVo">Stickman jas + bebek · beat 5 dtk · VO / CapCut / Leonardo / motion / thumbnail / caption.</template>
                <template v-else-if="isLong">Golden Hook → Bab → Penutup · beat 5 dtk · bumper tiap bab · visual mayoritas stock/black.</template>
                <template v-else-if="isText">Image (judul 2 baris + footer kecil “lihat caption” / like-share-follow) → i2v → caption ber-solusi + CTA.</template>
                <template v-else>1 prompt image (judul 2 baris + footer kecil lihat caption / like-share-follow) + caption ber-solusi + CTA.</template>
            </p>
            <div class="mt-4 space-y-2">
                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Referensi Analisa AI (opsional)</span>
                    <select v-model="analysisId" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Tanpa analisa — tema bebas</option>
                        <option v-for="a in analyses" :key="a.id" :value="String(a.id)">
                            #{{ a.id }} · {{ a.created_at }} · {{ a.engine }} · {{ (a.title || '').slice(0, 60) }}
                        </option>
                    </select>
                </label>
                <div v-if="selectedAnalysis" class="rounded-lg border border-teal-100 bg-teal-50/60 px-3 py-2 text-xs text-teal-950">
                    <p class="line-clamp-2">{{ selectedAnalysis.diagnosis }}</p>
                    <div v-if="(selectedAnalysis.title_hooks || []).length" class="mt-2 flex flex-wrap gap-1">
                        <button
                            v-for="(h, i) in selectedAnalysis.title_hooks.slice(0, 6)"
                            :key="i"
                            type="button"
                            class="rounded border border-teal-200 bg-white px-2 py-1 text-[11px] text-teal-900 hover:bg-teal-50"
                            @click="applyHook(h)"
                        >
                            Pakai hook: {{ h.slice(0, 42) }}{{ h.length > 42 ? '…' : '' }}
                        </button>
                    </div>
                </div>
            </div>

            <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto_auto_auto]" @submit.prevent="generate">
                <input
                    v-model="topic"
                    required
                    :placeholder="isFoto ? 'Contoh: Jangan jawab pertanyaan jebakan' : isText ? 'Contoh: Orang kaya asli vs pamer gengsi' : isLong ? 'Contoh: Frame Control & Pembalikan Realita' : 'Contoh: Orang kaya asli vs pamer gengsi'"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                />
                <input v-model="plannedDate" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Tanggal kalender" />
                <input v-model="plannedTime" type="time" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Jam post" :disabled="!plannedDate" />
                <button
                    type="submit"
                    class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="generating"
                >
                    {{ generating ? 'Generate...' : 'Generate Blueprint' }}
                </button>
            </form>
        </section>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat...</div>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <article
                v-for="bp in items"
                :key="bp.id"
                class="cursor-pointer rounded-xl border border-slate-200 bg-white p-5 hover:border-teal-300"
                @click="router.push({ name: 'blueprint-detail', params: { id: bp.id } })"
            >
                <div class="flex items-start justify-between gap-3">
                    <h3 class="font-semibold text-slate-900">{{ bp.cover_theme || bp.title }}</h3>
                    <span class="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold uppercase text-slate-700">{{ bp.status }}</span>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span class="rounded bg-teal-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-teal-800">
                        {{ formatLabel[bp.format] || 'Reels VO' }}
                    </span>
                    <span
                        class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                        :class="bp.engine === 'gemini' ? 'bg-violet-100 text-violet-800' : 'bg-amber-100 text-amber-900'"
                    >
                        {{ bp.engine === 'gemini' ? 'AI Gemini' : 'Template' }}
                    </span>
                    <span
                        v-if="bp.analytics_ai_analysis_id"
                        class="rounded bg-amber-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900"
                    >
                        Analisa #{{ bp.analytics_ai_analysis_id }}
                    </span>
                </div>
                <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ bp.story_concept }}</p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ scheduleLabel(bp) }}
                    <template v-if="bp.format === 'foto'"> · prompt image</template>
                    <template v-else-if="bp.format === 'reels_text'"> · i2v {{ durationLabel(bp.duration_seconds) }}</template>
                    <template v-else>
                        · {{ durationLabel(bp.duration_seconds) }}
                        · beat {{ bp.beat_seconds || 5 }} dtk
                        · sudut {{ bp.voice_perspective || 'Aku' }}
                        · {{ (bp.scenes || []).length }} scene
                    </template>
                </p>
                <div class="mt-3 flex gap-2" @click.stop>
                    <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs" @click="router.push({ name: 'blueprint-detail', params: { id: bp.id } })">
                        Buka
                    </button>
                    <button class="rounded-lg px-3 py-1.5 text-xs text-red-600" @click="removeItem(bp.id)">Hapus</button>
                </div>
            </article>
            <p v-if="!items.length" class="text-sm text-slate-500">Belum ada blueprint. Generate dari tema di atas atau dari Auto Radar.</p>
        </div>
    </div>
</template>
