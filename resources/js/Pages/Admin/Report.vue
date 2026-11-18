<script setup>
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    range: { type: Object, required: true },
    totals: { type: Object, required: true },
    days: { type: Array, required: true },
    usageError: { type: String, default: null },
});

const filters = reactive({ ...props.range });
const apply = () => router.get(route('admin.report'), filters, { preserveScroll: true });

const open = ref({});
const toggle = (date) => (open.value[date] = !open.value[date]);
</script>

<template>
    <Head title="Admin report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Admin report</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <form class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm" @submit.prevent="apply">
                    <label class="text-sm">
                        <span class="block text-gray-500">From (UTC)</span>
                        <input v-model="filters.start" type="date" class="mt-1 rounded-md border-gray-300 text-sm shadow-sm" />
                    </label>
                    <label class="text-sm">
                        <span class="block text-gray-500">To (UTC)</span>
                        <input v-model="filters.end" type="date" class="mt-1 rounded-md border-gray-300 text-sm shadow-sm" />
                    </label>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Apply</button>
                    <p class="ml-auto max-w-sm text-xs text-gray-500">
                        Days are UTC and bucketed by completion time — the same billing day Pixelforge uses in
                        <code>GET /v1/usage</code>. Only succeeded generations cost anything.
                    </p>
                </form>

                <dl class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Charged to users</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums">{{ totals.charged }}</dd>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Provider cost (ours)</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums">{{ totals.provider_cost }}</dd>
                        <p class="text-xs text-gray-400">Pixelforge says: {{ totals.pixelforge_cost ?? 'n/a' }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Profit</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-emerald-700">{{ totals.profit }}</dd>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <dt class="text-sm text-gray-500">Billed generations</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums">{{ totals.generations }}</dd>
                    </div>
                </dl>

                <div v-if="usageError" class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ usageError }}</div>

                <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 text-right font-medium">Gens</th>
                                <th class="px-4 py-2 text-right font-medium">Charged</th>
                                <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                                <th class="px-4 py-2 text-right font-medium">Pixelforge /v1/usage</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="day in days" :key="day.date">
                                <tr class="cursor-pointer hover:bg-gray-50" :class="day.generations === 0 && 'text-gray-400'" @click="day.users.length && toggle(day.date)">
                                    <td class="whitespace-nowrap px-4 py-2 font-medium">
                                        <span v-if="day.users.length" class="mr-1 inline-block w-3 text-gray-400">{{ open[day.date] ? '▾' : '▸' }}</span>
                                        <span v-else class="mr-1 inline-block w-3" />
                                        {{ day.date }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ day.generations }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ day.charged }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ day.provider_cost }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ day.profit }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                        <template v-if="day.pixelforge_cost !== null">
                                            {{ day.pixelforge_cost }} · {{ day.pixelforge_generations }}
                                            <span v-if="day.matches" class="ml-1 font-semibold text-emerald-600" title="Matches our records">✓</span>
                                            <span v-else class="ml-1 font-semibold text-red-600" title="Differs from our records">✗</span>
                                        </template>
                                        <span v-else class="text-gray-300">—</span>
                                    </td>
                                </tr>
                                <tr v-for="row in open[day.date] ? day.users : []" :key="day.date + row.user?.id" class="bg-gray-50/60 text-xs">
                                    <td class="px-4 py-1.5 pl-10 text-gray-600">{{ row.user?.name }} <span class="text-gray-400">{{ row.user?.email }}</span></td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ row.generations }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ row.charged }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ row.provider_cost }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ row.profit }}</td>
                                    <td />
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
