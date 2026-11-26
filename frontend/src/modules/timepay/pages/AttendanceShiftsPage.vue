<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('attendance/shifts')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
