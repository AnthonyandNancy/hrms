import { mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import { defineComponent } from 'vue'
import { describe, expect, it } from 'vitest'
import PayrollActionBar from '@/modules/payroll/components/PayrollActionBar.vue'

const OverlayStub = defineComponent({
  inheritAttrs: false,
  props: { modelValue: Boolean, title: String },
  emits: ['update:modelValue'],
  template: `<div v-if="modelValue" v-bind="$attrs" class="overlay-stub" role="dialog">
    <h2 v-if="title">{{ title }}</h2>
    <slot />
    <slot name="footer" />
  </div>`,
})

describe('payroll object allowed actions', () => {
  it('uses the server action list instead of local capability/status inference', () => {
    const wrapper = mount(PayrollActionBar, {
      props: {
        status: 'REVIEWED',
        period: '2026-11',
        capabilities: [
          'payroll.run.prepare',
          'payroll.run.review',
          'payroll.run.approve',
          'payroll.run.publish',
          'payroll.run.close',
        ],
        allowedActions: ['payroll.run.detail.approve'],
      },
      global: { plugins: [ElementPlus], stubs: { ElDialog: OverlayStub, teleport: true } },
    })

    expect(wrapper.get('[data-testid="payroll-action-approve"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-testid="payroll-action-review"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="payroll-action-publish"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="payroll-action-reason-publish"]').text()).toContain('当前状态或授权不允许')
    expect(wrapper.text()).not.toContain('payroll.run.approve')
    expect(wrapper.text()).not.toContain('payroll.run.publish')
  })
})
