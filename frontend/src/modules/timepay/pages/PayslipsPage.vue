<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('payroll/payslips')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
