import { defineStore } from 'pinia';
import http, { ensureCsrf } from '../api/http';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        loaded: false,
    }),
    getters: {
        isAuthenticated: (state) => !!state.user,
    },
    actions: {
        async fetchMe() {
            try {
                const { data } = await http.get('/auth/me');
                this.user = data.user;
            } catch {
                this.user = null;
            } finally {
                this.loaded = true;
            }
        },
        async register(payload) {
            await ensureCsrf();
            const { data } = await http.post('/auth/register', payload);
            this.user = data.user;
        },
        async login(payload) {
            await ensureCsrf();
            const { data } = await http.post('/auth/login', payload);
            this.user = data.user;
        },
        async logout() {
            await http.post('/auth/logout');
            this.user = null;
        },
    },
});
