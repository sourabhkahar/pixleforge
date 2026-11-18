<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BuyCredits from '@/Components/BuyCredits.vue';

const page = usePage();

defineProps({
    summary: { type: Object, required: true },
    transactions: { type: Object, required: true },
});

const typeStyles = {
    grant: 'bg-sky-50 text-sky-700 ring-sky-600/20',
    purchase: 'bg-sky-50 text-sky-700 ring-sky-600/20',
    hold: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    release: 'bg-gray-50 text-gray-600 ring-gray-500/20',
    charge: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
};

const when = (iso) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Usage & credits" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Usage &amp; credits</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="page.props.flash.success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ page.props.flash.success }}
                </div>

                <dl class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Available balance</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ summary.balance }}</dd>
                        <p v-if="summary.reserved !== '$0.00'" class="text-xs text-gray-400">+ {{ summary.reserved }} reserved</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Spent on images</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ summary.spent }}</dd>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Credits added</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ summary.added }}</dd>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Generations</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ summary.generations }}</dd>
                        <p class="text-xs text-gray-400">{{ summary.succeeded }} succeeded · {{ summary.failed }} failed (free)</p>
                    </div>
                </dl>

                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <BuyCredits />
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="font-semibold text-gray-900">Credit history</h3>
                        <p class="text-sm text-gray-500">
                            Newest first, in US dollars. Starting a generation <em>reserves</em> its maximum price; when it finishes the
                            reservation is <em>released</em> and the actual cost is <em>charged</em> (failed generations are never charged).
                        </p>
                    </div>

                    <div v-if="transactions.data.length === 0" class="p-8 text-center text-sm text-gray-500">
                        No activity yet.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-5 py-2 font-medium">Date</th>
                                    <th class="px-5 py-2 font-medium">Type</th>
                                    <th class="px-5 py-2 font-medium">Description</th>
                                    <th class="px-5 py-2 text-right font-medium">Amount</th>
                                    <th class="px-5 py-2 text-right font-medium">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="t in transactions.data" :key="t.id">
                                    <td class="whitespace-nowrap px-5 py-2.5 text-gray-500">{{ when(t.created_at) }}</td>
                                    <td class="px-5 py-2.5">
                                        <span class="rounded-md px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset" :class="typeStyles[t.type]">
                                            {{ t.type }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-2.5 text-gray-700">{{ t.description }}</td>
                                    <td
                                        class="whitespace-nowrap px-5 py-2.5 text-right font-medium tabular-nums"
                                        :class="t.amount_nanos < 0 ? 'text-gray-900' : 'text-emerald-700'"
                                    >
                                        {{ t.amount_nanos > 0 ? '+' : '' }}{{ t.amount }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right tabular-nums text-gray-500">{{ t.balance_after }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <nav v-if="transactions.last_page > 1" class="flex justify-center gap-1 border-t border-gray-100 p-3">
                        <template v-for="link in transactions.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded px-3 py-1.5 text-sm"
                                :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-50'"
                                v-html="link.label"
                            />
                            <span v-else class="px-3 py-1.5 text-sm text-gray-300" v-html="link.label" />
                        </template>
                    </nav>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
