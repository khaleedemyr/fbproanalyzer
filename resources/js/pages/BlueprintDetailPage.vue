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
const isLong = computed(() => bp.value?.format === 'reels_panjang');
const formatLabel = computed(() => (isLong.value ? 'Reels Panjang / VOD' : 'Reels VO'));
const isAi = computed(() => bp.value?.engine === 'gemini');
const engineLabel = computed(() => (isAi.value ? 'Generate AI (Gemini)' : 'Template Blueprint'));
const beatSeconds = computed(() => bp.value?.beat_seconds || 5);
const durationLabel = computed(() => {
    const s = Number(bp.value?.duration_seconds) || 0;
    if (!s) return '';
    if (s < 60) return `${s} detik`;
    const m = Math.floor(s / 60);
    const r = s % 60;
    return r ? `${m} menit ${r} detik` : `${m} menit`;
});

function sceneDurationLabel(scene) {
    const beat = scene?.beat_seconds || beatSeconds.value;
    return `${beat} detik`;
}

const groupedScenes = computed(() => {
    const groups = [];
    let current = null;
    for (const scene of scenes.value) {
        const section = scene.section || (isLong.value ? 'Blueprint' : 'REELS VO');
        if (!current || current.name !== section) {
            current = { name: section, scenes: [] };
            groups.push(current);
        }
        current.scenes.push(scene);
    }
    return groups;
});

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

async function unschedule() {
    saving.value = true;
    try {
        const { data } = await http.patch(`/blueprints/${bp.value.id}`, { unschedule: true });
        bp.value = data.data;
        plannedDate.value = '';
        message.value = 'Dihapus dari kalender.';
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal lepas kalender.';
    } finally {
        saving.value = false;
    }
}

async function removeItem() {
    if (!confirm('Hapus blueprint ini permanen?')) return;
    await http.delete(`/blueprints/${bp.value.id}`);
    router.push({ name: 'calendar' });
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
                <div v-if="bp" class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="rounded bg-teal-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-teal-800">{{ formatLabel }}</span>
                    <span
                        class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                        :class="isAi ? 'bg-violet-100 text-violet-800' : 'bg-amber-100 text-amber-900'"
                    >
                        {{ engineLabel }}
                    </span>
                    <span v-if="durationLabel" class="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-700">
                        {{ durationLabel }} · beat {{ beatSeconds }} dtk
                    </span>
                    <span class="rounded bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-800">
                        Sudut: {{ bp.voice_perspective || 'Aku' }}
                    </span>
                    <span v-if="bp.prefer_stock" class="rounded bg-sky-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-sky-800">
                        Utama stock
                    </span>
                </div>
                <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ bp?.cover_theme || bp?.title || 'Blueprint' }}</h2>
                <p v-if="bp" class="mt-1 text-sm text-slate-500">{{ bp.story_concept }}</p>
                <p v-if="bp" class="mt-1 text-xs text-slate-500">
                    Naskah: {{ durationLabel || '-' }} total · tiap beat {{ beatSeconds }} detik · sudut pandang “{{ bp.voice_perspective || 'Aku' }}”
                </p>
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
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" :disabled="saving" @click="unschedule">
                    Lepas kalender
                </button>
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @click="copyText(markdown)">Copy semua</button>
                <button type="button" class="rounded-lg px-3 py-2 text-sm text-red-600" @click="removeItem">Hapus</button>
            </div>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat...</div>

        <template v-else-if="bp">
            <section
                v-if="bp.title_options?.length"
                class="rounded-xl border border-slate-200 bg-white p-5"
            >
                <h3 class="font-semibold text-slate-900">Pilihan Judul (Cover / Thumbnail)</h3>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-800">
                    <li v-for="(opt, i) in bp.title_options" :key="i" class="flex items-start justify-between gap-2">
                        <span>{{ opt }}</span>
                        <button class="shrink-0 text-xs text-teal-700" @click="copyText(opt)">Copy</button>
                    </li>
                </ol>
            </section>

            <div v-for="group in groupedScenes" :key="group.name" class="space-y-3">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ group.name }}</h3>
                <section v-for="(scene, idx) in group.scenes" :key="group.name + idx" class="rounded-xl border border-slate-200 bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="font-semibold text-slate-900">
                            {{ scene.timecode || '—' }}
                            <span class="ml-2 text-xs font-normal text-slate-500">({{ sceneDurationLabel(scene) }})</span>
                        </h4>
                        <span
                            v-if="scene.visual_type"
                            class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-600"
                        >
                            {{ scene.visual_type }}
                        </span>
                    </div>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                Naskah VO · sudut {{ bp.voice_perspective || 'Aku' }}
                            </p>
                            <p class="mt-1 text-sm text-slate-800">{{ scene.vo ? `"${scene.vo}"` : '(Kosong / bumper)' }}</p>
                            <button v-if="scene.vo" class="mt-2 text-xs text-teal-700" @click="copyText(scene.vo)">Copy VO</button>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Teks On-Screen</p>
                            <pre class="mt-1 whitespace-pre-wrap text-sm text-slate-800">{{ scene.on_screen }}</pre>
                            <button class="mt-2 text-xs text-teal-700" @click="copyText(scene.on_screen)">Copy teks</button>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                {{ isLong ? 'Visual Level-Dewa' : '🎨 Prompt Gambar' }}
                            </p>
                            <p class="mt-1 text-xs leading-relaxed text-slate-700">{{ scene.image_prompt }}</p>
                            <button class="mt-2 text-xs text-teal-700" @click="copyText(scene.image_prompt)">Copy visual</button>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">🎬 Motion / Keterangan</p>
                            <p class="mt-1 text-xs leading-relaxed text-slate-700">{{ scene.motion_prompt }}</p>
                            <button class="mt-2 text-xs text-teal-700" @click="copyText(scene.motion_prompt)">Copy motion</button>
                        </div>
                    </div>
                </section>
            </div>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">🎨 Prompt Thumbnail</h3>
                <p class="mt-2 text-xs leading-relaxed text-slate-700">{{ bp.thumbnail_prompt }}</p>
                <button class="mt-2 text-xs text-teal-700" @click="copyText(bp.thumbnail_prompt)">Copy thumbnail</button>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">📝 Caption siap salin</h3>
                <pre class="mt-2 whitespace-pre-wrap text-sm text-slate-800">{{ bp.caption || '(Caption kosong — generate ulang)' }}</pre>
                <button v-if="bp.caption" class="mt-2 text-xs text-teal-700" @click="copyText(bp.caption)">Copy caption</button>
            </section>
        </template>
    </div>
</template>
