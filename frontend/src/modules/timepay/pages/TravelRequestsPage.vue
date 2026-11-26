<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('travel/requests')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
