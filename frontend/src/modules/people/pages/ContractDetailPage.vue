<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'ContractDetailPage' })

const config = peoplePageConfig('hr/contracts/by-id')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
