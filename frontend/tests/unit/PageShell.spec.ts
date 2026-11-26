import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PageShell from '@/shared/ui/PageShell.vue'

describe('PageShell', () => {
  it('renders title, primary action slot and body', () => {
    const wrapper = mount(PageShell, {
      props: { title: '员工列表' },
      slots: { actions: '<button>新增员工</button>', default: '<p>列表内容</p>' },
    })
    expect(wrapper.text()).toContain('员工列表')
    expect(wrapper.text()).toContain('新增员工')
    expect(wrapper.text()).toContain('列表内容')
  })
})
