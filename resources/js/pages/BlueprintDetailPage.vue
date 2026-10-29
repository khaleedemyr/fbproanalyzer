<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import http from '../api/http';

const route = useRoute();
const router = useRouter();
const loading = ref(true);
const saving = ref(false);
const bp = ref(null);
const markdown = ref('');
const message = ref('');
const error = ref('');
const plannedDate = ref('');

const scenes = computed(() => bp.value?.scenes || []);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get(`/blueprints/${route.params.id}`);
        bp.value = data.data;
        markdown.value = data.markdown || '';
        plannedDate.value = data.data.planned_date ? String(data.data.planned_date).slice(0, 10) : '';
    } catch (e) {
        error.value = e.response?.data?.message || 'Blueprint tidak ditemukan.';
    } finally {
        loading.value = false;
    }
}

async function saveSchedule() {
    saving.value = true;
    message.value = '';
    try {
        const { data } = await http.patch(`/blueprints/${bp.value.id}`, {
            planned_date: plannedDate.value || null,
            status: plannedDate.value ? 'scheduled' : 'ready',
        });
        bp.value = data.data;
        message.value = plannedDate.value ? 'Masuk kalender konten.' : 'Jadwal dihapus.';
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal simpan jadwal.';
    } finally {
        saving.value = false;
    }
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text || '');
        message.value = 'Disalin.';
    } catch {
        message.value = 'Gagal copy — select manual.';
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <button type="button" class="text-sm text-teal-700 hover:underline" @click="router.push({ name: 'blueprints' })">← Semua blueprint</button>
                <h2 class="mt-2 text-2xl font-semibold text-slate-900">{{ bp?.cover_theme || bp?.title || 'Blueprint' }}</h2>
                <p v-if="bp" class="mt-1 text-sm text-slate-500">{{ bp.story_concept }}</p>
            </div>
            <div v-if="bp" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-[11px] font-semibold uppercase text-slate-500">Kalender</label>
                    <input v-model="plannedDate" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <button
                    type="button"
                    class="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    :disabled="saving"
                    @click="saveSchedule"
                >
                    Simpan jadwal
                </button>
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @click="copyText(markdown)">Copy semua</button>
            </div>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat...</div>

        <template v-else-if="bp">
            <section v-for="(scene, idx) in scenes" :key="idx" class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">Scene {{ idx + 1 }} · {{ scene.timecode }}</h3>
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Naskah VO</p>
                        <p class="mt-1 text-sm text-slate-800">"{{ scene.vo }}"</p>
                        <button class="mt-2 text-xs text-teal-700" @click="copyText(scene.vo)">Copy VO</button>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Teks On-Screen (CapCut)</p>
                        <pre class="mt-1 whitespace-pre-wrap text-sm text-slate-800">{{ scene.on_screen }}</pre>
                        <button class="mt-2 text-xs text-teal-700" @click="copyText(scene.on_screen)">Copy teks</button>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">🎨 Prompt Gambar</p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-700">{{ scene.image_prompt }}</p>
                        <button class="mt-2 text-xs text-teal-700" @click="copyText(scene.image_prompt)">Copy prompt</button>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">🎬 Prompt Animasi</p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-700">{{ scene.motion_prompt }}</p>
                        <button class="mt-2 text-xs text-teal-700" @click="copyText(scene.motion_prompt)">Copy motion</button>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">🎨 Prompt Thumbnail</h3>
                <p class="mt-2 text-xs leading-relaxed text-slate-700">{{ bp.thumbnail_prompt }}</p>
                <button class="mt-2 text-xs text-teal-700" @click="copyText(bp.thumbnail_prompt)">Copy thumbnail</button>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">📝 Caption siap salin</h3>
                <pre class="mt-2 whitespace-pre-wrap text-sm text-slate-800">{{ bp.caption }}</pre>
                <button class="mt-2 text-xs text-teal-700" @click="copyText(bp.caption)">Copy caption</button>
            </section>
        </template>
    </div>
</template>
