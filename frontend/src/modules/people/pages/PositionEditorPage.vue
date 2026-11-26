<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'PositionEditorPage' })

const config = peoplePageConfig('hr/positions/editor')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
