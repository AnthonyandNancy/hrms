<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('travel/approval')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
