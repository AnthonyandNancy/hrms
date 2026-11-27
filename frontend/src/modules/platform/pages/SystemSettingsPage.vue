<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import SettingsCompanyTab from '../components/SettingsCompanyTab.vue'
import SettingsCalendarTab from '../components/SettingsCalendarTab.vue'
import SettingsDictionaryTab from '../components/SettingsDictionaryTab.vue'
import SettingsParameterTab from '../components/SettingsParameterTab.vue'
import SettingsFileTab from '../components/SettingsFileTab.vue'

type SettingsTab = 'company' | 'calendar' | 'dictionary' | 'parameter' | 'file'

const TABS: ReadonlyArray<{ name: SettingsTab; label: string }> = [
  { name: 'company', label: '公司' },
  { name: 'calendar', label: '日历' },
  { name: 'dictionary', label: '字典' },
  { name: 'parameter', label: '参数' },
  { name: 'file', label: '文件' },
]

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canOpen = computed(
  () =>
    session.can('system.settings.manage') ||
    session.can('time.calendar.read') ||
    session.can('system.dictionary.manage') ||
    session.can('system.parameter.manage') ||
    session.can('platform.feature.manage'),
)

function isTab(value: unknown): value is SettingsTab {
  return typeof value === 'string' && TABS.some((tab) => tab.name === value)
}

const activeTab = ref<SettingsTab>(isTab(route.query.tab) ? route.query.tab : 'company')

watch(
  () => route.query.tab,
  (value) => {
    if (isTab(value)) activeTab.value = value
  },
)

function selectTab(name: string | number): void {
  if (!isTab(name)) return
  activeTab.value = name
  void router.replace({ query: { ...route.query, tab: name } })
}

const visibleTabs = computed(() => TABS.filter((tab) => {
  if (tab.name === 'company') return session.can('system.settings.manage')
  if (tab.name === 'calendar') return session.can('time.calendar.read')
  if (tab.name === 'dictionary') return session.can('system.dictionary.manage')
  if (tab.name === 'parameter') {
    return session.can('system.parameter.manage') || session.can('platform.feature.manage')
  }
  return true
}))

const currentTab = computed<SettingsTab>(() =>
  visibleTabs.value.some((tab) => tab.name === activeTab.value)
    ? activeTab.value
    : visibleTabs.value[0]?.name ?? 'company',
)
</script>

<template>
  <PageShell
    data-testid="system-settings-page"
    title="公司设置"
    description="一个入口维护公司资料、工作日历、字典、参数与员工文件；每块数据由唯一后端模块写入。"
  >
    <section v-if="!canOpen" class="panel" data-testid="settings-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权访问系统设置</h2>
        <p class="muted">当前账号没有系统设置查看能力。请联系系统管理员授予相应能力。</p>
      </div>
    </section>

    <template v-else>
      <el-tabs
        :model-value="currentTab"
        data-testid="settings-tabs"
        class="settings-tabs"
        @tab-change="selectTab"
      >
        <el-tab-pane
          v-for="tab in visibleTabs"
          :key="tab.name"
          :label="tab.label"
          :name="tab.name"
        />
      </el-tabs>

      <SettingsCompanyTab v-if="currentTab === 'company'" />
      <SettingsCalendarTab v-else-if="currentTab === 'calendar'" />
      <SettingsDictionaryTab v-else-if="currentTab === 'dictionary'" />
      <SettingsParameterTab v-else-if="currentTab === 'parameter'" />
      <SettingsFileTab v-else />
    </template>
  </PageShell>
</template>

<style scoped>
.settings-tabs {
  padding: 0 4px;
}
</style>
