<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'ContractLedgerPage' })

const config = peoplePageConfig('hr/contracts/index')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
