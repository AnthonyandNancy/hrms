<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('leave/balances')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
