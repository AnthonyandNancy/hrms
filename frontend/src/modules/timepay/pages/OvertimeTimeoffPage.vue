<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('overtime/timeoff')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
