<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import BuyCredits from '@/Components/BuyCredits.vue';
import GenerationCard from '@/Pages/Generate/Partials/GenerationCard.vue';
import { characterCount, formatNanos } from '@/lib/money';

const props = defineProps({
    options: { type: Object, required: true },
    limits: { type: Object, required: true },
    defaults: { type: Object, required: true },
    initialEstimate: { type: Object, required: true },
    pricing: { type: Object, required: true },
    generations: { type: Object, required: true },
});

const page = usePage();

const form = useForm({
    prompt: '',
    size: props.defaults.size,
    quality: props.defaults.quality,
    reference_images: [],
});

// ------------------------------------------------------------ cost estimate
// Priced by the server (same code that reserves credits on submit), using
// only the prompt's length. Debounced so typing doesn't spam requests.
const estimate = ref(props.initialEstimate);
const estimating = ref(false);
const promptCharacters = computed(() => characterCount(form.prompt.trim()));
const filledReferences = computed(() => form.reference_images.map((u) => u.trim()).filter(Boolean));

let estimateTimer = null;
let estimateSeq = 0;
const refreshEstimate = () => {
    clearTimeout(estimateTimer);
    estimating.value = true;
    estimateTimer = setTimeout(async () => {
        const seq = ++estimateSeq;
        try {
            const { data } = await axios.get(route('generations.estimate'), {
                params: {
                    prompt_characters: Math.min(promptCharacters.value, props.limits.prompt_max),
                    size: form.size,
                    quality: form.quality,
                    reference_images: Math.min(filledReferences.value.length, props.limits.reference_images_max),
                },
            });
            if (seq === estimateSeq) estimate.value = data;
        } finally {
            if (seq === estimateSeq) estimating.value = false;
        }
    }, 250);
};
watch(() => [promptCharacters.value, form.size, form.quality, filledReferences.value.length], refreshEstimate);

const balanceNanos = computed(() => page.props.auth.user.balance_nanos);
const balanceAfterWorstCase = computed(() => balanceNanos.value - estimate.value.price_max_nanos);
const canAfford = computed(() => balanceAfterWorstCase.value >= 0);

const tokenRows = computed(() => {
    const t = estimate.value.tokens;
    const fmt = (n) => n.toLocaleString('en-US');
    const range = (key) => (t.min[key] === t.max[key] ? fmt(t.expected[key]) : `${fmt(t.min[key])}–${fmt(t.max[key])}`);
    return [
        { label: 'Prompt text', value: range('input_text_tokens'), note: '≈ 1 per 4 characters (±15%)' },
        { label: 'Reference images', value: fmt(t.expected.input_image_tokens), note: '765 each' },
        { label: 'Output image', value: fmt(t.expected.output_tokens), note: 'exact for size & quality' },
        { label: 'Reasoning', value: range('reasoning_tokens'), note: 'varies, known after completion' },
    ];
});

// ---------------------------------------------------------- reference images
const canAddReference = computed(() => form.reference_images.length < props.limits.reference_images_max);
const addReference = () => canAddReference.value && form.reference_images.push('');
const removeReference = (index) => form.reference_images.splice(index, 1);
const brokenPreviews = ref({});
const isLikelyUrl = (value) => /^https?:\/\/\S+$/i.test(value.trim());
const referenceError = (index) => form.errors[`reference_images.${index}`];

// ------------------------------------------------------------------- submit
const submit = () => {
    if (form.processing || !canAfford.value || !form.prompt.trim()) return;
    form.post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
            brokenPreviews.value = {};
        },
    });
};

// ----------------------------------------------------- gallery + live status
const items = ref([...props.generations.data]);
watch(
    () => props.generations.data,
    (fresh) => (items.value = [...fresh]),
);

const activeIds = computed(() => items.value.filter((g) => !g.terminal || (g.status === 'succeeded' && !g.image_stored && !g.image_expired)).map((g) => g.id));

