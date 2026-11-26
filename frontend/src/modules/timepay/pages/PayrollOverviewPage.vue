<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('payroll/index')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
