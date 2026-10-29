<script setup>
import { reactive, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const error = ref('');
const loading = ref(false);
const form = reactive({
    email: '',
    password: '',
});

async function submit() {
    error.value = '';
    loading.value = true;
    try {
        await auth.login(form);
        router.push(route.query.redirect || '/');
    } catch (e) {
        error.value = e.response?.data?.message || e.response?.data?.errors?.email?.[0] || 'Login gagal.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-[radial-gradient(circle_at_top,_#ccfbf1,_#f8fafc_50%)] px-4">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">KEA Abbasy</p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-900">Masuk Analyzer</h1>
            <p class="mt-1 text-sm text-slate-500">Analisa konten sebelum produksi.</p>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
                <label class="block text-sm">
                    <span class="mb-1 block text-slate-700">Email</span>
                    <input v-model="form.email" type="email" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-slate-700">Password</span>
                    <input v-model="form.password" type="password" required class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                </label>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
                <button
                    type="submit"
                    class="w-full rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                    :disabled="loading"
                >
                    {{ loading ? 'Memproses...' : 'Masuk' }}
                </button>
            </form>

            <p class="mt-4 text-sm text-slate-500">
                Belum punya akun?
                <RouterLink to="/register" class="font-medium text-teal-700 hover:underline">Daftar</RouterLink>
            </p>
        </div>
    </div>
</template>
