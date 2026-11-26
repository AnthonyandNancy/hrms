<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'ContractChangesPage' })

const config = peoplePageConfig('hr/contracts/changes')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
