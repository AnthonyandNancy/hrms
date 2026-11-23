import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StatusBadge from '@/components/StatusBadge.vue'

const stubs = {
  'el-tag': true,
  'el-button': true,
  'el-icon': true,
}

describe('StatusBadge separates application and effect status', () => {
  it('renders application approval without claiming business effect', () => {
    const wrapper = mount(StatusBadge, {
      props: { kind: 'application', status: 'APPROVED' },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('已通过')
    expect(wrapper.text()).not.toContain('已生效')
    expect(wrapper.attributes('data-status-kind')).toBe('application')
    expect(wrapper.attributes('data-status-value')).toBe('APPROVED')
  })

  it('renders scheduled effect distinctly from approved application', () => {
    const wrapper = mount(StatusBadge, {
      props: { kind: 'effect', status: 'SCHEDULED' },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('待生效')
    expect(wrapper.attributes('data-status-kind')).toBe('effect')
    expect(wrapper.classes()).toContain('status-badge--info')
  })

  it('renders effect failure with a danger tone and non-color symbol', () => {
    const wrapper = mount(StatusBadge, {
      props: { kind: 'effect', status: 'FAILED' },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('生效失败')
    expect(wrapper.text()).toContain('!')
    expect(wrapper.classes()).toContain('status-badge--danger')
  })

  it('shows label prefix when both lanes are rendered together', () => {
    const application = mount(StatusBadge, {
      props: { kind: 'application', status: 'APPROVED', showLabelPrefix: true },
      global: { stubs },
    })
    const effect = mount(StatusBadge, {
      props: { kind: 'effect', status: 'SCHEDULED', showLabelPrefix: true },
      global: { stubs },
    })
    expect(application.text()).toContain('申请状态')
    expect(effect.text()).toContain('生效状态')
  })

  it('communicates no-effect cases instead of displaying a green completed badge', () => {
    const wrapper = mount(StatusBadge, {
      props: { kind: 'effect', status: null, showLabelPrefix: true },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('无生效要求')
    expect(wrapper.classes()).not.toContain('status-badge--success')
  })
})
