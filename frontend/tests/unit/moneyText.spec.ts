import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MoneyText from '@/components/MoneyText.vue'

describe('MoneyText rendering failures', () => {
  it('renders an em dash instead of throwing when cents is missing', () => {
    const wrapper = mount(MoneyText, {
      props: { cents: undefined as unknown as number },
    })

    expect(wrapper.text()).toContain('—')
    expect(wrapper.text()).not.toContain('Money must be')
  })

  it('renders an em dash when cents is null', () => {
    const wrapper = mount(MoneyText, {
      props: { cents: null },
    })

    expect(wrapper.text()).toContain('—')
  })

  it('renders an em dash for non-finite and non-integer values', () => {
    const nonFinite = mount(MoneyText, {
      props: { cents: Number.POSITIVE_INFINITY },
    })
    const nonInteger = mount(MoneyText, {
      props: { cents: 12.5 },
    })

    expect(nonFinite.text()).toContain('—')
    expect(nonInteger.text()).toContain('—')
  })

  it('still renders a valid integer amount', () => {
    const wrapper = mount(MoneyText, {
      props: { cents: -500 },
    })

    expect(wrapper.text()).toContain('-¥5.00')
    expect(wrapper.classes()).toContain('money-text--negative')
  })
})
