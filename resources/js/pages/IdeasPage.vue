<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import http from '../api/http';

const router = useRouter();
const ideas = ref([]);
const loading = ref(true);
const generating = ref(false);
const makingId = ref(null);
const message = ref('');
const error = ref('');

const form = reactive({
    title: '',
    hook: '',
    angle: '',
    format: 'reel',
    topic_cluster: 'psikologi',
    potential_score: 70,
});

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/ideas');
        ideas.value = data.data || [];
    } finally {
        loading.value = false;
    }
}

async function createIdea() {
    await http.post('/ideas', form);
    Object.assign(form, {
        title: '',
        hook: '',
        angle: '',
        format: 'reel',
        topic_cluster: 'psikologi',
        potential_score: 70,
    });
    message.value = 'Ide ditambahkan ke antrian.';
    await load();
}

async function generate() {
    generating.value = true;
    try {
        const { data } = await http.post('/ideas/generate');
        message.value = `${data.created} ide digenerate dari pola menang.`;
        await load();
    } finally {
        generating.value = false;
    }
}

async function setStatus(idea, status) {
    await http.patch(`/ideas/${idea.id}`, { status });
    await load();
}

async function removeIdea(id) {
    await http.delete(`/ideas/${id}`);
    await load();
}

async function makeBlueprint(idea, format = 'reels_vo') {
    makingId.value = `${idea.id}:${format}`;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post('/blueprints/generate', {
            content_idea_id: idea.id,
            format,
        });
        message.value = `Blueprint siap: ${data.data.cover_theme || data.data.title}`;
        router.push({ name: 'blueprint-detail', params: { id: data.data.id } });
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal buat blueprint.';
    } finally {
        makingId.value = null;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Ide konten</h2>
                <p class="mt-1 text-sm text-slate-500">Dari pola viral niche — pilih yang skornya tinggi sebelum produksi.</p>
            </div>
            <button
                class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                :disabled="generating"
                @click="generate"
            >
                {{ generating ? 'Generate...' : 'Generate dari pola menang' }}
            </button>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="font-semibold text-slate-900">Tambah ide manual</h3>
            <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="createIdea">
                <input v-model="form.title" required placeholder="Judul ide" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-2" />
                <input v-model="form.hook" placeholder="Hook 3 detik pertama" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-2" />
                <textarea v-model="form.angle" rows="3" placeholder="Angle / sudut pembeda" class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-2" />
                <select v-model="form.format" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="reel">reel</option>
                    <option value="carousel">carousel</option>
                    <option value="photo">photo</option>
                    <option value="status">status</option>
                </select>
                <input v-model.number="form.potential_score" type="number" min="0" max="100" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white md:col-span-2">Simpan ide</button>
            </form>
        </section>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat ide...</div>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <article v-for="idea in ideas" :key="idea.id" class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="font-semibold text-slate-900">{{ idea.title }}</h3>
                    <span class="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800">{{ idea.potential_score }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ idea.hook || idea.angle }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ idea.format }} · {{ idea.topic_cluster || '-' }} · {{ idea.status }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        class="rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800 disabled:opacity-60"
                        :disabled="!!makingId"
                        @click="makeBlueprint(idea, 'reels_vo')"
                    >
                        {{ makingId === `${idea.id}:reels_vo` ? '...' : 'Blueprint VO' }}
                    </button>
                    <button
                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:opacity-60"
                        :disabled="!!makingId"
                        @click="makeBlueprint(idea, 'reels_panjang')"
                    >
                        {{ makingId === `${idea.id}:reels_panjang` ? '...' : 'Blueprint Panjang' }}
                    </button>
                    <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs" @click="setStatus(idea, 'in_progress')">Kerjakan</button>
                    <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs" @click="setStatus(idea, 'published')">Sudah publish</button>
                    <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs" @click="setStatus(idea, 'rejected')">Tolak</button>
                    <button class="rounded-lg px-3 py-1.5 text-xs text-red-600" @click="removeIdea(idea.id)">Hapus</button>
                </div>
            </article>
            <p v-if="!ideas.length" class="text-sm text-slate-500">Belum ada ide. Generate atau tulis manual.</p>
        </div>
    </div>
</template>
