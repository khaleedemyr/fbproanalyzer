<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';
import http from '../api/http';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const activeKit = ref(null);

const links = [
    { to: '/', label: 'Dashboard', name: 'dashboard' },
    { to: '/radar', label: 'Auto Radar', name: 'radar' },
    { to: '/angle-finder', label: 'Angle Finder', name: 'angle-finder' },
    { to: '/analytics', label: 'Analytics', name: 'analytics' },
    { to: '/brief-studio', label: 'Brief Studio', name: 'brief-studio' },
    { to: '/ideas', label: 'Ide Konten', name: 'ideas' },
    { to: '/blueprints', label: 'Blueprint', name: 'blueprints' },
    { to: '/calendar', label: 'Kalender', name: 'calendar' },
    { to: '/brand-kit', label: 'Brand Kit', name: 'brand-kit' },
    { to: '/watchlist', label: 'Watchlist', name: 'watchlist' },
    { to: '/accounts', label: 'Akun Meta', name: 'accounts' },
];

async function loadActiveKit() {
    try {
        const { data } = await http.get('/brand-kits');
        activeKit.value = data.active || (data.data || []).find((k) => k.is_active) || null;
    } catch {
        activeKit.value = null;
    }
}

async function logout() {
    await auth.logout();
    router.push({ name: 'login' });
}

onMounted(loadActiveKit);

defineExpose({ loadActiveKit });
</script>

<template>
    <div class="min-h-screen bg-[radial-gradient(circle_at_top,_#ecfeff,_#f8fafc_45%)]">
        <header class="border-b border-slate-200/80 bg-white/80 backdrop-blur">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">KEA Abbasy · Psikologi</p>
                    <h1 class="text-lg font-semibold text-slate-900">Viral Niche Radar</h1>
                </div>
                <div class="flex items-center gap-3">
                    <RouterLink
                        v-if="activeKit"
                        to="/brand-kit"
                        class="hidden max-w-[220px] rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-left sm:block"
                        title="Ganti profil di Brand Kit"
                    >
                        <p class="truncate text-xs font-semibold text-teal-950">{{ activeKit.name }}</p>
                        <p class="truncate text-[10px] text-teal-800">{{ activeKit.niche }} · {{ activeKit.region || 'ID' }}</p>
                    </RouterLink>
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-medium text-slate-900">{{ auth.user?.name }}</p>
                        <p class="text-xs text-slate-500">{{ auth.user?.email }}</p>
                    </div>
                    <button
                        type="button"
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
