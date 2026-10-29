<script setup>
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

const links = [
    { to: '/', label: 'Dashboard', name: 'dashboard' },
    { to: '/posts', label: 'Performa', name: 'posts' },
    { to: '/accounts', label: 'Akun Meta', name: 'accounts' },
    { to: '/watchlist', label: 'Watchlist', name: 'watchlist' },
    { to: '/ideas', label: 'Ide Konten', name: 'ideas' },
];

async function logout() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="min-h-screen bg-[radial-gradient(circle_at_top,_#ecfeff,_#f8fafc_45%)]">
        <header class="border-b border-slate-200/80 bg-white/80 backdrop-blur">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">KEA Abbasy</p>
                    <h1 class="text-lg font-semibold text-slate-900">FB Pro Analyzer</h1>
                </div>
                <div class="flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-medium text-slate-900">{{ auth.user?.name }}</p>
                        <p class="text-xs text-slate-500">{{ auth.user?.email }}</p>
                    </div>
                    <button
                        class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                        @click="logout"
                    >
                        Keluar
                    </button>
                </div>
            </div>
            <nav class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 pb-3 sm:px-6">
                <RouterLink
                    v-for="link in links"
                    :key="link.name"
                    :to="link.to"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-teal-50 hover:text-teal-800"
                    active-class="!bg-teal-700 !text-white hover:!bg-teal-700 hover:!text-white"
                >
                    {{ link.label }}
                </RouterLink>
            </nav>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
            <RouterView />
        </main>
    </div>
</template>
