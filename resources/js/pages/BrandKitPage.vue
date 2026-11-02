<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import http from '../api/http';

const loading = ref(true);
const saving = ref(false);
const message = ref('');
const error = ref('');
const geminiConfigured = ref(false);
const geminiModel = ref('');
const geminiHint = ref('');
const testing = ref(false);
const ranking = ref(false);

const kits = ref([]);
const selectedId = ref(null);
const niches = ref([]);
const showNicheBrowse = ref(false);

const form = reactive({
    id: null,
    name: '',
    niche: '',
    region: 'ID',
    character_name: '',
    character_visual: '',
    visual_style: '',
    aspect_ratio: '9:16',
    on_screen_font: 'Anton',
    voice_perspective: 'Aku',
    caption_hashtags: '',
    image_tool: 'Leonardo AI',
    video_tool: 'Image-to-Video',
    is_active: false,
});

const selectedKit = computed(() => kits.value.find((k) => k.id === selectedId.value) || null);

function fillForm(kit) {
    if (!kit) return;
    Object.assign(form, {
        id: kit.id,
        name: kit.name || '',
        niche: kit.niche || '',
        region: kit.region || 'ID',
        character_name: kit.character_name || '',
        character_visual: kit.character_visual || '',
        visual_style: kit.visual_style || '',
        aspect_ratio: kit.aspect_ratio || '9:16',
        on_screen_font: kit.on_screen_font || 'Anton',
        voice_perspective: kit.voice_perspective || 'Aku',
        caption_hashtags: kit.caption_hashtags || '',
        image_tool: kit.image_tool || 'Leonardo AI',
        video_tool: kit.video_tool || 'Image-to-Video',
        is_active: !!kit.is_active,
    });
}

async function loadGeminiMeta() {
    try {
        const { data } = await http.get('/brand-kit');
        geminiConfigured.value = !!data.gemini_configured;
        geminiModel.value = data.gemini_model || '';
        geminiHint.value = data.gemini_key_hint || '';
    } catch {
        /* ignore */
    }
}

async function loadKits() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/brand-kits');
        kits.value = data.data || [];
        const active = data.active || kits.value.find((k) => k.is_active) || kits.value[0];
        selectedId.value = active?.id || null;
        fillForm(active || kits.value[0]);
        await loadNiches();
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat brand kit.';
    } finally {
        loading.value = false;
    }
}

async function loadNiches() {
    try {
        const { data } = await http.get('/niches', { params: { region: form.region || 'ID' } });
        niches.value = data.data || [];
    } catch {
        niches.value = [];
    }
}

function selectKit(kit) {
    selectedId.value = kit.id;
    fillForm(kit);
    showNicheBrowse.value = false;
    loadNiches();
}

async function createKit() {
    saving.value = true;
    message.value = '';
    error.value = '';
    try {
        const name = prompt('Nama profil brand baru?', 'Brand baru') || '';
        if (!name.trim()) return;
        const { data } = await http.post('/brand-kits', {
            name: name.trim(),
            niche: form.niche || 'psikologi',
            region: form.region || 'ID',
        });
        message.value = data.message || 'Profil dibuat.';
        await loadKits();
        if (data.data?.id) selectKit(data.data);
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal membuat profil.';
    } finally {
        saving.value = false;
    }
}

async function activateKit() {
    if (!form.id) return;
    saving.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post(`/brand-kits/${form.id}/activate`);
        message.value = data.message || 'Profil diaktifkan.';
        await loadKits();
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal aktifkan profil.';
    } finally {
        saving.value = false;
    }
}

async function removeKit() {
    if (!form.id || !confirm('Hapus profil Brand Kit ini?')) return;
    saving.value = true;
    error.value = '';
    try {
        const { data } = await http.delete(`/brand-kits/${form.id}`);
        message.value = data.message || 'Dihapus.';
        await loadKits();
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal hapus.';
    } finally {
        saving.value = false;
    }
}

