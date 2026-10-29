<script setup>
import { onMounted, reactive, ref } from 'vue';
import http from '../api/http';

const accounts = ref([]);
const posts = ref([]);
const loading = ref(true);
const seeding = ref(false);
const message = ref('');
const error = ref('');

const accountForm = reactive({
    name: '',
    facebook_url: '',
    niche: 'psikologi',
    notes: '',
});

const postForm = reactive({
    watchlist_account_id: '',
    title: '',
    caption: '',
    post_url: '',
    format: 'reel',
    likes: 0,
    comments: 0,
    shares: 0,
    hook_note: '',
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const [a, p] = await Promise.all([
            http.get('/watchlist/accounts'),
            http.get('/watchlist/posts'),
        ]);
        accounts.value = a.data.data;
        posts.value = p.data.data || [];
        if (!postForm.watchlist_account_id && accounts.value[0]) {
            postForm.watchlist_account_id = accounts.value[0].id;
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat watchlist.';
    } finally {
        loading.value = false;
    }
}

async function seedPsychology() {
    seeding.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post('/watchlist/seed-psychology');
        message.value = data.message;
        accounts.value = data.data;
        if (!postForm.watchlist_account_id && accounts.value[0]) {
            postForm.watchlist_account_id = accounts.value[0].id;
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal seed creator.';
    } finally {
        seeding.value = false;
    }
}

async function addAccount() {
    await http.post('/watchlist/accounts', accountForm);
    Object.assign(accountForm, { name: '', facebook_url: '', niche: 'psikologi', notes: '' });
    message.value = 'Creator ditambahkan ke radar.';
    await load();
}

async function removeAccount(id) {
    await http.delete(`/watchlist/accounts/${id}`);
    await load();
}

async function addPost() {
    await http.post('/watchlist/posts', postForm);
    Object.assign(postForm, {
        title: '',
        caption: '',
        post_url: '',
        format: 'reel',
        likes: 0,
        comments: 0,
        shares: 0,
        hook_note: '',
    });
    message.value = 'Post viral dicatat & di-skor.';
    await load();
}

async function removePost(id) {
    await http.delete(`/watchlist/posts/${id}`);
    await load();
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Watchlist niche psikologi</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Ini inti tools: pantau creator luar, catat yang lagi viral, baru produksi.
                </p>
            </div>
            <button
                type="button"
                class="rounded-lg border border-teal-200 bg-teal-50 px-4 py-2.5 text-sm font-semibold text-teal-900 disabled:opacity-60"
                :disabled="seeding"
                @click="seedPsychology"
            >
                {{ seeding ? 'Menyiapkan...' : 'Seed 8 slot creator psikologi' }}
            </button>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">1) Tambah creator yang dipantau</h3>
                <p class="mt-1 text-xs text-slate-500">Isi nama akun nyata yang sering muncul di FYP psikologi kamu.</p>
                <form class="mt-4 space-y-3" @submit.prevent="addAccount">
                    <input v-model="accountForm.name" required placeholder="Nama creator / page" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="accountForm.facebook_url" type="url" placeholder="URL Facebook (opsional)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="accountForm.notes" placeholder="Kenapa dipantau?" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Simpan creator</button>
                </form>

                <div class="mt-5 space-y-2">
                    <div v-for="account in accounts" :key="account.id" class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm">
                        <div>
                            <p class="font-medium text-slate-900">{{ account.name }}</p>
                            <p class="text-xs text-slate-500">{{ account.posts_count || 0 }} post · {{ account.notes || account.niche }}</p>
                        </div>
                        <button type="button" class="text-red-600 hover:underline" @click="removeAccount(account.id)">Hapus</button>
                    </div>
                    <p v-if="!accounts.length && !loading" class="text-sm text-slate-500">Belum ada creator. Klik seed atau tambah manual.</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">2) Catat post yang sedang panas</h3>
                <p class="mt-1 text-xs text-slate-500">Ambil angka kasar dari post FB (like/komen/share) + tulis kenapa hook-nya kuat.</p>
                <form class="mt-4 space-y-3" @submit.prevent="addPost">
                    <select v-model="postForm.watchlist_account_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option disabled value="">Pilih creator</option>
                        <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                    <input v-model="postForm.title" placeholder="Judul / ringkas angle" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <textarea v-model="postForm.caption" rows="3" placeholder="Caption / hook (opsional)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="postForm.post_url" type="url" placeholder="Link post" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="postForm.hook_note" placeholder="Kenapa ini terasa viral?" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <select v-model="postForm.format" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="reel">reel</option>
                        <option value="carousel">carousel</option>
                        <option value="photo">photo</option>
                        <option value="status">status</option>
                        <option value="video">video</option>
                    </select>
                    <div class="grid grid-cols-3 gap-2">
                        <input v-model.number="postForm.likes" type="number" min="0" placeholder="Likes" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <input v-model.number="postForm.comments" type="number" min="0" placeholder="Komen" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <input v-model.number="postForm.shares" type="number" min="0" placeholder="Share" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white" :disabled="!accounts.length">
                        Simpan ke papan viral
                    </button>
                </form>
            </section>
        </div>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">Post tercatat (skor tertinggi di atas)</div>
            <div class="divide-y divide-slate-100">
                <div v-for="post in posts" :key="post.id" class="flex items-start justify-between gap-3 px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ post.title || post.caption || 'Tanpa judul' }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ post.account?.name }} · {{ post.format }} · {{ post.hook_note }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800">{{ post.estimated_score }}</span>
                        <button type="button" class="text-xs text-red-600" @click="removePost(post.id)">Hapus</button>
                    </div>
                </div>
                <p v-if="!posts.length && !loading" class="px-4 py-8 text-center text-sm text-slate-500">Belum ada post dicatat.</p>
            </div>
        </section>
    </div>
</template>
