<script setup lang="ts">
import { computed, h, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import {
    NButton,
    NDropdown,
    NIcon,
    NLayout,
    NLayoutContent,
    NLayoutHeader,
    NLayoutSider,
    NMenu,
    NText,
    type MenuOption,
} from 'naive-ui';
import { ChevronDownOutline, LogOutOutline, MailOutline, PersonCircleOutline } from '@vicons/ionicons5';
import { menuEntries, roleLabels, visibleMenu } from '@/menu';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const collapsed = ref(false);

const menuOptions = computed<MenuOption[]>(() =>
    visibleMenu(menuEntries, auth.can).map((entry) => ({
        key: entry.route,
        label: () => h(RouterLink, { to: { name: entry.route } }, { default: () => entry.label }),
        icon: () => h(NIcon, null, { default: () => h(entry.icon) }),
    })),
);

// Sección activa: la de la ruta actual o la de su "padre" (detalle → listado).
const activeKey = computed(() => route.meta.menu ?? (route.name as string));

const userOptions = [
    { label: 'Mi cuenta', key: 'account', icon: () => h(NIcon, null, { default: () => h(PersonCircleOutline) }) },
    { label: 'Cerrar sesión', key: 'logout', icon: () => h(NIcon, null, { default: () => h(LogOutOutline) }) },
];

async function onUserAction(key: string): Promise<void> {
    if (key === 'account') {
        await router.push({ name: 'account' });
    } else if (key === 'logout') {
        await auth.logout();
        await router.push({ name: 'login' });
    }
}
</script>

<template>
    <NLayout has-sider class="layout">
        <NLayoutSider
            bordered
            collapse-mode="width"
            :collapsed-width="64"
            :width="220"
            :collapsed="collapsed"
            show-trigger
            @collapse="collapsed = true"
            @expand="collapsed = false"
        >
            <div class="layout__brand">
                <NIcon size="22"><MailOutline /></NIcon>
                <span v-if="!collapsed">AI Mail</span>
            </div>
            <NMenu :value="activeKey" :options="menuOptions" :collapsed="collapsed" :collapsed-width="64" />
        </NLayoutSider>

        <NLayout>
            <NLayoutHeader bordered class="layout__header">
                <NText strong data-test="tenant-name">{{ auth.user?.tenant.name }}</NText>
                <NDropdown :options="userOptions" trigger="click" @select="onUserAction">
                    <NButton quaternary icon-placement="right">
                        {{ auth.user?.name }}
                        <NText depth="3" class="layout__role">· {{ auth.user ? roleLabels[auth.user.role] : '' }}</NText>
                        <template #icon>
                            <NIcon><ChevronDownOutline /></NIcon>
                        </template>
                    </NButton>
                </NDropdown>
            </NLayoutHeader>

            <NLayoutContent class="layout__content">
                <RouterView />
            </NLayoutContent>
        </NLayout>
    </NLayout>
</template>

<style scoped>
.layout {
    min-height: 100vh;
}

.layout__brand {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 56px;
    padding: 0 22px;
    font-weight: 600;
    font-size: 16px;
}

.layout__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 56px;
    padding: 0 24px;
}

.layout__role {
    margin-left: 4px;
}

.layout__content {
    padding: 24px;
    background: #f5f6f8;
    min-height: calc(100vh - 56px);
}
</style>
