<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
</script>

<template>
  <div class="page">
    <header class="page-header">
      <div>
        <p class="page-header__breadcrumb">访问受限</p>
        <h1 class="page-header__title">你没有访问此页面的权限</h1>
        <p class="page-header__description">
          前端隐藏入口只是减少误操作；真正的权限由后端按能力、锚点、字段与业务日期独立校验。
          如果你需要访问，请向你的经理或权限管理员提交带业务目的和到期日的授权申请。
        </p>
      </div>
    </header>
    <section class="panel panel__body">
      <p class="small muted">请求路径：<span class="mono">{{ route.query.from ?? '—' }}</span></p>
      <div class="row" style="margin-top: 16px">
        <el-button type="primary" @click="router.push('/workspace')">返回我的工作台</el-button>
        <el-button @click="router.back()">返回上一页</el-button>
      </div>
    </section>
  </div>
</template>
