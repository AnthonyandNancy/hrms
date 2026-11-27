<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatBusinessDate, formatDateTimeInTimezone, formatMoney } from '@/utils/format'
import {
  acceptOffer,
  createOffer,
  listApplications,
  listOffers,
  listOrgUnits,
  type CandidateApplication,
  type Offer,
  type OrgUnitOption,
} from '../api'

const props = defineProps<{
  candidateId: number
  canManage: boolean
  canAccept: boolean
}>()

const emit = defineEmits<{
  changed: []
}>()

const offers = ref<Offer[]>([])
const applications = ref<CandidateApplication[]>([])
const orgUnits = ref<OrgUnitOption[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const acceptError = ref('')
const accepting = ref<Offer | null>(null)
const acceptForm = reactive({ orgUnitId: '', employeeNo: '', hireDate: '' })
const form = reactive({
  requisitionId: '',
  baseSalaryMinor: '',
  salaryCurrency: 'CNY',
  startDate: '',
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [rows, appRows, units] = await Promise.all([
      listOffers(props.candidateId),
      listApplications(props.candidateId),
      props.canAccept ? listOrgUnits() : Promise.resolve([] as OrgUnitOption[]),
    ])
    offers.value = rows
    applications.value = appRows
    orgUnits.value = units
  } catch (failure) {
    offers.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : 'Offer 信息加载失败。'
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  const requisitionId = Number(form.requisitionId)
  const salaryMinor = Number(form.baseSalaryMinor)
  if (!Number.isSafeInteger(requisitionId) || requisitionId <= 0) {
    formError.value = '请选择已批准的应聘需求。'
    return
  }
  if (!Number.isSafeInteger(salaryMinor) || salaryMinor < 0) {
    formError.value = '薪资金额必须是整数最小单位（分）。'
    return
  }
  if (form.startDate === '') {
    formError.value = '请填写预计入职日期。'
    return
  }

  saving.value = true
  try {
    await createOffer({
      candidateId: props.candidateId,
      requisitionId,
      baseSalaryMinor: salaryMinor,
      salaryCurrency: form.salaryCurrency.trim().toUpperCase(),
      startDate: form.startDate,
    })
    form.baseSalaryMinor = ''
    form.startDate = ''
    await load()
    emit('changed')
  } catch (failure) {
    formError.value = failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  } finally {
    saving.value = false
  }
}

function openAccept(offer: Offer): void {
  accepting.value = offer
  acceptError.value = ''
  acceptForm.orgUnitId = String(orgUnits.value[0]?.id ?? '')
  acceptForm.employeeNo = ''
  acceptForm.hireDate = offer.startDate
}

async function submitAccept(): Promise<void> {
  if (accepting.value === null) return
  acceptError.value = ''

  if (acceptForm.employeeNo.trim() === '') {
    acceptError.value = '请填写员工编号。'
    return
  }

  saving.value = true
  try {
    await acceptOffer(accepting.value.id, {
      orgUnitId: Number(acceptForm.orgUnitId),
      employeeNo: acceptForm.employeeNo,
      hireDate: acceptForm.hireDate === '' ? null : acceptForm.hireDate,
    })
    accepting.value = null
    await load()
    emit('changed')
  } catch (failure) {
    acceptError.value = failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-testid="candidate-tab-offers">
    <h3>Offer</h3>
    <p v-if="loading" class="panel-muted">正在加载 Offer。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="offers.length === 0" class="panel-muted">还没有 Offer；金额以整数最小单位展示，不会生成虚构金额。</p>
    <ul v-else class="panel-list">
      <li v-for="offer in offers" :key="offer.id" :data-testid="`offer-row-${offer.id}`">
        <strong>第 {{ offer.version }} 版 · {{ offer.statusLabel }}</strong>
        <span>{{ formatMoney(offer.baseSalaryMinor) }} · {{ offer.salaryCurrency }}</span>
        <span>预计入职 {{ formatBusinessDate(offer.startDate) }}</span>
        <a v-if="offer.caseId !== null" :href="`/workflow/cases/${offer.caseId}`">审批案件 #{{ offer.caseId }}</a>
        <a v-if="offer.onboardingCaseId !== null" :href="`/workflow/cases/${offer.onboardingCaseId}`" data-testid="offer-onboarding-case">
          入职案件 #{{ offer.onboardingCaseId }}
        </a>
        <span v-if="offer.acceptedAt !== null" class="panel-muted">接受时间 {{ formatDateTimeInTimezone(offer.acceptedAt) }}</span>
        <button
          v-if="canAccept && offer.status === 'APPROVED'"
          type="button"
          class="panel-primary"
          data-testid="offer-accept"
          @click="openAccept(offer)"
        >
          接受 Offer
        </button>
      </li>
    </ul>

    <form v-if="canManage" class="panel-form" @submit.prevent="submit">
      <h4>创建 Offer</h4>
      <label>
        <span>应聘需求</span>
        <el-select v-model="form.requisitionId" data-testid="offer-requisition">
          <el-option value="" label="请选择已批准需求" />
          <el-option
            v-for="application in applications"
            :key="application.id"
            :value="String(application.requisitionId)"
            :label="`${application.requisitionNo ?? application.requisitionId} · ${application.requisitionTitle ?? ''}`"
            :disabled="application.requisitionStatus !== 'APPROVED'"
          />
        </el-select>
      </label>
      <label>
        <span>基本薪资（最小单位）</span>
        <el-input v-model="form.baseSalaryMinor" data-testid="offer-salary-minor" inputmode="numeric" />
      </label>
      <label>
        <span>币种</span>
        <el-input v-model="form.salaryCurrency" data-testid="offer-currency" maxlength="3" />
      </label>
      <label>
        <span>预计入职日期</span>
        <el-input v-model="form.startDate" data-testid="offer-start-date" placeholder="2026-12-01" />
      </label>
      <p v-if="formError !== ''" class="panel-error" role="alert">{{ formError }}</p>
      <button type="submit" class="panel-primary" :disabled="saving" data-testid="offer-submit">
        {{ saving ? '提交中…' : '提交 Offer 审批' }}
      </button>
    </form>

    <div v-if="accepting !== null" class="panel-dialog" data-testid="offer-accept-dialog">
      <section role="dialog" aria-modal="true" aria-labelledby="offer-accept-title">
        <h4 id="offer-accept-title">接受第 {{ accepting.version }} 版 Offer</h4>
        <p>接受后会创建唯一的入职草稿；员工由入职流程创建，招聘不会直接建档。</p>
        <label>
          <span>目标组织单元</span>
          <el-select v-model="acceptForm.orgUnitId" data-testid="offer-accept-org-unit">
            <el-option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)" :label="`${unit.name}（${unit.code}）`" />
          </el-select>
        </label>
        <label>
          <span>员工编号</span>
          <el-input v-model="acceptForm.employeeNo" data-testid="offer-accept-employee-no" maxlength="64" />
        </label>
        <label>
          <span>入职日期</span>
          <el-input v-model="acceptForm.hireDate" data-testid="offer-accept-hire-date" />
        </label>
        <p v-if="acceptError !== ''" class="panel-error" role="alert">{{ acceptError }}</p>
        <footer>
          <button type="button" class="panel-secondary" :disabled="saving" @click="accepting = null">取消</button>
          <button type="button" class="panel-primary" :disabled="saving" data-testid="offer-accept-submit" @click="submitAccept">
            {{ saving ? '接受中…' : '确认接受' }}
          </button>
        </footer>
      </section>
    </div>
  </section>
</template>

<style scoped>
.panel-muted { color: #64748b; font-size: 12px; }
.panel-error { color: #b91c1c; font-size: 12px; }
.panel-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.panel-list li { display: flex; flex-direction: column; gap: 4px; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12.5px; }
.panel-list a { color: #1d4ed8; }
.panel-form { display: flex; flex-direction: column; gap: 10px; max-width: 460px; margin-top: 16px; }
.panel-form h4 { margin: 0; font-size: 13px; }
.panel-form label, .panel-dialog label { display: flex; flex-direction: column; gap: 4px; color: #475569; font-size: 12px; }
.panel-primary, .panel-secondary { align-self: flex-start; height: 32px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; cursor: pointer; }
.panel-primary { border-color: #1d4ed8; background: #1d4ed8; color: #fff; }
.panel-primary:disabled, .panel-secondary:disabled { opacity: 0.55; cursor: not-allowed; }
.panel-dialog { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; background: rgb(15 23 42 / 42%); }
.panel-dialog section { width: min(440px, 92vw); display: flex; flex-direction: column; gap: 10px; padding: 20px; border-radius: 12px; background: #fff; }
.panel-dialog h4 { margin: 0; font-size: 15px; }
.panel-dialog footer { display: flex; justify-content: flex-end; gap: 8px; }
</style>
