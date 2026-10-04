<template>
  <div class="min-h-screen bg-slate-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8 font-sans antialiased selection:bg-indigo-500 selection:text-white">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <!-- Pinnacle Logo & Branding -->
      <div class="flex justify-center items-center gap-3">
        <img :src="logoLight" alt="Pinnacle Technologies" class="h-10 w-auto" />
      </div>
      <h2 class="mt-4 text-center text-xl font-bold tracking-tight text-slate-900">
        AI Camera Hub &amp; Attendance
      </h2>
      <p class="mt-1 text-center text-xs text-slate-500">
        Enterprise Biometric Access Control &amp; Workforce Portal
      </p>
    </div>

    <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md">
      <div class="bg-white py-8 px-6 shadow-xl border border-slate-200/80 rounded-2xl sm:px-10">
        <!-- Error Banner -->
        <div 
          v-if="authStore.loginError" 
          role="alert" 
          aria-live="assertive" 
          class="mb-5 bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-xs flex items-center justify-between shadow-xs"
        >
          <div class="flex items-center gap-2">
            <span class="text-rose-500 text-sm" aria-hidden="true">⚠️</span>
            <span>{{ authStore.loginError }}</span>
          </div>
          <button 
            @click="authStore.loginError = null" 
            aria-label="Dismiss error" 
            class="text-rose-400 hover:text-rose-600 font-bold ml-2 cursor-pointer p-1 rounded focus:outline-none focus:ring-2 focus:ring-rose-500"
          >&times;</button>
        </div>

        <form class="space-y-5" @submit.prevent="handleSubmit">
          <!-- Email Input -->
          <div>
            <label for="email" class="block text-xs font-semibold text-slate-700">
              Work Email Address
            </label>
            <div class="mt-1 relative">
              <input
                id="email"
                ref="emailInput"
                v-model="form.email"
                type="email"
                autocomplete="email"
                required
                :aria-invalid="authStore.loginError ? 'true' : 'false'"
                placeholder="name@company.com"
                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
              />
            </div>
          </div>

          <!-- Password Input -->
          <div>
            <label for="password" class="block text-xs font-semibold text-slate-700">
              Password
            </label>
            <div class="mt-1 relative">
              <input
                id="password"
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                required
                :aria-invalid="authStore.loginError ? 'true' : 'false'"
                placeholder="••••••••"
                class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 pr-10 text-xs text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 shadow-xs"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                :aria-pressed="showPassword"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500/30 rounded"
                title="Toggle password visibility"
              >
                <span class="text-xs" aria-hidden="true">{{ showPassword ? '👁️' : '🙈' }}</span>
              </button>
            </div>
          </div>

          <!-- Remember Me & Forgot Password -->
          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <input
                id="remember-me"
                v-model="form.remember"
                type="checkbox"
                class="h-3.5 w-3.5 text-indigo-600 focus:ring-indigo-500 border-slate-300 rounded cursor-pointer"
              />
              <label for="remember-me" class="ml-2 block text-xs text-slate-600 cursor-pointer">
                Keep me signed in
              </label>
            </div>

            <div class="text-xs">
              <button
                type="button"
                @click="forgotPasswordAlert"
                class="font-medium text-indigo-600 hover:text-indigo-500 cursor-pointer focus:outline-none focus:underline"
              >
                Forgot password?
              </button>
            </div>
          </div>

          <!-- Submit Button -->
          <div>
            <button
              type="submit"
              :disabled="authStore.loading"
              class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 transition-all cursor-pointer"
            >
              <span v-if="authStore.loading" class="flex items-center gap-2">
                <span class="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                Authenticating...
              </span>
              <span v-else>Sign In to Console</span>
            </button>
          </div>
        </form>

        <!-- Quick-Fill Developer / Demo Switcher -->
        <div class="mt-6 pt-5 border-t border-slate-100">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Quick Fill Roles</span>
            <span class="text-[10px] text-slate-400 font-mono">pw: password</span>
          </div>
          <div class="grid grid-cols-2 gap-2 text-[11px]">
            <button
              type="button"
              @click="prefill('admin@camera.hub', 'password')"
              class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              👑 Super Admin
            </button>
            <button
              type="button"
              @click="prefill('hr@camera.hub', 'password')"
              class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              💼 HR Manager
            </button>
            <button
              type="button"
              @click="prefill('security@camera.hub', 'password')"
              class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              🛡️ Security
            </button>
            <button
              type="button"
              @click="prefill('reception@camera.hub', 'password')"
              class="px-2 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 rounded border border-slate-200 text-left font-medium transition-colors cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              🎫 Receptionist
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import logoLight from '../../images/pinnacle-logo-light.svg';
import { useAuthStore } from '../stores/authStore';
import notify from '../utils/notify';

const authStore = useAuthStore();
const showPassword = ref(false);
const emailInput = ref(null);

const form = ref({
  email: '',
  password: '',
  remember: true,
});

const prefill = (email, password) => {
  form.value.email = email;
  form.value.password = password;
};

const handleSubmit = async () => {
  try {
    await authStore.login({
      email: form.value.email,
      password: form.value.password,
      remember: form.value.remember,
    });
  } catch (err) {
    emailInput.value?.focus();
  }
};

const forgotPasswordAlert = () => {
  notify.info(
    'Password Reset Assistance',
    'Please contact your system administrator or IT helpdesk to issue a temporary password or reset credentials.'
  );
};
</script>
