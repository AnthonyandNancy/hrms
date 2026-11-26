<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('payroll/reviews')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
