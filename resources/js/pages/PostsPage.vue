<script setup>
import { onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import http from '../api/http';

const posts = ref([]);
const loading = ref(true);
const format = ref('');

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/watchlist/posts', {
            params: {
                format: format.value || undefined,
            },
        });
        posts.value = data.data || [];
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(format, load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Papan viral</h2>
                <p class="mt-1 text-sm text-slate-500">Ranking konten niche psikologi yang kamu catat dari creator luar.</p>
            </div>
            <div class="flex gap-2">
                <select v-model="format" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Semua format</option>
                    <option value="reel">reel</option>
                    <option value="carousel">carousel</option>
                    <option value="photo">photo</option>
                    <option value="status">status</option>
                    <option value="video">video</option>
                </select>
                <RouterLink to="/watchlist" class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white">
                    Catat post baru
                </RouterLink>
            </div>
        </div>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat papan viral...</div>

        <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Konten</th>
                        <th class="px-4 py-3">Creator</th>
                        <th class="px-4 py-3">Format</th>
                        <th class="px-4 py-3">Skor</th>
                        <th class="px-4 py-3">Engagement</th>
                        <th class="px-4 py-3">Hook</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="post in posts" :key="post.id" class="border-t border-slate-100 align-top">
                        <td class="px-4 py-3">
                            <p class="max-w-md font-medium text-slate-800">{{ post.title || post.caption || '(tanpa judul)' }}</p>
                            <a
                                v-if="post.post_url"
                                :href="post.post_url"
                                target="_blank"
                                class="mt-1 inline-block text-xs text-teal-700 hover:underline"
                            >Buka post</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ post.account?.name }}</td>
                        <td class="px-4 py-3">{{ post.format }}</td>
                        <td class="px-4 py-3 font-semibold text-teal-700">{{ post.estimated_score }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ post.likes }} like · {{ post.comments }} komen · {{ post.shares }} share
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ post.hook_note || '-' }}</td>
                    </tr>
                    <tr v-if="!posts.length">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                            Belum ada post viral. Catat dari Watchlist.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
