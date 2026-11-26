<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'ContractExpiryPage' })

const config = peoplePageConfig('hr/contracts/expiry')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
