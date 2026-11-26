<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'ContractEditorPage' })

const config = peoplePageConfig('hr/contracts/editor')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
