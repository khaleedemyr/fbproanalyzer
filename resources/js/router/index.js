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
