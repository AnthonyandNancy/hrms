<script setup lang="ts">
import { TimePayrollPage, timePayDefinition } from '../legacyBridge'

const definition = timePayDefinition('leave/types')
</script>

<template>
  <TimePayrollPage :definition="definition" />
</template>
