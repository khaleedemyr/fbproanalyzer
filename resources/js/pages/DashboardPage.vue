<script setup>
import { onMounted, ref } from 'vue';
import http from '../api/http';

const loading = ref(true);
const summary = ref(null);

onMounted(async () => {
    try {
        const { data } = await http.get('/dashboard/summary');
        summary.value = data;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Dashboard niche psikologi</h2>
            <p class="mt-1 text-sm text-slate-500">Lihat pola yang sedang menang sebelum produksi konten baru.</p>
        </div>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat ringkasan...</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div v-for="item in [
                    { label: 'Akun Meta', value: summary.stats.accounts },
                    { label: 'Post tersinkron', value: summary.stats.posts },
                    { label: 'Watchlist', value: summary.stats.watchlist },
                    { label: 'Ide antri', value: summary.stats.ideas },
                    { label: 'Avg viral score', value: summary.stats.avg_viral_score },
                ]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ item.label }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ item.value }}</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="font-semibold text-slate-900">Top posts (score)</h3>
                    <div class="mt-4 space-y-3">
                        <div v-for="post in summary.top_posts" :key="post.id" class="rounded-lg border border-slate-100 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm text-slate-800">{{ post.message || '(tanpa caption)' }}</p>
                                <span class="shrink-0 rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800">{{ post.viral_score }}</span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">
                                {{ post.meta_account?.name }} · {{ post.format }} · ER {{ post.engagement_rate }}%
                            </p>
                        </div>
                        <p v-if="!summary.top_posts.length" class="text-sm text-slate-500">Belum ada data. Hubungkan Meta lalu sync Page.</p>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="font-semibold text-slate-900">Format & topik menang</h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Format</p>
                            <ul class="mt-2 space-y-2">
                                <li v-for="row in summary.format_stats" :key="row.format" class="flex justify-between text-sm">
                                    <span>{{ row.format }}</span>
                                    <span class="font-medium">{{ Number(row.avg_score).toFixed(1) }}</span>
                                </li>
                                <li v-if="!summary.format_stats.length" class="text-sm text-slate-500">Belum ada.</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Topik</p>
                            <ul class="mt-2 space-y-2">
                                <li v-for="row in summary.topic_stats" :key="row.topic_cluster" class="flex justify-between text-sm">
                                    <span>{{ row.topic_cluster }}</span>
                                    <span class="font-medium">{{ Number(row.avg_score).toFixed(1) }}</span>
                                </li>
                                <li v-if="!summary.topic_stats.length" class="text-sm text-slate-500">Belum terdeteksi.</li>
                            </ul>
                        </div>
                    </div>
                </section>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Antrian ide siap produksi</h3>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="idea in summary.idea_queue" :key="idea.id" class="rounded-lg border border-slate-100 p-3">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-medium text-slate-900">{{ idea.title }}</p>
                            <span class="text-xs font-semibold text-teal-700">{{ idea.potential_score }}</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">{{ idea.hook || idea.angle }}</p>
                    </div>
                    <p v-if="!summary.idea_queue.length" class="text-sm text-slate-500">Belum ada ide. Buka menu Ide Konten lalu generate.</p>
                </div>
            </section>
        </template>
    </div>
</template>
