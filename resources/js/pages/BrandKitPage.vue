<script setup>
import { onMounted, reactive, ref } from 'vue';
import http from '../api/http';

const loading = ref(true);
const saving = ref(false);
const message = ref('');
const error = ref('');
const geminiConfigured = ref(false);

const form = reactive({
    name: '',
    niche: '',
    character_name: '',
    character_visual: '',
    visual_style: '',
    aspect_ratio: '9:16',
    on_screen_font: 'Anton',
    voice_perspective: 'Aku',
    caption_hashtags: '',
    image_tool: 'Leonardo AI',
    video_tool: 'Image-to-Video',
});

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/brand-kit');
        Object.assign(form, data.data);
        geminiConfigured.value = !!data.gemini_configured;
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat brand kit.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    message.value = '';
    error.value = '';
    try {
        await http.put('/brand-kit', { ...form });
        message.value = 'Brand kit tersimpan. Semua blueprint berikutnya pakai karakter/visual ini.';
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal menyimpan.';
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Brand Kit / Karakter</h2>
            <p class="mt-1 text-sm text-slate-500">
                Lock karakter & visual supaya setiap blueprint konsisten (stickman jas + bebek kuning, cinematic 9:16).
            </p>
        </div>

        <div
            class="rounded-lg border px-4 py-3 text-sm"
            :class="geminiConfigured ? 'border-teal-100 bg-teal-50 text-teal-900' : 'border-amber-200 bg-amber-50 text-amber-950'"
        >
            <template v-if="geminiConfigured">
                Gemini API aktif — blueprint lebih “nulis” seperti generate manualmu.
            </template>
            <template v-else>
                Tanpa <code class="rounded bg-white/70 px-1">GEMINI_API_KEY</code> di `.env`, generator pakai template cinematic + karakter lock (tetap siap produksi). Opsional: isi key Gemini biar VO & angle lebih tajam.
            </template>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <form v-if="!loading" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5" @submit.prevent="save">
            <div class="grid gap-3 md:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Nama brand</span>
                    <input v-model="form.name" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">Niche</span>
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
            </div>
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold text-slate-500">Deskripsi visual karakter (lock)</span>
                <textarea v-model="form.character_visual" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block text-sm">
                <span class="mb-1 block text-xs font-semibold text-slate-500">Visual style</span>
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
</template>
