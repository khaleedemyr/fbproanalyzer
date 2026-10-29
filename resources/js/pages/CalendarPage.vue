<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import http from '../api/http';

const router = useRouter();
const loading = ref(true);
const items = ref([]);
const error = ref('');
const cursor = ref(new Date());

const monthLabel = computed(() =>
    cursor.value.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' }),
);

const range = computed(() => {
    const y = cursor.value.getFullYear();
    const m = cursor.value.getMonth();
    const from = new Date(y, m, 1);
    const to = new Date(y, m + 1, 0);
    const pad = (d) => String(d).padStart(2, '0');
    return {
        from: `${from.getFullYear()}-${pad(from.getMonth() + 1)}-${pad(from.getDate())}`,
        to: `${to.getFullYear()}-${pad(to.getMonth() + 1)}-${pad(to.getDate())}`,
    };
});

const days = computed(() => {
    const y = cursor.value.getFullYear();
    const m = cursor.value.getMonth();
    const first = new Date(y, m, 1);
    const last = new Date(y, m + 1, 0);
    const startPad = (first.getDay() + 6) % 7; // Monday-first
    const cells = [];
    for (let i = 0; i < startPad; i++) cells.push(null);
    for (let d = 1; d <= last.getDate(); d++) {
        const key = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        cells.push({
            day: d,
            key,
            items: items.value.filter((bp) => String(bp.planned_date || '').slice(0, 10) === key),
        });
    }
    return cells;
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/blueprints', { params: range.value });
        items.value = data.data || [];
    } catch (e) {
        error.value = e.response?.data?.message || 'Gagal memuat kalender.';
    } finally {
        loading.value = false;
    }
}

function prevMonth() {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() - 1, 1);
    load();
}

function nextMonth() {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 1);
    load();
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">Kalender Konten</h2>
                <p class="mt-1 text-sm text-slate-500">Blueprint yang sudah dijadwalkan dari Auto Radar / Ide.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @click="prevMonth">←</button>
                <span class="min-w-[140px] text-center text-sm font-semibold capitalize text-slate-900">{{ monthLabel }}</span>
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" @click="nextMonth">→</button>
            </div>
        </div>

        <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
        <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Memuat kalender...</div>

        <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                <div v-for="d in ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']" :key="d" class="px-2 py-2">{{ d }}</div>
            </div>
            <div class="grid grid-cols-7">
                <div
                    v-for="(cell, idx) in days"
                    :key="idx"
                    class="min-h-[96px] border-b border-r border-slate-100 p-2"
                    :class="cell ? 'bg-white' : 'bg-slate-50/50'"
                >
                    <template v-if="cell">
                        <p class="text-xs font-semibold text-slate-700">{{ cell.day }}</p>
                        <button
                            v-for="bp in cell.items"
                            :key="bp.id"
                            type="button"
                            class="mt-1 block w-full truncate rounded bg-teal-50 px-1.5 py-1 text-left text-[11px] font-medium text-teal-900 hover:bg-teal-100"
                            @click="router.push({ name: 'blueprint-detail', params: { id: bp.id } })"
                        >
                            {{ bp.cover_theme || bp.title }}
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <p v-if="!loading && !items.length" class="text-sm text-slate-500">
            Belum ada jadwal bulan ini. Generate blueprint lalu set tanggal di detail, atau isi tanggal saat generate.
        </p>
    </div>
</template>
