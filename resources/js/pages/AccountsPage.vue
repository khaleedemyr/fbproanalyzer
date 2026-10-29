<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import http, { ensureCsrf } from '../api/http';

const route = useRoute();
const accounts = ref([]);
const loading = ref(true);
const connecting = ref(false);
const message = ref('');
const error = ref('');
const syncingId = ref(null);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/meta/accounts');
        accounts.value = data.data;
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat akun Meta.';
    } finally {
        loading.value = false;
    }
}

async function connectMeta() {
    connecting.value = true;
    error.value = '';
    message.value = 'Menyiapkan koneksi Meta...';

    try {
        await ensureCsrf();
        const { data } = await http.get('/meta/redirect');

        if (!data?.url) {
            throw new Error('URL OAuth Meta kosong. Cek META_APP_ID di .env');
        }

        window.location.assign(data.url);
    } catch (e) {
        connecting.value = false;
        message.value = '';
        error.value = e.response?.data?.message
            || e.message
            || 'Gagal menghubungkan Meta. Cek App ID/Secret dan redirect URI.';
    }
}

async function syncAccount(account) {
    syncingId.value = account.id;
    message.value = '';
    error.value = '';
    try {
        const { data } = await http.post(`/meta/accounts/${account.id}/sync`);
        message.value = `Berhasil sync ${data.synced} postingan dari ${account.name}.`;
        await load();
    } catch (e) {
        error.value = e.response?.data?.message || 'Sync gagal.';
    } finally {
        syncingId.value = null;
    }
}

async function setPrimary(account) {
    await http.patch(`/meta/accounts/${account.id}`, { is_primary: true });
    await load();
}

async function setNiche(account, niche) {
    await http.patch(`/meta/accounts/${account.id}`, { niche });
    await load();
}

onMounted(async () => {
    if (route.query.meta === 'connected') {
        message.value = 'Meta berhasil terhubung. Pilih Page lalu Sync.';
    } else if (route.query.meta) {
        error.value = `Status Meta: ${route.query.meta}`;
    }
    await load();
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Akun Meta</h2>
                <p class="mt-1 text-sm text-slate-500">Hubungkan Personal Pro (Kea Abbasy) + Page, lalu sync Insights.</p>
            </div>
            <button
                type="button"
                class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-wait disabled:opacity-70"
                :disabled="connecting"
                @click="connectMeta"
            >
                {{ connecting ? 'Menghubungkan...' : 'Hubungkan Meta' }}
            </button>
        </div>

        <p v-if="message" class="rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm text-teal-900">{{ message }}</p>
        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat akun...</div>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <article v-for="account in accounts" :key="account.id" class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex items-start gap-3">
                    <img
                        v-if="account.picture_url"
                        :src="account.picture_url"
                        alt=""
                        class="h-12 w-12 rounded-full object-cover"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="truncate font-semibold text-slate-900">{{ account.name }}</h3>
                            <span v-if="account.is_primary" class="rounded bg-teal-100 px-2 py-0.5 text-[11px] font-semibold text-teal-800">PRIMARY</span>
                        </div>
                        <p class="text-xs text-slate-500">{{ account.type }} · {{ account.posts_count || 0 }} posts · {{ account.niche }}</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <select
                        class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
                        :value="account.niche"
                        @change="setNiche(account, $event.target.value)"
                    >
                        <option value="psikologi">psikologi</option>
                        <option value="bisnis">bisnis</option>
                        <option value="fnb">fnb</option>
                        <option value="lainnya">lainnya</option>
                    </select>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50"
                        @click="setPrimary(account)"
                    >
                        Jadikan utama
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="syncingId === account.id"
                        @click="syncAccount(account)"
                    >
                        {{ syncingId === account.id ? 'Sync...' : 'Sync Insights' }}
                    </button>
                </div>
                <p v-if="account.last_synced_at" class="mt-3 text-xs text-slate-500">Terakhir sync: {{ account.last_synced_at }}</p>
            </article>

            <div v-if="!accounts.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-sm text-slate-500">
                Belum ada akun. Klik <strong>Hubungkan Meta</strong> dan login sebagai Kea Abbasy.
            </div>
        </div>
    </div>
</template>
