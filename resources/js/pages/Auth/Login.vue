<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import { login, register } from '@/routes';

defineProps<{ canRegister: boolean }>();

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

function submit(): void {
    form.post(login.url(), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Logowanie" />

    <AuthShell title="Kuchnia" subtitle="Zaloguj się, żeby wejść do przepisów.">
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-1.5 block text-sm text-ink-muted">
                    E-mail
                </label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus
                    class="h-12 w-full rounded-xl border border-rule-strong bg-paper-raised px-4 text-base text-ink focus:border-accent focus:outline-none"
                    :aria-invalid="Boolean(form.errors.email)"
                    :aria-describedby="
                        form.errors.email ? 'email-error' : undefined
                    "
                />
                <p
                    v-if="form.errors.email"
                    id="email-error"
                    class="mt-1.5 text-sm text-accent-strong"
                >
                    {{ form.errors.email }}
                </p>
            </div>

            <div>
                <label
                    for="password"
                    class="mb-1.5 block text-sm text-ink-muted"
                >
                    Hasło
                </label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="h-12 w-full rounded-xl border border-rule-strong bg-paper-raised px-4 text-base text-ink focus:border-accent focus:outline-none"
                    :aria-invalid="Boolean(form.errors.password)"
                />
                <p
                    v-if="form.errors.password"
                    class="mt-1.5 text-sm text-accent-strong"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <label class="flex items-center gap-2.5 text-sm text-ink-muted">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="h-4 w-4 [accent-color:var(--color-accent)]"
                />
                Nie wylogowuj mnie
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="h-12 w-full rounded-full bg-accent font-medium text-ink transition-opacity disabled:opacity-60"
            >
                {{ form.processing ? 'Loguję…' : 'Zaloguj się' }}
            </button>
        </form>

        <p class="mt-8 text-center text-sm text-ink-muted">
            Masz kod QR od drugiej osoby? Zeskanuj go aparatem — otworzy tę
            aplikację i zaloguje Cię automatycznie.
        </p>

        <p v-if="canRegister" class="mt-4 text-center text-sm">
            <Link
                :href="register.url()"
                class="text-accent-strong underline underline-offset-4"
            >
                Załóż konto
            </Link>
        </p>
    </AuthShell>
</template>
