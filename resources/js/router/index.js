import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/LoginPage.vue'),
        meta: { guest: true },
    },
    {
        path: '/register',
        name: 'register',
        component: () => import('../pages/RegisterPage.vue'),
        meta: { guest: true },
    },
    {
        path: '/',
        component: () => import('../layouts/AppLayout.vue'),
        meta: { auth: true },
        children: [
            {
                path: '',
                name: 'dashboard',
                component: () => import('../pages/DashboardPage.vue'),
            },
            {
                path: 'radar',
                name: 'radar',
                component: () => import('../pages/RadarPage.vue'),
            },
            {
                path: 'angle-finder',
                name: 'angle-finder',
                component: () => import('../pages/AngleFinderPage.vue'),
            },
            {
                path: 'analytics',
                name: 'analytics',
                component: () => import('../pages/AnalyticsTrackerPage.vue'),
            },
            {
                path: 'brief-studio',
                name: 'brief-studio',
                component: () => import('../pages/BriefStudioPage.vue'),
            },
            {
                path: 'posts',
                name: 'posts',
                component: () => import('../pages/PostsPage.vue'),
            },
            {
                path: 'accounts',
                name: 'accounts',
                component: () => import('../pages/AccountsPage.vue'),
            },
            {
                path: 'watchlist',
                name: 'watchlist',
                component: () => import('../pages/WatchlistPage.vue'),
            },
            {
                path: 'ideas',
                name: 'ideas',
                component: () => import('../pages/IdeasPage.vue'),
            },
            {
                path: 'blueprints',
                name: 'blueprints',
                component: () => import('../pages/BlueprintsPage.vue'),
            },
            {
                path: 'blueprints/:id',
                name: 'blueprint-detail',
                component: () => import('../pages/BlueprintDetailPage.vue'),
            },
            {
                path: 'calendar',
                name: 'calendar',
                component: () => import('../pages/CalendarPage.vue'),
            },
            {
                path: 'brand-kit',
                name: 'brand-kit',
                component: () => import('../pages/BrandKitPage.vue'),
            },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (!auth.loaded) {
        await auth.fetchMe();
    }

    if (to.meta.auth && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guest && auth.isAuthenticated) {
        return { name: 'dashboard' };
    }

    return true;
});

export default router;