async function save() {
    if (!form.id) return;
    saving.value = true;
    message.value = '';
    error.value = '';
    try {
        const payload = { ...form };
        delete payload.id;
        delete payload.is_active;
        await http.put(`/brand-kits/${form.id}`, payload);
        message.value = 'Brand Kit tersimpan.';
        await loadKits();
        const again = kits.value.find((k) => k.id === form.id);
        if (again) fillForm(again);
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal menyimpan.';
    } finally {
        saving.value = false;
    }
}

async function testGemini() {
    testing.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post('/brand-kit/test-gemini');
        message.value = data.message;
    } catch (e) {
        const tried = e.response?.data?.tried;
        const detail = Array.isArray(tried)
            ? tried.map((t) => `${t.model}: ${t.error}`).join(' | ')
            : '';
        error.value = (e.response?.data?.message || 'Tes Gemini gagal.') + (detail ? ` — ${detail}` : '');
    } finally {
        testing.value = false;
    }
}

function applyNiche(n) {
    form.niche = n.label || n.slug;
    if (n.hashtags) form.caption_hashtags = n.hashtags;
    showNicheBrowse.value = false;
    message.value = `Niche dipilih: ${form.niche}`;
}

async function refreshNicheRank() {
    ranking.value = true;
    error.value = '';
    try {
        const { data } = await http.post('/niches/refresh-rank', { region: form.region || 'ID' });
        niches.value = data.data || [];
        message.value = 'Ranking niche diperbarui (cache 24 jam).';
        showNicheBrowse.value = true;
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal refresh ranking niche.';
    } finally {
        ranking.value = false;
    }
}

