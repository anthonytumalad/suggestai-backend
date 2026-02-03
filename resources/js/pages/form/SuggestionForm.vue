<template>
    <div class="min-h-screen flex justify-center mx-auto p-6">
        <div class="w-full max-w-xl flex flex-col space-y-2 text-foreground">

            <div class="bg-white border border-gray-200 rounded p-6 text-center">
                <h3 class="text-sm font-medium text-gray-700 mb-4">Share this form</h3>
                <img
                    :src="form.qr_code_url"
                    alt="QR Code"
                    class="w-48 h-48 mx-auto"
                />
                <p class="text-xs text-gray-500 mt-2">Scan to access this form</p>
                <div class="mt-4">
                    <input
                        :value="form.url"
                        readonly
                        class="w-full text-xs bg-gray-50 border border-gray-200 rounded px-3 py-2 text-gray-600"
                    />
                </div>
            </div>

            <div v-if="form?.img_url" class="border border-gray-500 rounded overflow-hidden">
                <img :src="form.img_url" alt="" class="w-full h-34 object-cover" />
            </div>


            <form class="space-y-2" @submit.prevent="submitForm">
                <div class="bg-white border border-gray-200 rounded">
                    <div class="px-6 py-4 space-y-2">
                        <h1 class="text-2xl font-medium">
                            {{ form.title }}
                        </h1>
                        <p v-if="form?.description" class="text-sm font-normal">
                            {{ form.description }}
                        </p>
                    </div>

                    <div class="border-y border-gray-200 px-6 py-4">
                        <span class="text-sm italic font-normal">
                            You logged in as
                            <span class="underline">{{ displayEmail }}</span>
                        </span>
                    </div>

                    <div class="py-4 px-6 flex items-center space-x-2 text-sm">
                        <input type="checkbox" v-model="anonymous" id="agree" class="cursor-pointer" />
                        <label for="agree" class="cursor-pointer">
                            Submit anonymously
                        </label>
                    </div>
                </div>

                <div class="bg-white border border-gray-200 rounded">
                    <div class="py-4 px-6">
                        <textarea v-model="suggestion" placeholder="Describe your suggestion"
                            class="w-full text-sm min-h-45 p-3 border-b border-gray-200 focus:outline-none resize-y"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-between text-sm mt-8">
                    <button type="submit"
                        class="bg-blue-400 hover:bg-blue-500 transition-all duration-300 rounded text-white py-1 px-4 cursor-pointer">
                        Submit
                    </button>
                    <button type="button" @click="clearForm" class="cursor-pointer hover:underline">
                        Clear
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";

const { form, userEmail } = defineProps({
    form: Object,
    userEmail: String,
});

const suggestion = ref("");
const anonymous = ref(false);

const displayEmail = computed(() => {
    return userEmail && userEmail.trim() !== ""
        ? userEmail
        : "Guest";
});

const submitForm = () => {
    if (!suggestion.value.trim()) {
        alert("Suggestion is required");
        return;
    }

    router.post(
        `/forms/${form.slug}/submit`,
        {
            suggestion: suggestion.value,
            is_anonymous: anonymous.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                suggestion.value = "";
                anonymous.value = false;
            },
        }
    );
};

const clearForm = () => {
    suggestion.value = "";
    anonymous.value = false;
};
</script>
