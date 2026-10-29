<script setup>
import { onMounted, reactive, ref } from 'vue';
import http from '../api/http';

const accounts = ref([]);
const posts = ref([]);
const loading = ref(true);
const message = ref('');

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
    } finally {
        loading.value = false;
    }
}

async function addAccount() {
    await http.post('/watchlist/accounts', accountForm);
    Object.assign(accountForm, { name: '', facebook_url: '', niche: 'psikologi', notes: '' });
    message.value = 'Akun watchlist ditambahkan.';
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
    message.value = 'Post kompetitor dicatat.';
    await load();
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Watchlist kompetitor psikologi</h2>
            <p class="mt-1 text-sm text-slate-500">Tambah akun manual dulu. Nanti bisa dilengkapi Competitor Finder.</p>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Tambah akun</h3>
                <form class="mt-4 space-y-3" @submit.prevent="addAccount">
                    <input v-model="accountForm.name" required placeholder="Nama akun" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="accountForm.facebook_url" type="url" placeholder="URL Facebook" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="accountForm.notes" placeholder="Catatan singkat" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Simpan akun</button>
                </form>

                <div class="mt-5 space-y-2">
                    <div v-for="account in accounts" :key="account.id" class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-sm">
                        <div>
                            <p class="font-medium text-slate-900">{{ account.name }}</p>
                            <p class="text-xs text-slate-500">{{ account.posts_count || 0 }} post dicatat</p>
                        </div>
                        <button class="text-red-600 hover:underline" @click="removeAccount(account.id)">Hapus</button>
                    </div>
                    <p v-if="!accounts.length && !loading" class="text-sm text-slate-500">Belum ada akun watchlist.</p>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-5">
                <h3 class="font-semibold text-slate-900">Catat post yang sedang panas</h3>
                <form class="mt-4 space-y-3" @submit.prevent="addPost">
                    <select v-model="postForm.watchlist_account_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option disabled value="">Pilih akun</option>
                        <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                    <input v-model="postForm.title" placeholder="Judul / ringkas" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <textarea v-model="postForm.caption" rows="3" placeholder="Caption / hook" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="postForm.post_url" type="url" placeholder="Link post" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <input v-model="postForm.hook_note" placeholder="Kenapa hook-nya kuat?" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    <div class="grid grid-cols-3 gap-2">
                        <input v-model.number="postForm.likes" type="number" min="0" placeholder="Likes" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <input v-model.number="postForm.comments" type="number" min="0" placeholder="Komen" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <input v-model.number="postForm.shares" type="number" min="0" placeholder="Share" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white" :disabled="!accounts.length">Simpan post</button>
                </form>
            </section>
        </div>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-3 font-semibold text-slate-900">Post watchlist (score)</div>
            <div class="divide-y divide-slate-100">
                <div v-for="post in posts" :key="post.id" class="flex items-start justify-between gap-3 px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ post.title || post.caption || 'Tanpa judul' }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ post.account?.name }} · {{ post.format }} · {{ post.hook_note }}</p>
                    </div>
                    <span class="rounded-md bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-800">{{ post.estimated_score }}</span>
                </div>
                <p v-if="!posts.length && !loading" class="px-4 py-8 text-center text-sm text-slate-500">Belum ada post dicatat.</p>
            </div>
        </section>
    </div>
</template>