let pollTimer = null;
const poll = async () => {
    if (activeIds.value.length === 0) return;
    try {
        const { data } = await axios.get(route('generations.status'), { params: { ids: activeIds.value.join(',') } });
        const byId = Object.fromEntries(data.generations.map((g) => [g.id, g]));
        items.value = items.value.map((g) => byId[g.id] ?? g);
        page.props.auth.user.balance = data.balance;
        page.props.auth.user.balance_nanos = data.balance_nanos;
    } catch {
        // transient network error — next tick will retry
    }
};
watch(
    activeIds,
    (ids) => {
        if (ids.length && !pollTimer) pollTimer = setInterval(poll, 2000);
        else if (!ids.length && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearTimeout(estimateTimer);
});

// ------------------------------------------------------------------ viewer
const viewing = ref(null);
const reuse = (generation) => {
    form.prompt = generation.prompt;
    form.size = generation.size;
    form.quality = generation.quality;
    form.reference_images = [...(generation.reference_images ?? [])];
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const aspectBox = (size) => {
    const [w, h] = size.split('x').map(Number);
    return { width: `${(w / 1536) * 34}px`, height: `${(h / 1536) * 34}px` };
};
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Generate images</h2>
                <span v-if="pricing.source === 'fallback'" class="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">
                    Live rates unavailable — estimating with published rates
                </span>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-5 lg:px-8">
                <!-- ======================= Form ======================= -->
                <form class="space-y-6 rounded-lg bg-white p-6 shadow-sm lg:col-span-3" @submit.prevent="submit">
                    <div v-if="page.props.flash.success" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {{ page.props.flash.success }}
                    </div>

                    <!-- Prompt -->
                    <div>
                        <div class="flex items-baseline justify-between">
                            <label for="prompt" class="block text-sm font-medium text-gray-700">Prompt</label>
                            <span class="text-xs" :class="promptCharacters > limits.prompt_max ? 'text-red-600' : 'text-gray-400'">
                                {{ promptCharacters.toLocaleString() }} / {{ limits.prompt_max.toLocaleString() }}
                            </span>
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            required
                            :maxlength="limits.prompt_max"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            @keydown.ctrl.enter="submit"
                            @keydown.meta.enter="submit"
                        />
                        <InputError class="mt-2" :message="form.errors.prompt" />
                    </div>

                    <!-- Size -->
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">Size</legend>
                        <div class="mt-2 grid grid-cols-3 gap-3">
                            <label
                                v-for="size in options.sizes"
                                :key="size.value"
                                class="flex cursor-pointer flex-col items-center rounded-lg border p-3 text-center transition"
                                :class="form.size === size.value ? 'border-indigo-600 bg-indigo-50 ring-1 ring-indigo-600' : 'border-gray-200 hover:border-gray-300'"
                            >
                                <input v-model="form.size" type="radio" :value="size.value" class="sr-only" />
                                <span class="mb-2 block rounded-sm border-2 border-current" :class="form.size === size.value ? 'text-indigo-600' : 'text-gray-400'" :style="aspectBox(size.value)" />
                                <span class="text-sm font-semibold text-gray-900">{{ size.label }}</span>
                                <span class="text-xs text-gray-500">{{ size.value.replace('x', ' × ') }}</span>
                            </label>
                        </div>
                        <InputError class="mt-2" :message="form.errors.size" />
                    </fieldset>

                    <!-- Quality -->
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">Quality</legend>
                        <div class="mt-2 inline-flex rounded-md shadow-sm">
                            <label
                                v-for="(quality, i) in options.qualities"
                                :key="quality.value"
                                class="cursor-pointer border px-5 py-2 text-sm font-medium"
                                :class="[
                                    i === 0 ? 'rounded-l-md' : '-ml-px',
                                    i === options.qualities.length - 1 ? 'rounded-r-md' : '',
                                    form.quality === quality.value ? 'z-10 border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
                                ]"
                            >
                                <input v-model="form.quality" type="radio" :value="quality.value" class="sr-only" />
                                {{ quality.label }}
                            </label>
                        </div>
                        <InputError class="mt-2" :message="form.errors.quality" />
                    </fieldset>

                    <!-- Reference images -->
                    <fieldset>
                        <div class="flex items-baseline justify-between">
                            <legend class="text-sm font-medium text-gray-700">
                                Reference images <span class="font-normal text-gray-400">(optional, URLs)</span>
                            </legend>
                            <span class="text-xs text-gray-500">up to {{ limits.reference_images_max }}</span>
                        </div>

                        <div class="mt-2 space-y-2">
                            <div v-for="(url, index) in form.reference_images" :key="index">
                                <div class="flex items-center gap-2">
                                    <div class="h-10 w-10 shrink-0 overflow-hidden rounded border border-gray-200 bg-gray-50">
                                        <img
                                            v-if="isLikelyUrl(url) && !brokenPreviews[url]"
                                            :src="url.trim()"
                                            alt=""
                                            class="h-full w-full object-cover"
                                            referrerpolicy="no-referrer"
                                            @error="brokenPreviews[url] = true"
                                        />
                                    </div>
                                    <input
                                        v-model="form.reference_images[index]"
                                        type="url"
                                        inputmode="url"
                                        placeholder="https://example.com/style.png"
                                        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <button type="button" class="rounded p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" :aria-label="`Remove reference image ${index + 1}`" @click="removeReference(index)">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                                    </button>
                                </div>
                                <InputError class="ml-12 mt-1" :message="referenceError(index)" />
                            </div>
                        </div>

                        <button v-if="canAddReference" type="button" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-500" @click="addReference">
                            <span class="text-lg leading-none">+</span> Add reference image URL
                        </button>
                        <InputError class="mt-2" :message="form.errors.reference_images" />
                    </fieldset>

                    <!-- Cost estimate -->
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4" :class="estimating && 'opacity-70'">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-sm font-medium text-gray-700">Estimated cost</div>
                                <div class="mt-0.5 text-2xl font-bold tabular-nums text-gray-900">{{ estimate.price_expected }}</div>
                                <div class="text-xs text-gray-500">
                                    Likely between {{ estimate.price_min }} and {{ estimate.price_max }}
                                </div>
                            </div>
                            <div class="text-right text-xs text-gray-500">
                                <div>Reserved now: <span class="font-semibold tabular-nums text-gray-700">{{ estimate.price_max }}</span></div>
                                <div>
                                    Balance after reserve:
                                    <span class="font-semibold tabular-nums" :class="canAfford ? 'text-gray-700' : 'text-red-600'">{{ formatNanos(balanceAfterWorstCase) }}</span>
                                </div>
                            </div>
                        </div>

                        <details class="mt-3 text-xs text-gray-600">
                            <summary class="cursor-pointer select-none font-medium text-gray-700">How this is calculated</summary>
                            <table class="mt-2 w-full">
                                <tbody>
                                    <tr v-for="row in tokenRows" :key="row.label" class="border-t border-gray-200">
                                        <td class="py-1 pr-2">{{ row.label }}</td>
                                        <td class="py-1 pr-2 text-right tabular-nums">{{ row.value }} tokens</td>
                                        <td class="py-1 text-gray-400">{{ row.note }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="mt-2">
                                Priced at Pixelforge's per-token rates plus a {{ estimate.markup_percent }}% service fee. The reasoning
                                token count is only known once the image is done, so we reserve the maximum and charge the
                                <strong>actual</strong> cost when it finishes. Failed generations are free.
                            </p>
                        </details>

                        <div v-if="!canAfford" class="mt-3 space-y-2">
                            <p class="text-sm text-red-600">
                                Not enough credits: this could cost up to {{ estimate.price_max }} and your balance is {{ page.props.auth.user.balance }}.
                            </p>
                            <BuyCredits />
                        </div>
                        <InputError class="mt-2" :message="form.errors.credits" />
                    </div>

                    <div class="flex items-center justify-end gap-4">
                        <button
                            type="submit"
                            :disabled="form.processing || !canAfford || !form.prompt.trim()"
                            class="inline-flex shrink-0 items-center gap-2 rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25" /><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" /></svg>
                            Generate · ≈ {{ estimate.price_expected }}
                        </button>
                    </div>
                </form>

                <!-- ======================= Sidebar ======================= -->
                <aside class="space-y-4 lg:col-span-2">
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <div class="text-sm text-gray-500">Your balance</div>
                        <div class="mt-1 text-3xl font-bold tabular-nums text-gray-900">{{ page.props.auth.user.balance }}</div>
                        <p class="mt-1 text-xs text-gray-500">Credits are US dollars. Reservations for running generations are already deducted.</p>
                        <div class="mt-4"><BuyCredits /></div>
                        <Link :href="route('usage.index')" class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">View credit history →</Link>
                    </div>
                    <div class="rounded-lg bg-white p-6 text-sm text-gray-600 shadow-sm">
                        <div class="mb-2 font-medium text-gray-900">Pixelforge rates</div>
                        <table class="w-full">
                            <tbody>
                                <tr v-for="(rate, type) in pricing.rates" :key="type" class="border-t border-gray-100">
                                    <td class="py-1.5 capitalize">{{ type.replace(/_/g, ' ') }}</td>
                                    <td class="py-1.5 text-right tabular-nums">${{ rate }} / 1M</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="mt-2 text-xs text-gray-500">You pay these rates + {{ pricing.markup_percent }}%. Every cost is kept exact to the nanodollar — nothing is rounded to the cent.</p>
                    </div>
                </aside>

                <!-- ======================= History ======================= -->
                <section class="lg:col-span-5">
                    <h3 class="mb-3 text-lg font-semibold text-gray-900">History</h3>

                    <div v-if="items.length === 0" class="rounded-lg border-2 border-dashed border-gray-200 p-10 text-center text-sm text-gray-500">
                        Nothing yet — write a prompt above and hit Generate.
                    </div>

                    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <GenerationCard v-for="generation in items" :key="generation.id" :generation="generation" @view="viewing = generation" @reuse="reuse(generation)" />
                    </div>

                    <nav v-if="generations.last_page > 1" class="mt-6 flex justify-center gap-1">
                        <template v-for="link in generations.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded px-3 py-1.5 text-sm"
                                :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-white'"
                                v-html="link.label"
                            />
                            <span v-else class="px-3 py-1.5 text-sm text-gray-300" v-html="link.label" />
                        </template>
                    </nav>
                </section>
            </div>
        </div>

        <Modal :show="viewing !== null" max-width="2xl" @close="viewing = null">
            <div v-if="viewing" class="p-4">
                <img :src="viewing.image" :alt="viewing.prompt" class="mx-auto max-h-[70vh] rounded" />
                <p class="mt-3 text-sm text-gray-700">{{ viewing.prompt }}</p>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                    <span>{{ viewing.size }} · {{ viewing.quality }} · charged {{ viewing.actual_cost }}</span>
                    <a :href="viewing.image" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:text-indigo-500">Open full size ↗</a>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
