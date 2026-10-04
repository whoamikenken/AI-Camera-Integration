import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import '../css/app.css';

console.info('Intelligent AI Camera Hub v1.0.2');

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.mount('#app');
// cb 213900
// version 214100
// cache-buster-214300
