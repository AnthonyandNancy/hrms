<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('attendance/schedules')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
