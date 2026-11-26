<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('attendance/corrections')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
