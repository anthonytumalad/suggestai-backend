<template>
    <div class="min-h-screen flex justify-center mx-auto p-6">

        <div
            v-if="showToast"
            :class="[
                'fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg flex items-center space-x-2 z-50 animate-slide-in',
                toastType === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
            ]"
        >
            <svg v-if="toastType === 'success'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            <span>{{ toastMessage }}</span>
        </div>

        <div class="w-full max-w-xl flex flex-col space-y-2 text-foreground">

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
                        <textarea
                            v-model="suggestion"
                            placeholder="Describe your suggestion"
                            @input="validateInput"
                            :class="[
                                'w-full text-sm min-h-45 p-3 border-b focus:outline-none resize-y',
                                validationError ? 'border-red-300' : 'border-gray-200'
                            ]"
                        ></textarea>
                        <p v-if="validationError" class="text-red-500 text-xs mt-2">
                            {{ validationError }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-between text-sm mt-8">
                    <button
                        type="submit"
                        :disabled="!isFormValid || isSubmitting"
                        :class="[
                            'rounded text-white py-1 px-4 transition-all duration-300',
                            !isFormValid || isSubmitting
                                ? 'bg-gray-300 cursor-not-allowed'
                                : 'bg-blue-400 hover:bg-blue-500 cursor-pointer'
                        ]"
                    >
                        {{ isSubmitting ? 'Submitting...' : 'Submit' }}
                    </button>
                    <button type="button" @click="clearForm" class="cursor-pointer hover:underline">
                        Clear
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref, computed } from "vue";

interface Form {
    title: string;
    slug: string;
    description?: string;
    img_url?: string;
    qr_code_url?: string;
    url?: string;
}

const { form, userEmail } = defineProps<{
    form: Form;
    userEmail?: string;
}>();

const suggestion = ref("");
const anonymous = ref(false);
const showToast = ref(false);
const toastMessage = ref("");
const toastType = ref<"success" | "error">("success");
const isSubmitting = ref(false);
const validationError = ref("");

const displayEmail = computed(() => {
    return userEmail && userEmail.trim() !== ""
        ? userEmail
        : "Guest";
});

const isFormValid = computed(() => {
    return suggestion.value.trim() !== "" && !validationError.value;
});

const validateInput = () => {
    const text = suggestion.value.trim();

    if (!text) {
        validationError.value = "";
        return;
    }

    // Check minimum length
    if (text.length < 10) {
        validationError.value = "Please provide a more detailed suggestion (at least 10 characters)";
        return;
    }

    // Remove special characters and numbers for analysis
    const words = text.replace(/[^a-zA-Z\s]/g, '').split(/\s+/).filter(word => word.length > 0);

    if (words.length < 3) {
        validationError.value = "Please use at least 3 words to describe your suggestion";
        return;
    }

    // Check for gibberish - words with too many consecutive consonants or vowels
    const hasGibberish = words.some(word => {
        if (word.length < 3) return false; // Short words are okay

        // Check for excessive consecutive consonants (more than 4)
        if (/[bcdfghjklmnpqrstvwxyz]{5,}/i.test(word)) {
            return true;
        }

        // Check for excessive consecutive vowels (more than 3)
        if (/[aeiou]{4,}/i.test(word)) {
            return true;
        }

        // Check vowel-to-consonant ratio for gibberish detection
        const vowels = (word.match(/[aeiou]/gi) || []).length;
        const consonants = (word.match(/[bcdfghjklmnpqrstvwxyz]/gi) || []).length;
        const total = vowels + consonants;

        // If word is mostly one type and long enough, it might be gibberish
        if (total > 6 && (vowels / total < 0.15 || vowels / total > 0.85)) {
            return true;
        }

        return false;
    });

    if (hasGibberish) {
        validationError.value = "Please enter a meaningful suggestion with real words";
        return;
    }

    // Check for repeated characters (like "aaaaaaa")
    if (/(.)\1{6,}/.test(text)) {
        validationError.value = "Please avoid excessive repeated characters";
        return;
    }

    validationError.value = "";
};

const showToastMessage = (message: string, type: "success" | "error" = "success") => {
    toastMessage.value = message;
    toastType.value = type;
    showToast.value = true;
    setTimeout(() => {
        showToast.value = false;
    }, 3000);
};

const submitForm = () => {
    if (!isFormValid.value || isSubmitting.value) {
        return;
    }

    // Final validation before submit
    validateInput();
    if (validationError.value) {
        showToastMessage(validationError.value, "error");
        return;
    }

    isSubmitting.value = true;

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
                validationError.value = "";
                isSubmitting.value = false;
                showToastMessage("Suggestion submitted successfully!", "success");
            },
            onError: () => {
                isSubmitting.value = false;
                showToastMessage("Failed to submit. Please try again.", "error");
            },
        }
    );
};

const clearForm = () => {
    suggestion.value = "";
    anonymous.value = false;
    validationError.value = "";
};
</script>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.animate-slide-in {
    animation: slide-in 0.3s ease-out;
}
</style>
