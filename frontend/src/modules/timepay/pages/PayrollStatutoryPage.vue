<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('payroll/statutory')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
