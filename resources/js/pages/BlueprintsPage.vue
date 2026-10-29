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
const format = ref('reels_vo');
const durationSeconds = ref(15);
const durationMinutes = ref(10);
const preferStock = ref(true);

const formatLabel = {
    reels_vo: 'Reels VO',
    reels_panjang: 'Reels Panjang',
};

const voDurationOptions = [10, 15, 20, 30, 45, 60];
const longDurationOptions = [5, 8, 10, 12, 15];

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
        const payload = {
            topic: topic.value.trim(),
            planned_date: plannedDate.value || null,
            format: format.value,
        };
        if (format.value === 'reels_panjang') {
            payload.duration_minutes = Number(durationMinutes.value);
            payload.prefer_stock = !!preferStock.value;
        } else {
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

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Blueprint Reels</h2>
            <p class="mt-1 text-sm text-slate-500">
                Durasi dipilih per generate. Beat tetap 5 detik. Video panjang tetap ada bumper; bisa utamakan stock video.
            </p>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h3 class="font-semibold text-slate-900">Buat dari tema / ide</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg px-3 py-2 text-sm font-semibold"
                    :class="format === 'reels_vo' ? 'bg-teal-700 text-white' : 'border border-slate-200 text-slate-700'"
                    @click="format = 'reels_vo'"
                >
                    Reels VO
                </button>
                <button
                    type="button"
                    class="rounded-lg px-3 py-2 text-sm font-semibold"
                    :class="format === 'reels_panjang' ? 'bg-teal-700 text-white' : 'border border-slate-200 text-slate-700'"
                    @click="format = 'reels_panjang'"
                >
                    Reels Panjang / VOD
                </button>
            </div>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label v-if="format === 'reels_vo'" class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Durasi (detik)</span>
                    <select v-model.number="durationSeconds" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option v-for="s in voDurationOptions" :key="s" :value="s">{{ s }} detik · {{ s / 5 }} beat</option>
                    </select>
                </label>
                <label v-else class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Durasi (menit)</span>
                    <select v-model.number="durationMinutes" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option v-for="m in longDurationOptions" :key="m" :value="m">{{ m }} menit · ~{{ (m * 60) / 5 }} beat</option>
                    </select>
                </label>
                <label v-if="format === 'reels_panjang'" class="flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="preferStock" type="checkbox" class="rounded border-slate-300" />
                    Utamakan stock video (AI minimal)
                </label>
            </div>

            <p class="mt-2 text-xs text-slate-500">
                <template v-if="format === 'reels_vo'">Stickman jas + bebek · beat 5 dtk · VO / CapCut / Leonardo / motion / thumbnail / caption.</template>
                <template v-else>Golden Hook → Bab → Penutup · beat 5 dtk · bumper tiap bab · visual mayoritas stock/black.</template>
            </p>
            <form class="mt-4 grid gap-3 md:grid-cols-[1fr_auto_auto]" @submit.prevent="generate">
                <input
                    v-model="topic"
                    required
                    :placeholder="format === 'reels_panjang' ? 'Contoh: Frame Control & Pembalikan Realita' : 'Contoh: Orang kaya asli vs pamer gengsi'"
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
                </div>
                <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ bp.story_concept }}</p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ bp.planned_date ? `Kalender: ${bp.planned_date}` : 'Belum dijadwalkan' }}
                    · {{ bp.duration_seconds ? `${bp.duration_seconds}s` : '-' }}
                    · {{ (bp.scenes || []).length }} beat
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
