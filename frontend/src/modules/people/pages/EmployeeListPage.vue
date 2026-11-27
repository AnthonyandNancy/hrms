<script setup lang="ts">
/**
 * Compatibility entry point.
 *
 * The employee list workspace moved to `modules/people/employees` during the
 * Volume 3 rebuild. The registry key is intentionally unchanged so the router,
 * page-component audit and deep links keep resolving through the same module
 * manifest; this file must stay a thin re-export, never a second implementation.
 */
import EmployeeListPage from '../employees/pages/EmployeeListPage.vue'
</script>

<template>
  <EmployeeListPage />
</template>
