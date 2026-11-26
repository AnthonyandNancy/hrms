<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('attendance/devices')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
