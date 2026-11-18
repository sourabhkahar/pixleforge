<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-gradient-to-br from-indigo-50 via-white to-sky-50">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-5">
            <div class="flex items-center gap-2">
                <ApplicationLogo class="h-8 w-auto fill-current text-indigo-600" />
                <span class="text-lg font-bold tracking-tight text-gray-900">Pixleforge</span>
            </div>
            <nav v-if="canLogin" class="flex items-center gap-2 text-sm">
                <Link
                    :href="route('login')"
                    class="rounded-md px-3 py-2 font-medium text-gray-700 hover:text-gray-900"
                >
                    Log in
                </Link>
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Sign up
                </Link>
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col items-center justify-center px-6 text-center">
            <h1 class="text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">
                Turn a prompt into an image.
            </h1>
            <p class="mt-4 text-lg text-gray-600">
                Describe what you want, pick a size and quality, and see the exact price before you generate.
                You pay the actual cost in US-dollar credits — failed generations are free.
            </p>
            <div class="mt-8 flex gap-3">
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="rounded-md bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Create an account
                </Link>
                <Link
                    :href="route('login')"
                    class="rounded-md bg-white px-5 py-3 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                >
                    Log in
                </Link>
            </div>
        </main>

        <footer class="py-6 text-center text-xs text-gray-400">Powered by Pixelforge</footer>
    </div>
</template>
