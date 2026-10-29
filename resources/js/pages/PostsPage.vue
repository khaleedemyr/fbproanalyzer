<script setup>
import { onMounted, ref, watch } from 'vue';
import http from '../api/http';

const posts = ref([]);
const loading = ref(true);
const days = ref(30);
const format = ref('');

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/posts', {
            params: {
                days: days.value,
                format: format.value || undefined,
                sort: 'viral_score',
            },
        });
        posts.value = data.data || [];
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch([days, format], load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Board performa</h2>
                <p class="mt-1 text-sm text-slate-500">Urut berdasarkan viral score heuristik.</p>
            </div>
            <div class="flex gap-2">
                <select v-model="days" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option :value="7">7 hari</option>
                    <option :value="14">14 hari</option>
                    <option :value="30">30 hari</option>
                </select>
                <select v-model="format" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Semua format</option>
                    <option value="reel">reel</option>
                    <option value="video">video</option>
                    <option value="photo">photo</option>
                    <option value="status">status</option>
                    <option value="post">post</option>
                </select>
            </div>
        </div>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat posts...</div>

        <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Konten</th>
                        <th class="px-4 py-3">Akun</th>
                        <th class="px-4 py-3">Format</th>
                        <th class="px-4 py-3">Score</th>
                        <th class="px-4 py-3">ER</th>
                        <th class="px-4 py-3">Engagement</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="post in posts" :key="post.id" class="border-t border-slate-100 align-top">
                        <td class="px-4 py-3">
                            <p class="max-w-md text-slate-800">{{ post.message || '(tanpa caption)' }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ post.topic_cluster || 'unclustered' }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ post.meta_account?.name }}</td>
                        <td class="px-4 py-3">{{ post.format }}</td>
                        <td class="px-4 py-3 font-semibold text-teal-700">{{ post.viral_score }}</td>
                        <td class="px-4 py-3">{{ post.engagement_rate }}%</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ post.likes }} like · {{ post.comments }} komen · {{ post.shares }} share
                        </td>
                    </tr>
                    <tr v-if="!posts.length">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">Belum ada data performa.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
