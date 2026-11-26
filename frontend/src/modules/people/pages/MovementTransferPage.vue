<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementTransferPage' })

const config = peoplePageConfig('hr/movements/transfer')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
