<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import AuthShell from '@/components/AuthShell.vue';
import { login, register } from '@/routes';
import { scan } from '@/routes/device-link';

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

        <!--
          A button rather than the small print it used to be. Signing up is a
          real way into this screen now that the app is shared beyond the
          household, and the two things you can do here should look like two
          things you can do. Outlined, not filled: the account you already have
          is still the likelier of the two.
        -->
        <div v-if="canRegister" class="mt-6 border-t border-rule pt-6">
            <p class="mb-3 text-center text-sm text-ink-muted">
                Pierwszy raz tutaj?
            </p>

            <Link
                :href="register.url()"
                class="flex h-12 w-full items-center justify-center rounded-full border border-accent-strong font-medium text-accent-strong"
            >
                Załóż konto
            </Link>
        </div>

        <!--
          The camera is opened from here rather than left to the phone's own
          camera app: the instruction it replaces asked somebody standing in a
          kitchen to leave the app, find the right app, and trust that a
          notification would bring them back.
        -->
        <div class="mt-6 border-t border-rule pt-6">
            <Link
                :href="scan.url()"
                class="flex h-12 w-full items-center justify-center gap-2 rounded-full border border-rule-strong font-medium text-ink"
            >
                <AppIcon name="qr" class="h-5 w-5" />
                Zaloguj się kodem QR
            </Link>

            <p class="mt-3 text-center text-sm text-ink-muted">
                Kod pokazuje druga osoba na swoim telefonie, w „Dodaj
                urządzenie”.
            </p>
        </div>
    </AuthShell>
</template>
