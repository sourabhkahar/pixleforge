<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    generation: { type: Object, required: true },
});
defineEmits(['view', 'reuse']);

const now = ref(Date.now());
const tick = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(tick));

const elapsed = computed(() => {
    const start = Date.parse(props.generation.created_at);
    const end = props.generation.completed_at ? Date.parse(props.generation.completed_at) : now.value;
    const s = Math.max(0, Math.round((end - start) / 1000));
    return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`;
});

const aspect = computed(() => {
    const [w, h] = props.generation.size.split('x').map(Number);
    return `${w} / ${h}`;
});

const STATUS = {
    queued: { label: 'Queued', classes: 'bg-gray-100 text-gray-700 ring-gray-500/20' },
    processing: { label: 'Processing', classes: 'bg-sky-50 text-sky-700 ring-sky-600/20' },
    succeeded: { label: 'Succeeded', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
    failed: { label: 'Failed', classes: 'bg-red-50 text-red-700 ring-red-600/20' },
};
const status = computed(() => STATUS[props.generation.status] ?? STATUS.queued);

const createdAt = computed(() => new Date(props.generation.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }));
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="relative bg-gray-100" :style="{ aspectRatio: aspect }">
            <!-- Image -->
            <button v-if="generation.image" type="button" class="block h-full w-full" @click="$emit('view')">
                <img :src="generation.image" :alt="generation.prompt" loading="lazy" class="h-full w-full object-cover" />
            </button>

            <!-- Running -->
            <div v-else-if="!generation.terminal" class="flex h-full w-full flex-col items-center justify-center gap-3 px-4 text-center text-gray-500">
                <div class="absolute inset-0 animate-pulse bg-gradient-to-br from-gray-100 via-gray-200 to-gray-100" />
                <svg class="relative h-8 w-8 animate-spin text-indigo-500" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" />
                    <path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                <span class="relative text-sm font-medium">{{ status.label }}… {{ elapsed }}</span>
                <span v-if="generation.status_detail" class="relative text-xs text-amber-700">{{ generation.status_detail }}</span>
            </div>

            <!-- Failed -->
            <div v-else-if="generation.status === 'failed'" class="flex h-full w-full flex-col items-center justify-center gap-2 bg-red-50 p-4 text-center">
                <svg class="h-8 w-8 text-red-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                <p class="line-clamp-4 text-xs text-red-700">{{ generation.error || 'Generation failed.' }}</p>
                <p class="text-[11px] font-medium text-red-600">Not charged</p>
            </div>

            <!-- Succeeded but image link expired and no stored copy -->
            <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1 p-4 text-center text-gray-500">
                <template v-if="generation.image_expired">
                    <span class="text-sm font-medium">Image link expired</span>
                    <span class="text-xs">Pixelforge image URLs last 24 hours.</span>
                </template>
                <span v-else class="text-sm">Saving image…</span>
            </div>

            <span class="absolute left-2 top-2 rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="status.classes">
                {{ status.label }}
            </span>
        </div>

        <div class="flex flex-1 flex-col gap-2 p-3">
            <p class="line-clamp-2 text-sm text-gray-800" :title="generation.prompt">{{ generation.prompt }}</p>

            <div class="flex flex-wrap gap-1.5 text-[11px] text-gray-600">
                <span class="rounded bg-gray-100 px-1.5 py-0.5">{{ generation.size }}</span>
                <span class="rounded bg-gray-100 px-1.5 py-0.5 capitalize">{{ generation.quality }}</span>
                <span v-if="generation.reference_images.length" class="rounded bg-gray-100 px-1.5 py-0.5">{{ generation.reference_images.length }} ref</span>
            </div>

            <dl class="grid grid-cols-2 gap-x-2 rounded bg-gray-50 px-2 py-1.5 text-xs">
                <dt class="text-gray-500">Estimated</dt>
                <dt class="text-right text-gray-500">Actual</dt>
                <dd class="tabular-nums text-gray-700">{{ generation.estimated_cost }}</dd>
                <dd class="text-right font-semibold tabular-nums" :class="generation.actual_cost === null ? 'text-gray-400' : 'text-gray-900'">
                    <template v-if="generation.actual_cost !== null">{{ generation.actual_cost }}</template>
                    <template v-else>pending</template>
                </dd>
            </dl>

            <div class="mt-auto flex items-center justify-between pt-1 text-xs text-gray-400">
                <span :title="createdAt">#{{ generation.id }} · {{ elapsed }}</span>
                <button type="button" class="hover:text-indigo-600" @click="$emit('reuse')">Reuse settings</button>
            </div>
        </div>
    </article>
</template>
