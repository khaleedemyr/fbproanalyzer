<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import http from '../api/http';

const loading = ref(true);
const syncing = ref(false);
const summary = ref(null);
const message = ref('');
const error = ref('');

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/dashboard/summary');
        summary.value = data;
    } finally {
        loading.value = false;
    }
}

async function runAutoRadar() {
    syncing.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post('/radar/sync');
        message.value = `${data.message} · ${data.result.synced} sinyal · ${data.result.ideas} ide baru`;
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
                <h2 class="text-2xl font-semibold text-slate-900">Auto detect konten/topik viral</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Niche psikologi — sistem mendeteksi sinyal yang sedang naik, lalu menyiapkan ide produksi.
                </p>
            </div>
            <button
                type="button"
                class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
                :disabled="syncing"
                @click="runAutoRadar"
            >
                {{ syncing ? 'Mendeteksi...' : 'Jalankan Auto Radar' }}
            </button>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat...</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="item in [
                    { label: 'Sinyal otomatis', value: summary.stats.auto_signals },
                    { label: 'Ide antri', value: summary.stats.ideas },
                    { label: 'Avg skor', value: summary.stats.avg_viral_score },
                    { label: 'Watchlist (opsional)', value: summary.stats.creators },
                ]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ item.label }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ item.value }}</p>
                </div>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">Hasil auto detect</h3>
                    <RouterLink to="/radar" class="text-xs font-medium text-teal-700 hover:underline">Lihat semua</RouterLink>
                </div>
                <div class="mt-4 space-y-3">
                    <a
                        v-for="signal in summary.auto_signals"
                        :key="signal.id"
                        :href="signal.url || '#'"
                        :target="signal.url ? '_blank' : undefined"
                        class="block rounded-lg border border-slate-100 p-3 hover:bg-slate-50"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ signal.title || signal.keyword }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ signal.source }} · {{ signal.keyword }}</p>
                            </div>
                            <span class="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800">{{ signal.viral_score }}</span>
                        </div>
                    </a>
                    <p v-if="!summary.auto_signals?.length" class="text-sm text-slate-500">
                        Belum ada sinyal. Klik <strong>Jalankan Auto Radar</strong>.
                    </p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">Ide siap produksi (otomatis)</h3>
                    <RouterLink to="/ideas" class="text-xs font-medium text-teal-700 hover:underline">Kelola ide</RouterLink>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="idea in summary.idea_queue" :key="idea.id" class="rounded-lg border border-slate-100 p-3">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ idea.title }}</p>
                            <span class="text-xs font-semibold text-teal-700">{{ idea.potential_score }}</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">{{ idea.hook || idea.angle }}</p>
                    </div>
                    <p v-if="!summary.idea_queue.length" class="text-sm text-slate-500">Jalankan Auto Radar agar ide terisi.</p>
                </div>
            </section>
        </template>
    </div>
</template>
