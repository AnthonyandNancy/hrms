<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('payroll/employee-plans')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
