<script setup>
import { onMounted, ref } from 'vue';
import http from '../api/http';

const loading = ref(true);
const syncing = ref(false);
const data = ref(null);
const message = ref('');
const error = ref('');
const warnings = ref([]);

const sourceLabel = {
    youtube: 'YouTube',
    google_trends: 'Google Trends',
    keyword_radar: 'Google',
};

const sourceBadge = {
    youtube: 'bg-red-50 text-red-700',
    google_trends: 'bg-blue-50 text-blue-700',
    keyword_radar: 'bg-sky-50 text-sky-800',
};

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const res = await http.get('/radar');
        data.value = res.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat auto radar.';
    } finally {
        loading.value = false;
    }
}

async function runSync() {
    syncing.value = true;
    message.value = '';
    error.value = '';
    warnings.value = [];
    try {
        const res = await http.post('/radar/sync');
        const sources = res.data.result.sources || {};
        message.value = `${res.data.message} · YT ${sources.youtube || 0} · Google ${sources.google_trends || 0} · Keyword ${sources.keyword_radar || sources.seed || 0} · Ide ${res.data.result.ideas || 0}`;
        warnings.value = res.data.result.warnings || [];
        await load();
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal menjalankan auto radar.';
    } finally {
        syncing.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Auto Viral Radar</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Deteksi otomatis topik/konten yang sedang naik. Sumber dipisah: YouTube vs Google.
                </p>
            </div>
            <button
                type="button"
                class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                :disabled="syncing"
                @click="runSync"
            >
                {{ syncing ? 'Mendeteksi...' : 'Jalankan Auto Radar' }}
            </button>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <div v-for="warn in warnings" :key="warn" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            {{ warn }}
        </div>

        <div v-if="data && !data.youtube_configured" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            YouTube API key belum terbaca. Pastikan di `.env` server ada `YOUTUBE_API_KEY=...` lalu `php artisan config:clear`.
        </div>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat sinyal...</div>

        <template v-else-if="data">
            <div class="grid gap-4 sm:grid-cols-3">
                <div v-for="row in data.by_source" :key="row.source" class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ sourceLabel[row.source] || row.source }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ row.total }}</p>
                    <p class="text-xs text-slate-500">avg skor {{ Number(row.avg_score || 0).toFixed(1) }}</p>
                </div>
            </div>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">Sinyal viral terdeteksi</div>
                <div class="divide-y divide-slate-100">
                    <a
                        v-for="signal in data.signals"
                        :key="signal.id"
                        :href="signal.url || '#'"
                        :target="signal.url ? '_blank' : undefined"
                        class="flex items-start justify-between gap-3 px-4 py-3 hover:bg-slate-50"
                    >
                        <div>
                            <div class="mb-1 flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded px-2 py-0.5 text-[11px] font-semibold"
                                    :class="sourceBadge[signal.source] || 'bg-slate-100 text-slate-700'"
                                >
                                    {{ sourceLabel[signal.source] || signal.source }}
                                </span>
                                <span class="text-[11px] text-slate-500">{{ signal.format }}</span>
                            </div>
                            <p class="text-sm font-medium text-slate-900">{{ signal.title || signal.keyword }}</p>
                            <p class="mt-1 text-xs text-slate-500">
                                keyword: {{ signal.keyword }}
                                <span v-if="signal.metric_value"> · metric {{ signal.metric_value }}</span>
                            </p>
                            <p v-if="signal.snippet" class="mt-1 line-clamp-2 text-xs text-slate-600">{{ signal.snippet }}</p>
                        </div>
                        <span class="shrink-0 rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800">{{ signal.viral_score }}</span>
                    </a>
                    <p v-if="!data.signals.length" class="px-4 py-8 text-center text-sm text-slate-500">
                        Belum ada sinyal. Klik <strong>Jalankan Auto Radar</strong>.
                    </p>
                </div>
            </section>
        </template>
    </div>
</template>
