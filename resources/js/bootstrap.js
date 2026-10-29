import axios from 'axios';
import { installReadOnlyRetry } from './readOnlyRetry';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Writes rejected while a deploy has the site read-only (503 + Retry-After) are sent again once it is over.
installReadOnlyRetry(window.axios);
