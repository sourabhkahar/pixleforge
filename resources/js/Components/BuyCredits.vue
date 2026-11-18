<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

// Demo top-up: no payment provider, the server just records a purchase.
const page = usePage();
const busy = ref(null);

const buy = (amount) => {
    busy.value = amount;
    router.post(route('credits.purchase'), { amount }, {
        preserveScroll: true,
        onFinish: () => (busy.value = null),
    });
};
</script>

<template>
    <div>
        <div class="text-xs font-medium text-gray-500">Buy credits <span class="font-normal">(demo — no payment)</span></div>
        <div class="mt-1.5 flex flex-wrap gap-2">
            <button
                v-for="amount in page.props.purchaseOptions"
                :key="amount"
                type="button"
                :disabled="busy !== null"
                class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
                @click="buy(amount)"
            >
                {{ busy === amount ? 'Adding…' : `+ $${Number(amount).toFixed(2)}` }}
            </button>
        </div>
    </div>
</template>
