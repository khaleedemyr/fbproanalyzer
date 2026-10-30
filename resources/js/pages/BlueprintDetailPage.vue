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
const plannedTime = ref('09:00');

const scenes = computed(() => bp.value?.scenes || []);
const format = computed(() => bp.value?.format || 'reels_vo');
const isLong = computed(() => format.value === 'reels_panjang');
const isText = computed(() => format.value === 'reels_text');
const isFoto = computed(() => format.value === 'foto');
const isVisual = computed(() => isText.value || isFoto.value);
const primaryScene = computed(() => scenes.value[0] || {});

const formatLabel = computed(() => ({
    reels_vo: 'Reels VO',
    reels_panjang: 'Reels Panjang / VOD',
    reels_text: 'Reels Text Tempel',
    foto: 'Postingan Foto',
})[format.value] || 'Reels VO');

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
    if (!beat) return 'foto';
    return `${beat} detik`;
}

const groupedScenes = computed(() => {
    const groups = [];
    let current = null;
    for (const scene of scenes.value) {
        const section = scene.section || (isLong.value ? 'Blueprint' : isVisual.value ? formatLabel.value : 'REELS VO');
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
        plannedTime.value = data.data.planned_time ? String(data.data.planned_time).slice(0, 5) : '09:00';
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
            planned_time: plannedDate.value ? (plannedTime.value || null) : null,
            status: plannedDate.value ? 'scheduled' : 'ready',
        });
        bp.value = data.data;
        message.value = plannedDate.value
            ? `Masuk kalender: ${plannedDate.value}${plannedTime.value ? ' ' + plannedTime.value : ''}`
            : 'Jadwal dihapus.';
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
        plannedTime.value = '09:00';
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
                        <template v-if="isText">i2v {{ durationLabel }}</template>
                        <template v-else>{{ durationLabel }} · beat {{ beatSeconds }} dtk</template>
                    </span>
                    <span v-if="!isVisual" class="rounded bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-800">
                        Sudut: {{ bp.voice_perspective || 'Aku' }}
                    </span>
                    <span v-if="bp.prefer_stock" class="rounded bg-sky-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-sky-800">
                        Utama stock
                    </span>
                    <span
                        v-if="bp.content_brief_id"
                        class="rounded bg-fuchsia-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-fuchsia-900"
                    >
                        Brief Studio #{{ bp.content_brief_id }}
                    </span>
                    <span
                        v-if="bp.analytics_ai_analysis_id"
                        class="rounded bg-amber-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900"
                    >
                        Ref Analisa AI #{{ bp.analytics_ai_analysis_id }}
                    </span>
                </div>
                <h2 class="mt-1 text-2xl font-semibold text-slate-900">{{ bp?.cover_theme || bp?.title || 'Blueprint' }}</h2>
                <p v-if="bp" class="mt-1 text-sm text-slate-500">{{ bp.story_concept }}</p>
                <p v-if="bp && !isVisual" class="mt-1 text-xs text-slate-500">
                    Naskah: {{ durationLabel || '-' }} total · tiap beat {{ beatSeconds }} detik · sudut pandang “{{ bp.voice_perspective || 'Aku' }}”
                </p>
                <p v-else-if="bp && isText" class="mt-1 text-xs text-slate-500">
                    Judul 2 baris + footer kecil “lihat caption / like·share·follow” · i2v {{ durationLabel }} · caption + CTA
                </p>
                <p v-else-if="bp && isFoto" class="mt-1 text-xs text-slate-500">
                    Judul 2 baris + footer kecil “lihat caption / like·share·follow” · caption ber-solusi + CTA
                </p>
            </div>
            <div v-if="bp" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-[11px] font-semibold uppercase text-slate-500">Tanggal</label>
                    <input v-model="plannedDate" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase text-slate-500">Jam</label>
                    <input v-model="plannedTime" type="time" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" :disabled="!plannedDate" />
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

            <!-- Mode visual: layout sederhana -->
            <template v-if="isVisual">
                <section class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="font-semibold text-slate-900">🎨 Prompt Generate Image</h3>
                    <p class="mt-1 text-xs text-slate-500">
                        Judul “{{ bp.cover_theme }}” + footer kecil “Lihat caption selengkapnya” / like·share·follow — generate langsung, tanpa edit teks.
                    </p>
                    <p class="mt-2 text-xs leading-relaxed text-slate-700">{{ primaryScene.image_prompt || bp.thumbnail_prompt }}</p>
                    <button class="mt-2 text-xs text-teal-700" @click="copyText(primaryScene.image_prompt || bp.thumbnail_prompt)">Copy prompt image</button>
                </section>

                <section v-if="isText" class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="font-semibold text-slate-900">🎬 Prompt Image → Video</h3>
                    <p class="mt-1 text-xs text-slate-500">Durasi {{ durationLabel || '—' }} · jangan biarkan teks di gambar bergeser/hilang.</p>
                    <p class="mt-2 text-xs leading-relaxed text-slate-700">{{ primaryScene.motion_prompt }}</p>
                    <button class="mt-2 text-xs text-teal-700" @click="copyText(primaryScene.motion_prompt)">Copy i2v prompt</button>
                </section>

                <section v-if="isText || bp.caption" class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="font-semibold text-slate-900">📝 Caption siap salin</h3>
                    <pre class="mt-2 whitespace-pre-wrap text-sm text-slate-800">{{ bp.caption || '(Caption kosong)' }}</pre>
                    <button v-if="bp.caption" class="mt-2 text-xs text-teal-700" @click="copyText(bp.caption)">Copy caption</button>
                </section>
            </template>

            <!-- Mode VO / Panjang -->
            <template v-else>
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
        </template>
    </div>
</template>
