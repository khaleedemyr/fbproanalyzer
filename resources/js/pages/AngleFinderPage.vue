<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import http from '../api/http';

const router = useRouter();
const loading = ref(true);
const analyzing = ref(false);
const savingIdx = ref(null);
const topic = ref('toxic relationship');
const message = ref('');
const error = ref('');
const result = ref(null);
const history = ref([]);

async function loadHistory() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/angle-finder');
        history.value = data.data || [];
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat riwayat Angle Finder.';
    } finally {
        loading.value = false;
    }
}

async function analyze() {
    if (!topic.value.trim()) {
        error.value = 'Isi topik dulu.';
        return;
    }
    analyzing.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post('/angle-finder/analyze', { topic: topic.value.trim() });
        result.value = data.data;
        message.value = data.message;
        if (data.warning) error.value = data.warning;
        await loadHistory();
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal analisis komentar.';
    } finally {
        analyzing.value = false;
    }
}

async function openFinding(id) {
    try {
        const { data } = await http.get(`/angle-finder/${id}`);
        result.value = data.data;
        topic.value = data.data.topic || topic.value;
        message.value = 'Riwayat dibuka.';
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal buka riwayat.';
    }
}

async function saveIdea(idx) {
    if (!result.value?.id) return;
    savingIdx.value = idx;
    message.value = '';
    try {
        const { data } = await http.post(`/angle-finder/${result.value.id}/save-idea`, { angle_index: idx });
        message.value = data.message;
        router.push({ name: 'ideas' });
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal simpan ide.';
    } finally {
        savingIdx.value = null;
    }
}

function useInBlueprint(row) {
    const topicText = `${row.title || ''} — ${row.angle || ''}`.trim();
    router.push({
        name: 'blueprints',
        query: {
            topic: topicText.slice(0, 180),
            format: 'reels_vo',
        },
    });
}

async function removeFinding(id) {
    if (!confirm('Hapus hasil Angle Finder ini?')) return;
    await http.delete(`/angle-finder/${id}`);
    if (result.value?.id === id) result.value = null;
    await loadHistory();
}

onMounted(loadHistory);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Angle Finder</h2>
            <p class="mt-1 text-sm text-slate-500">
                Ambil komentar video YouTube populer di niche (API resmi), ringkas keluhan penonton, lalu rekomendasi sudut pandang unik / kontroversial untuk naskah.
            </p>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ error }}</p>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="font-semibold text-slate-900">Cari sudut dari komentar kompetitor</h3>
            <form class="mt-4 flex flex-wrap gap-3" @submit.prevent="analyze">
                <input
                    v-model="topic"
                    required
                    placeholder="Contoh: toxic relationship, gaslighting, frame control"
                    class="min-w-[240px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                />
                <button
                    type="submit"
                    class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="analyzing"
                >
                    {{ analyzing ? 'Mengambil komentar...' : 'Temukan sudut unik' }}
                </button>
            </form>
            <p class="mt-2 text-xs text-slate-500">
                Butuh YOUTUBE_API_KEY (+ GEMINI_API_KEY untuk ringkasan AI). Bukan scraping gelap — pakai YouTube Data API.
            </p>
        </section>

        <template v-if="result">
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 class="font-semibold text-slate-900">Hasil · {{ result.topic }}</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ result.videos_scanned }} video · {{ result.comments_scanned }} komentar ·
                            <span :class="result.engine === 'gemini' ? 'text-violet-700' : 'text-amber-700'">
                                {{ result.engine === 'gemini' ? 'AI Gemini' : 'Template' }}
                            </span>
                        </p>
                    </div>
                </div>
                <p class="mt-3 text-sm text-slate-700">{{ result.summary }}</p>

                <div v-if="result.pain_points?.length" class="mt-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Keluhan penonton</h4>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-800">
                        <li v-for="(p, i) in result.pain_points" :key="i">{{ p }}</li>
                    </ul>
                </div>
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Rekomendasi sudut pandang</h3>
                <article
                    v-for="(row, idx) in result.angles || []"
                    :key="idx"
                    class="rounded-xl border border-slate-200 bg-white p-5"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h4 class="font-semibold text-slate-900">{{ row.title }}</h4>
                        <div class="flex flex-wrap gap-2">
                            <span class="rounded bg-rose-50 px-2 py-0.5 text-[11px] font-semibold uppercase text-rose-800">
                                kontroversi {{ row.controversy_score }}
                            </span>
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-slate-700">
                                {{ row.emotion }}
                            </span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-slate-800">{{ row.angle }}</p>
                    <p v-if="row.hook" class="mt-2 text-sm text-teal-900">
                        <span class="text-[11px] font-semibold uppercase text-slate-500">Hook · </span>“{{ row.hook }}”
                    </p>
                    <p v-if="row.why_it_works" class="mt-2 text-xs text-slate-500">Kenapa beda: {{ row.why_it_works }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800 disabled:opacity-60"
                            :disabled="savingIdx === idx"
                            @click="saveIdea(idx)"
                        >
                            {{ savingIdx === idx ? '...' : 'Simpan ke Ide' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700"
                            @click="useInBlueprint(row)"
                        >
                            Pakai di Blueprint
                        </button>
                    </div>
                </article>
            </section>

            <section v-if="result.source_videos?.length" class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Video sumber</h3>
                <ul class="mt-3 divide-y divide-slate-100">
                    <li v-for="v in result.source_videos" :key="v.video_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <a :href="v.url" target="_blank" class="text-sm font-medium text-slate-900 hover:underline">{{ v.title }}</a>
                            <p class="text-xs text-slate-500">
                                {{ v.channel }}
                                <span v-if="v.views"> · {{ Number(v.views).toLocaleString('id-ID') }} views</span>
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="result.sample_comments?.length" class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Cuplikan komentar</h3>
                <ul class="mt-3 space-y-2">
                    <li
                        v-for="(c, i) in result.sample_comments.slice(0, 12)"
                        :key="i"
                        class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700"
                    >
                        “{{ c.text }}”
                        <span class="mt-1 block text-[10px] text-slate-400">{{ c.video_title }}</span>
                    </li>
                </ul>
            </section>
        </template>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="font-semibold text-slate-900">Riwayat</h3>
            <div v-if="loading" class="mt-3 text-sm text-slate-500">Memuat...</div>
            <ul v-else class="mt-3 divide-y divide-slate-100">
                <li v-for="h in history" :key="h.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <button type="button" class="text-left" @click="openFinding(h.id)">
                        <p class="text-sm font-medium text-slate-900">{{ h.topic }}</p>
                        <p class="text-xs text-slate-500">
                            {{ h.videos_scanned }} video · {{ h.comments_scanned }} komentar · {{ h.engine }}
                        </p>
                    </button>
                    <button type="button" class="text-xs text-red-600" @click="removeFinding(h.id)">Hapus</button>
                </li>
                <li v-if="!history.length" class="py-4 text-sm text-slate-500">Belum ada analisis.</li>
            </ul>
        </section>
    </div>
</template>
