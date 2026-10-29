<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import http from '../api/http';

const router = useRouter();
const loading = ref(true);
const generating = ref(false);
const items = ref([]);
const message = ref('');
const error = ref('');
const topic = ref('');
const plannedDate = ref('');
const engine = ref('');

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

async function generate() {
    if (!topic.value.trim()) {
        error.value = 'Isi tema / ide konten dulu.';
        return;
    }
    generating.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post('/blueprints/generate', {
            topic: topic.value.trim(),
            planned_date: plannedDate.value || null,
        });
        engine.value = data.engine;
        message.value = `${data.message} (engine: ${data.engine})`;
        topic.value = '';
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
    await http.delete(`/blueprints/${id}`);
    await load();
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Blueprint Reels</h2>
            <p class="mt-1 text-sm text-slate-500">
                Generate blueprint cinematic ala Gemini: VO, CapCut, Leonardo, animasi, thumbnail, caption — karakter stickman jas + bebek kuning selalu sama.
            </p>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="font-semibold text-slate-900">Buat dari tema / ide</h3>
            <p class="mt-1 text-xs text-slate-500">Bisa paste judul dari Auto Viral Radar. Atau klik tombol di Radar / Ide Konten.</p>
            <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto_auto]" @submit.prevent="generate">
                <input
                    v-model="topic"
                    required
                    placeholder="Contoh: Orang kaya asli vs pamer gengsi"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                />
                <input v-model="plannedDate" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" title="Masuk kalender" />
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
                <p class="mt-2 line-clamp-2 text-sm text-slate-600">{{ bp.story_concept }}</p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ bp.planned_date ? `Kalender: ${bp.planned_date}` : 'Belum dijadwalkan' }}
                    · {{ (bp.scenes || []).length }} scene
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
