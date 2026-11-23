import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { MaskedFieldValue } from '@/api/contracts'
import MaskedField from '@/components/MaskedField.vue'

const stubs = {
  'el-tag': { template: '<span><slot /></span>' },
  'el-button': { template: '<button><slot /></button>' },
  'el-icon': { template: '<i><slot /></i>' },
}

function field(overrides: Partial<MaskedFieldValue> = {}): MaskedFieldValue {
  return {
    display: '138****6621',
    masked: true,
    profile: 'P1_INTERNAL',
    revealable: true,
    exportable: true,
    ...overrides,
  }
}

describe('MaskedField', () => {
  it('never invents a clear value from a mask', () => {
    const wrapper = mount(MaskedField, {
      props: { label: '手机号', value: field(), canReveal: true },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('138****6621')
    expect(wrapper.text()).toContain('已遮罩')
    expect(wrapper.text()).not.toContain('1386621')
    expect(wrapper.text()).not.toContain('138 6621')
  })

  it('describes masking to assistive technology', () => {
    const wrapper = mount(MaskedField, {
      props: { label: '身份证号', value: field({ display: '310***********1227', profile: 'P2_SENSITIVE' }), canReveal: true },
      global: { stubs },
    })
    expect(wrapper.get('.masked-field__value').attributes('aria-label')).toContain('已遮罩')
  })

  it('exposes a guarded reveal action and emits the request', async () => {
    const wrapper = mount(MaskedField, {
      props: { label: '手机号', value: field(), canReveal: true },
      global: { stubs },
    })
    const buttons = wrapper.findAll('button')
    expect(buttons.length).toBe(1)
    await buttons[0]!.trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(wrapper.emitted('reveal')).toHaveLength(1)
    expect(wrapper.emitted('reveal')?.[0]?.[0]).toMatchObject({ profile: 'P1_INTERNAL' })
  })

  it('does not expose a reveal action without reveal permission', () => {
    const wrapper = mount(MaskedField, {
      props: { label: '手机号', value: field(), canReveal: false },
      global: { stubs },
    })
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('does not render masked treatment or reveal control for unmasked values', () => {
    const wrapper = mount(MaskedField, {
      props: {
        label: '工作邮箱',
        value: field({ display: 'ruotong.lin@demo.example', masked: false, profile: 'P0_PUBLIC' }),
        canReveal: true,
      },
      global: { stubs },
    })
    expect(wrapper.text()).not.toContain('已遮罩')
    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})
