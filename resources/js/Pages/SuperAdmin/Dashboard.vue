<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';

const props = defineProps({
    companies: Array,
    tiffinServices: Array,
    assignments: Array,
});

const activeTab = ref('companies');

// Forms
const companyForm = useForm({
    name: '',
    address: '',
    contact_phone: '',
    admin_name: '',
    admin_email: '',
    admin_password: 'password',
});

const tiffinForm = useForm({
    name: '',
    address: '',
    contact_phone: '',
    admin_name: '',
    admin_email: '',
    admin_password: 'password',
});

const assignForm = useForm({
    company_id: '',
    tiffin_service_id: '',
});

const submitCompany = () => {
    companyForm.post('/super-admin/companies', {
        onSuccess: () => companyForm.reset('name', 'address', 'contact_phone', 'admin_name', 'admin_email'),
    });
};

const submitTiffin = () => {
    tiffinForm.post('/super-admin/tiffin-services', {
        onSuccess: () => tiffinForm.reset('name', 'address', 'contact_phone', 'admin_name', 'admin_email'),
    });
};

const submitAssign = () => {
    assignForm.post('/super-admin/assign', {
        onSuccess: () => assignForm.reset(),
    });
};

const deleteCompany = (companyId, companyName) => {
    if (confirm(`Are you sure you want to delete ${companyName}? This action cannot be undone.`)) {
        router.delete(`/super-admin/companies/${companyId}`);
    }
};

const unpairCompany = (companyId, companyName) => {
    if (confirm(`Are you sure you want to unpair ${companyName}?`)) {
        router.post('/super-admin/unpair', { company_id: companyId });
    }
};

const deleteTiffin = (tiffinId, tiffinName) => {
    if (confirm(`Are you sure you want to delete ${tiffinName}? This action cannot be undone.`)) {
        router.delete(`/super-admin/tiffin-services/${tiffinId}`);
    }
};

</script>

