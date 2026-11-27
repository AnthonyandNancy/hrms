<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatBusinessDate, formatMoney } from '@/utils/format'
import {
  convertCandidate,
  listOffers,
  listOrgUnits,
  serverReason,
  type Candidate,
  type CandidateConversion,
  type Offer,
  type OrgUnitOption,
} from '../api'

const props = defineProps<{
  candidate: Candidate
  canConvert: boolean
}>()

const offers = ref<Offer[]>([])
const orgUnits = ref<OrgUnitOption[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const formRequestId = ref<string | null>(null)
const submitting = ref(false)
const result = ref<CandidateConversion | null>(null)
const form = reactive({ orgUnitId: '', employeeNo: '', hireDate: '' })

const latestAcceptedOffer = computed(() => offers.value.find((offer) => offer.status === 'ACCEPTED') ?? null)
const onboardingHref = computed(() => {
  const caseId = result.value?.onboardingCaseId ?? latestAcceptedOffer.value?.onboardingCaseId ?? props.candidate.onboardingCaseId
  return caseId === null || caseId === undefined ? null : `/workflow/cases/${caseId}`
})
const employeeHref = computed(() => {
  const employmentId = result.value?.employmentId ?? props.candidate.convertedEmploymentId
  return employmentId === null || employmentId === undefined ? null : `/hr/employees/${employmentId}`
})
const blockedReason = computed(() => {
  if (!props.canConvert) return '当前账号缺少 recruitment.candidate.convert，不能执行录用转入职。'
  if (props.candidate.convertedEmploymentId !== null) return '该候选人已完成转入职。'
  if (latestAcceptedOffer.value === null) return '只有已接受 Offer 的候选人可以转入职。'
  return ''
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [offerRows, units] = await Promise.all([
      listOffers(props.candidate.id),
      props.canConvert ? listOrgUnits() : Promise.resolve([] as OrgUnitOption[]),
    ])
    offers.value = offerRows
    orgUnits.value = units
    if (form.orgUnitId === '' && units.length > 0) form.orgUnitId = String(units[0]?.id ?? '')
    if (form.hireDate === '' && offerRows[0] !== undefined) form.hireDate = offerRows[0].startDate
  } catch (failure) {
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '转入职信息加载失败。'
  } finally {
    loading.value = false
  }
}

function chineseConvertError(failure: unknown): string {
  switch (serverReason(failure)) {
    case 'EMPLOYEE_NO_DUPLICATE':
      return '员工编号已存在，请更换员工编号后重试；表单输入会保留。'
    case 'CANDIDATE_NOT_ACCEPTED':
      return '该候选人还没有已接受的 Offer，不能转入职。'
    case 'REQUISITION_CLOSED':
      return '招聘需求已关闭，不能继续录用。'
    case 'IDEMPOTENCY_PAYLOAD_MISMATCH':
      return '本次操作与之前的幂等请求不一致，请刷新后重新发起。'
    default:
      return failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  formRequestId.value = null
  if (!props.canConvert) {
    formError.value = '当前账号没有录用转入职能力。'
    return
  }
  if (form.employeeNo.trim() === '') {
    formError.value = '请填写员工编号。'
    return
  }
  if (!/^\d+$/.test(form.orgUnitId) || Number(form.orgUnitId) <= 0) {
    formError.value = '请选择目标组织单元。'
    return
  }

  submitting.value = true
  try {
    result.value = await convertCandidate(props.candidate.id, {
      orgUnitId: Number(form.orgUnitId),
      employeeNo: form.employeeNo,
      hireDate: form.hireDate === '' ? null : form.hireDate,
    })
  } catch (failure) {
    formError.value = chineseConvertError(failure)
    formRequestId.value = failure instanceof ApiError ? failure.requestId : null
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-testid="candidate-tab-conversion">
    <h3>录用转入职</h3>
    <p v-if="loading" class="panel-muted">正在加载转入职信息。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>

    <section v-if="result !== null" class="panel-result" role="status">
      <template v-if="result.status === 'PENDING_ONBOARDING'">
        <h4>待入职</h4>
        <p>入职草稿已建立，员工尚未创建。完成 Lifecycle 入职审批后再回来登记员工。</p>
        <a v-if="onboardingHref !== null" :href="onboardingHref" data-testid="conversion-onboarding-link">查看入职案件</a>
      </template>
      <template v-else>
        <h4>已转入职</h4>
        <p>员工编号 {{ result.employeeNo }}，任职编号 {{ result.employmentId }}。重复提交会返回同一员工，不会生成第二个员工。</p>
        <a v-if="employeeHref !== null" :href="employeeHref" data-testid="conversion-employee-link">查看员工档案</a>
      </template>
    </section>

    <p v-if="latestAcceptedOffer !== null" class="panel-muted">
      最近已接受 Offer：{{ formatMoney(latestAcceptedOffer.baseSalaryMinor) }} · {{ latestAcceptedOffer.salaryCurrency }} ·
      预计 {{ formatBusinessDate(latestAcceptedOffer.startDate) }}
    </p>
    <p v-if="blockedReason !== ''" class="panel-muted">{{ blockedReason }}</p>

    <form class="panel-form" @submit.prevent="submit">
      <label>
        <span>目标组织单元</span>
        <el-select v-model="form.orgUnitId" data-testid="conversion-org-unit">
          <el-option value="" disabled label="请选择组织单元" />
          <el-option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)" :label="`${unit.name}（${unit.code}）`" />
        </el-select>
      </label>
      <label>
        <span>员工编号</span>
        <el-input v-model="form.employeeNo" data-testid="conversion-employee-no" maxlength="64" />
      </label>
      <label>
        <span>入职日期</span>
        <el-input v-model="form.hireDate" data-testid="conversion-hire-date" placeholder="2026-12-01" />
      </label>
      <p v-if="formError !== ''" class="panel-error" role="alert" data-testid="conversion-error">
        {{ formError }}
        <span v-if="formRequestId !== null" data-testid="conversion-request-id">请求编号：{{ formRequestId }}</span>
      </p>
      <button
        type="button"
        class="panel-primary"
        :disabled="submitting || !canConvert"
        data-testid="conversion-submit"
        @click="submit"
      >
        {{ submitting ? '转入职中…' : '确认转入职' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.panel-muted { color: #64748b; font-size: 12px; }
.panel-error { color: #b91c1c; font-size: 12px; }
.panel-form { display: flex; flex-direction: column; gap: 10px; max-width: 460px; margin-top: 16px; }
.panel-form label { display: flex; flex-direction: column; gap: 4px; color: #475569; font-size: 12px; }
.panel-primary { align-self: flex-start; height: 32px; padding: 0 12px; border: 1px solid #1d4ed8; border-radius: 7px; background: #1d4ed8; color: #fff; cursor: pointer; }
.panel-primary:disabled { opacity: 0.55; cursor: not-allowed; }
.panel-result { padding: 12px; border: 1px solid #a7f3d0; border-radius: 8px; background: #ecfdf5; color: #065f46; font-size: 12.5px; }
.panel-result h4 { margin: 0 0 6px; font-size: 14px; }
.panel-result a { color: #047857; font-weight: 600; }
</style>
