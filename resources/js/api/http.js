import axios from 'axios';

const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

export async function ensureCsrf() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export default http;
