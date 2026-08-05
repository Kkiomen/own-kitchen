<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthShell from '@/components/AuthShell.vue';
import { login, register } from '@/routes';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post(register.url(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Zakładanie konta" />

    <AuthShell
        title="Zakładamy konto"
        subtitle="Jedno konto na całe gospodarstwo. Drugą osobę dodasz później kodem QR — bez podawania jej hasła."
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="name" class="mb-1.5 block text-sm text-ink-muted">
                    Imię
                </label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    required
                    autofocus
                    class="h-12 w-full rounded-xl border border-rule-strong bg-paper-raised px-4 text-base text-ink focus:border-accent focus:outline-none"
                />
                <p
                    v-if="form.errors.name"
                    class="mt-1.5 text-sm text-accent-strong"
                >
                    {{ form.errors.name }}
                </p>
            </div>

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
                    class="h-12 w-full rounded-xl border border-rule-strong bg-paper-raised px-4 text-base text-ink focus:border-accent focus:outline-none"
                />
                <p
                    v-if="form.errors.email"
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
                    autocomplete="new-password"
                    required
                    class="h-12 w-full rounded-xl border border-rule-strong bg-paper-raised px-4 text-base text-ink focus:border-accent focus:outline-none"
                />
                <p
                    v-if="form.errors.password"
                    class="mt-1.5 text-sm text-accent-strong"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <div>
                <label
                    for="password_confirmation"
                    class="mb-1.5 block text-sm text-ink-muted"
                >
                    Powtórz hasło
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="h-12 w-full rounded-xl border border-rule-strong bg-paper-raised px-4 text-base text-ink focus:border-accent focus:outline-none"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="h-12 w-full rounded-full bg-accent font-medium text-ink transition-opacity disabled:opacity-60"
            >
                {{ form.processing ? 'Zakładam…' : 'Załóż konto' }}
            </button>
        </form>

        <p class="mt-8 text-center text-sm">
            <Link
                :href="login.url()"
                class="text-accent-strong underline underline-offset-4"
            >
                Mam już konto
            </Link>
        </p>
    </AuthShell>
</template>