<template>
    <AppLayout>
    <div class="p-6">
        <div class="max-w-7xl mx-auto pb-6 border-b border-slate-800 mb-8">
            <h1 class="text-3xl font-bold text-emerald-400">Super Admin Dashboard</h1>
        </div>

        <main class="max-w-7xl mx-auto space-y-8">
            <!-- Pairing Tool Card -->
            <section class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-xl">
                <h2 class="text-xl font-bold text-emerald-300 mb-4">Pair Company ↔ Tiffin Service</h2>
                <form @submit.prevent="submitAssign" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-xs uppercase font-semibold text-slate-400 mb-1">Select Company</label>
                        <select v-model="assignForm.company_id" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                            <option value="" disabled>Choose Company</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs uppercase font-semibold text-slate-400 mb-1">Select Tiffin Service</label>
                        <select v-model="assignForm.tiffin_service_id" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                            <option value="" disabled>Choose Tiffin Service</option>
                            <option v-for="t in tiffinServices" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <button type="submit" :disabled="assignForm.processing" class="bg-emerald-500 hover:bg-emerald-600 font-bold text-slate-950 py-2 px-4 rounded-lg transition">
                        Confirm Pairing
                    </button>
                </form>
            </section>

            <!-- Tabs -->
            <div class="flex space-x-4 border-b border-slate-800">
                <button @click="activeTab = 'companies'" :class="['pb-2 text-sm font-semibold border-b-2 transition', activeTab === 'companies' ? 'border-emerald-400 text-emerald-400' : 'border-transparent text-slate-400 hover:text-slate-200']">
                    Companies ({{ companies.length }})
                </button>
                <button @click="activeTab = 'tiffins'" :class="['pb-2 text-sm font-semibold border-b-2 transition', activeTab === 'tiffins' ? 'border-emerald-400 text-emerald-400' : 'border-transparent text-slate-400 hover:text-slate-200']">
                    Tiffin Services ({{ tiffinServices.length }})
                </button>
                <button @click="activeTab = 'assignments'" :class="['pb-2 text-sm font-semibold border-b-2 transition', activeTab === 'assignments' ? 'border-emerald-400 text-emerald-400' : 'border-transparent text-slate-400 hover:text-slate-200']">
                    Pairing History
                </button>
            </div>

            <!-- Companies -->
            <section v-if="activeTab === 'companies'" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Add Company Form -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Add New Company</h3>
                    <form @submit.prevent="submitCompany" class="space-y-3">
                        <div>
                            <input v-model="companyForm.name" placeholder="Company Name" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                            <p v-if="companyForm.errors.name" class="text-red-400 text-xs mt-1">{{ companyForm.errors.name }}</p>
                        </div>
                        <div>
                            <input v-model="companyForm.address" placeholder="Address" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                            <p v-if="companyForm.errors.address" class="text-red-400 text-xs mt-1">{{ companyForm.errors.address }}</p>
                        </div>
                        <div>
                            <input v-model="companyForm.contact_phone" type="tel" placeholder="Phone (e.g. 9876543210)" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                            <p v-if="companyForm.errors.contact_phone" class="text-red-400 text-xs mt-1">{{ companyForm.errors.contact_phone }}</p>
                        </div>
                        <div class="pt-2 border-t border-slate-800">
                            <p class="text-xs text-slate-400 mb-2">Company Admin Account</p>
                            <div class="mb-2">
                                <input v-model="companyForm.admin_name" placeholder="Admin Name" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                                <p v-if="companyForm.errors.admin_name" class="text-red-400 text-xs mt-1">{{ companyForm.errors.admin_name }}</p>
                            </div>
                            <div class="mb-2">
                                <input v-model="companyForm.admin_email" type="email" placeholder="Admin Email" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                                <p v-if="companyForm.errors.admin_email" class="text-red-400 text-xs mt-1">{{ companyForm.errors.admin_email }}</p>
                            </div>
                            <div>
                                <input v-model="companyForm.admin_password" type="password" placeholder="Admin Password (min 6 chars)" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                                <p v-if="companyForm.errors.admin_password" class="text-red-400 text-xs mt-1">{{ companyForm.errors.admin_password }}</p>
                            </div>
                        </div>
                        <button type="submit" :disabled="companyForm.processing" class="w-full bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 text-slate-950 font-bold py-2 rounded-lg text-sm">
                            Create Company
                        </button>
                    </form>
                </div>

                <!-- Companies List -->
                <div class="lg:col-span-2 space-y-4">
                    <div v-for="c in companies" :key="c.id" class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex justify-between items-center">
                        <div>
                            <h4 class="text-lg font-bold text-white">{{ c.name }}</h4>
                            <p class="text-xs text-slate-400">{{ c.address || 'No address' }} | {{ c.contact_phone || 'No phone' }}</p>
                            <div class="mt-2 text-xs flex items-center gap-2">
                                <span class="text-slate-400">Assigned Tiffin: </span>
                                <template v-if="c.assignments?.find(a => a.is_active)">
                                    <span class="text-emerald-400 font-semibold">
                                        {{ c.assignments.find(a => a.is_active).tiffin_service?.name }}
                                    </span>
                                    <button @click="unpairCompany(c.id, c.name)" class="bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 text-[10px] px-2 py-0.5 rounded transition font-medium">
                                        Unpair
                                    </button>
                                </template>
                                <span v-else class="text-amber-400 italic">Not Assigned</span>
                            </div>
                        </div>
                        <button @click="deleteCompany(c.id, c.name)" class="bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 text-xs px-3 py-1.5 rounded-lg transition font-medium">
                            Delete
                        </button>
                    </div>
                </div>
            </section>

            <!-- Tiffin Services -->
            <section v-if="activeTab === 'tiffins'" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Add Tiffin Form -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                    <h3 class="text-lg font-bold text-white mb-4">Add Tiffin Service</h3>
                    <form @submit.prevent="submitTiffin" class="space-y-3">
                        <div>
                            <input v-model="tiffinForm.name" placeholder="Tiffin Service Name" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                            <p v-if="tiffinForm.errors.name" class="text-red-400 text-xs mt-1">{{ tiffinForm.errors.name }}</p>
                        </div>
                        <div>
                            <input v-model="tiffinForm.address" placeholder="Kitchen Address" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                            <p v-if="tiffinForm.errors.address" class="text-red-400 text-xs mt-1">{{ tiffinForm.errors.address }}</p>
                        </div>
                        <div>
                            <input v-model="tiffinForm.contact_phone" type="tel" placeholder="Phone (e.g. 9876543210)" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                            <p v-if="tiffinForm.errors.contact_phone" class="text-red-400 text-xs mt-1">{{ tiffinForm.errors.contact_phone }}</p>
                        </div>
                        <div class="pt-2 border-t border-slate-800">
                            <p class="text-xs text-slate-400 mb-2">Tiffin Admin Account</p>
                            <div class="mb-2">
                                <input v-model="tiffinForm.admin_name" placeholder="Admin Name" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                                <p v-if="tiffinForm.errors.admin_name" class="text-red-400 text-xs mt-1">{{ tiffinForm.errors.admin_name }}</p>
                            </div>
                            <div class="mb-2">
                                <input v-model="tiffinForm.admin_email" type="email" placeholder="Admin Email" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                                <p v-if="tiffinForm.errors.admin_email" class="text-red-400 text-xs mt-1">{{ tiffinForm.errors.admin_email }}</p>
                            </div>
                            <div>
                                <input v-model="tiffinForm.admin_password" type="password" placeholder="Admin Password (min 6 chars)" required class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white" />
                                <p v-if="tiffinForm.errors.admin_password" class="text-red-400 text-xs mt-1">{{ tiffinForm.errors.admin_password }}</p>
                            </div>
                        </div>
                        <button type="submit" :disabled="tiffinForm.processing" class="w-full bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 text-slate-950 font-bold py-2 rounded-lg text-sm">
                            Create Tiffin Service
                        </button>
                    </form>
                </div>

                <!-- Tiffin List -->
                <div class="lg:col-span-2 space-y-4">
                    <div v-for="t in tiffinServices" :key="t.id" class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex justify-between items-center">
                        <div>
                            <h4 class="text-lg font-bold text-white">{{ t.name }}</h4>
                            <p class="text-xs text-slate-400">{{ t.address || 'No address' }} | {{ t.contact_phone || 'No phone' }}</p>
                        </div>
                        <button @click="deleteTiffin(t.id, t.name)" class="bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 text-xs px-3 py-1.5 rounded-lg transition font-medium">
                            Delete
                        </button>
                    </div>
                </div>
            </section>

            <!-- Assignments -->
            <section v-if="activeTab === 'assignments'" class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">Pairing History</h3>
                <div class="space-y-3">
                    <div v-for="a in assignments" :key="a.id" class="flex justify-between items-center p-3 bg-slate-950 rounded-lg text-sm border border-slate-800">
                        <div>
                            <span class="font-bold text-white">{{ a.company?.name }}</span>
                            <span class="text-slate-500 mx-2">↔</span>
                            <span class="font-bold text-emerald-400">{{ a.tiffin_service?.name }}</span>
                        </div>
                        <div>
                            <span v-if="a.is_active" class="bg-emerald-500/20 text-emerald-300 text-xs px-2.5 py-1 rounded-full border border-emerald-500/30 font-semibold">Active</span>
                            <span v-else class="bg-slate-800 text-slate-400 text-xs px-2.5 py-1 rounded-full">Inactive</span>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
    </AppLayout>
</template>
