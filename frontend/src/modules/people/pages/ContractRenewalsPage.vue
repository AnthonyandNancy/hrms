<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'ContractRenewalsPage' })

const config = peoplePageConfig('hr/contracts/renewals')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