onMounted(async () => {
    await loadKits();
    await loadGeminiMeta();
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Brand Kit / Profil</h2>
            <p class="mt-1 text-sm text-slate-500">
                Multi profil brand — pilih yang aktif untuk Brief Studio &amp; Blueprint. Browse niche viral ID/US di bawah.
            </p>
        </div>

        <div
            class="rounded-lg border px-4 py-3 text-sm"
            :class="geminiConfigured ? 'border-teal-100 bg-teal-50 text-teal-900' : 'border-amber-200 bg-amber-50 text-amber-950'"
        >
            <template v-if="geminiConfigured">
                Gemini key terbaca (<code class="rounded bg-white/70 px-1">{{ geminiHint }}</code>) · model
                <code class="rounded bg-white/70 px-1">{{ geminiModel }}</code>.
            </template>
            <template v-else>
                <code class="rounded bg-white/70 px-1">GEMINI_API_KEY</code> belum kebaca.
            </template>
            <div class="mt-3">
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 disabled:opacity-60"
                    :disabled="testing"
                    @click="testGemini"
                >
                    {{ testing ? 'Mengetes...' : 'Tes koneksi Gemini' }}
                </button>
            </div>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat...</div>

        <div v-else class="grid gap-4 lg:grid-cols-[240px_1fr]">
            <aside class="space-y-2 rounded-xl border border-slate-200 bg-white p-3">
                <div class="flex items-center justify-between gap-2 px-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Profil</p>
                    <button type="button" class="text-xs font-semibold text-teal-700" :disabled="saving" @click="createKit">+ Tambah</button>
                </div>
                <button
                    v-for="kit in kits"
                    :key="kit.id"
                    type="button"
                    class="flex w-full flex-col rounded-lg border px-3 py-2 text-left text-sm transition"
                    :class="kit.id === selectedId ? 'border-teal-600 bg-teal-50' : 'border-slate-200 hover:bg-slate-50'"
                    @click="selectKit(kit)"
                >
                    <span class="font-semibold text-slate-900">{{ kit.name }}</span>
                    <span class="text-[11px] text-slate-500">{{ kit.niche }} · {{ kit.region || 'ID' }}</span>
                    <span v-if="kit.is_active" class="mt-1 w-fit rounded bg-teal-700 px-1.5 py-0.5 text-[10px] font-bold uppercase text-white">Aktif</span>
                </button>
            </aside>

            <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-5" @submit.prevent="save">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-slate-900">Edit: {{ form.name || '—' }}</h3>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="!form.is_active"
                            type="button"
                            class="rounded-lg border border-teal-300 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-950"
                            :disabled="saving"
                            @click="activateKit"
                        >
                            Jadikan aktif
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700"
                            :disabled="saving || kits.length <= 1"
                            @click="removeKit"
                        >
                            Hapus
                        </button>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-semibold text-slate-500">Nama brand</span>
                        <input v-model="form.name" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-semibold text-slate-500">Region pasar</span>
                        <select v-model="form.region" class="w-full rounded-lg border border-slate-300 px-3 py-2" @change="loadNiches">
                            <option value="ID">Indonesia (ID)</option>
                            <option value="US">United States (US)</option>
                        </select>
                    </label>
                    <label class="text-sm md:col-span-2">
                        <span class="mb-1 flex items-center justify-between text-xs font-semibold text-slate-500">
                            <span>Niche</span>
                            <button type="button" class="text-teal-700" @click="showNicheBrowse = !showNicheBrowse">
                                {{ showNicheBrowse ? 'Tutup browse' : 'Browse niche viral' }}
                            </button>
                        </span>
                        <input v-model="form.niche" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-semibold text-slate-500">Nama karakter</span>
                        <input v-model="form.character_name" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-semibold text-slate-500">Font CapCut</span>
                        <input v-model="form.on_screen_font" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-semibold text-slate-500">Sudut VO default</span>
                        <select v-model="form.voice_perspective" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option>Aku</option>
                            <option>Saya</option>
                            <option>Gue</option>
                            <option>Kamu</option>
                            <option>Kita</option>
                        </select>
                    </label>
                </div>

                <div v-if="showNicheBrowse" class="rounded-xl border border-teal-100 bg-teal-50/50 p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-teal-950">Niche viral · {{ form.region }}</p>
                            <p class="text-[11px] text-teal-800">Urut skor tinggi → rendah. Detail lengkap juga di Auto Radar.</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-teal-300 bg-white px-3 py-1.5 text-xs font-semibold text-teal-900 disabled:opacity-60"
                            :disabled="ranking"
                            @click="refreshNicheRank"
                        >
                            {{ ranking ? 'Ranking…' : 'Refresh ranking (Gemini)' }}
                        </button>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="(n, i) in niches"
                            :key="n.slug"
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-left hover:border-teal-400"
                            @click="applyNiche(n)"
                        >
                            <span class="flex items-start justify-between gap-2">
                                <span class="text-sm font-semibold text-slate-900">#{{ i + 1 }} {{ n.label }}</span>
                                <span class="shrink-0 rounded-md bg-teal-50 px-2 py-0.5 text-xs font-bold text-teal-800">
                                    {{ Math.round(n.viral_score || 0) }}
                                </span>
                            </span>
                            <span class="block text-[11px] text-slate-500">
                                {{ n.score_source === 'gemini' ? 'Gemini' : n.score_source === 'radar' ? 'Radar heat' : 'Katalog' }}
                                · {{ (n.keywords || []).slice(0, 3).join(', ') }}
                            </span>
                            <span v-if="n.why" class="mt-1 block text-[11px] text-teal-800">{{ n.why }}</span>
                        </button>
                    </div>
                </div>

                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Deskripsi visual karakter (lock)</span>
                    <textarea v-model="form.character_visual" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Visual style (lingkungan saja)</span>
                    <textarea v-model="form.visual_style" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Hashtag caption default</span>
                    <textarea v-model="form.caption_hashtags" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </label>
                <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60" :disabled="saving">
                    {{ saving ? 'Menyimpan...' : 'Simpan Brand Kit' }}
                </button>
            </form>
        </div>
    </div>
</template>
