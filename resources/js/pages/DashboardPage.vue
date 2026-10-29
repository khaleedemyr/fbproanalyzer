<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
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
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Viral Niche Radar</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Pantau konten psikologi yang sedang panas di luar — sebelum kamu produksi.
                </p>
            </div>
            <div class="flex gap-2">
                <RouterLink to="/watchlist" class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white">
                    Isi Watchlist
                </RouterLink>
                <RouterLink to="/ideas" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800">
                    Lihat Ide
                </RouterLink>
            </div>
        </div>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat radar...</div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="item in [
                    { label: 'Creator dipantau', value: summary.stats.creators },
                    { label: 'Post viral dicatat', value: summary.stats.viral_posts },
                    { label: 'Ide antri', value: summary.stats.ideas },
                    { label: 'Avg skor viral', value: summary.stats.avg_viral_score },
                ]" :key="item.label" class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ item.label }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ item.value }}</p>
                </div>
            </div>

            <section class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                <p class="font-semibold">Cara pakai (inti)</p>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li v-for="step in summary.next_actions" :key="step">{{ step }}</li>
                </ol>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-semibold text-slate-900">Papan viral niche</h3>
                        <RouterLink to="/posts" class="text-xs font-medium text-teal-700 hover:underline">Lihat semua</RouterLink>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div v-for="post in summary.viral_board" :key="post.id" class="rounded-lg border border-slate-100 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-slate-900">{{ post.title || post.caption || '(tanpa judul)' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ post.account?.name }} · {{ post.format }} ·
                                        {{ post.likes }} like · {{ post.comments }} komen · {{ post.shares }} share
                                    </p>
                                    <p v-if="post.hook_note" class="mt-1 text-xs text-teal-800">Hook: {{ post.hook_note }}</p>
                                </div>
                                <span class="shrink-0 rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800">{{ post.estimated_score }}</span>
                            </div>
                        </div>
                        <p v-if="!summary.viral_board.length" class="text-sm text-slate-500">
                            Belum ada data viral. Buka Watchlist → seed creator → catat post yang lagi naik.
                        </p>
                    </div>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="font-semibold text-slate-900">Pola yang sedang menang</h3>
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

                    <div class="mt-6">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Creator paling panas</p>
                        <ul class="mt-2 space-y-2">
                            <li v-for="creator in summary.top_creators" :key="creator.id" class="flex justify-between text-sm">
                                <span>{{ creator.name }}</span>
                                <span class="text-slate-600">avg {{ Number(creator.avg_score).toFixed(1) }} · max {{ Number(creator.max_score).toFixed(0) }}</span>
                            </li>
                            <li v-if="!summary.top_creators.length" class="text-sm text-slate-500">Belum ada.</li>
                        </ul>
                    </div>
                </section>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">Ide siap produksi (dari pola viral)</h3>
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
                    <p v-if="!summary.idea_queue.length" class="text-sm text-slate-500">Isi watchlist dulu, lalu generate ide.</p>
                </div>
            </section>
        </template>
    </div>
</template>
