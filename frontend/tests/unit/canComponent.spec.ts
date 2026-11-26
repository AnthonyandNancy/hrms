import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import ActionGate from '@/components/ActionGate.vue'
import Can from '@/components/Can.vue'
import { useSessionStore } from '@/stores/session'

function sessionWith(capabilities: string[]): void {
  setActivePinia(createPinia())
  useSessionStore().$patch({ status: 'authenticated', capabilities })
}

describe('Can', () => {
  it('hides the default slot and its button without the capability', () => {
    sessionWith([])
    const wrapper = mount(Can, {
      props: { capability: 'people.employee.manage' },
      slots: { default: '<button data-testid="create">新增员工</button>' },
    })

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
  })

  it('renders the default slot with the capability', () => {
    sessionWith(['people.employee.manage'])
    const wrapper = mount(Can, {
      props: { capability: 'people.employee.manage' },
      slots: { default: '<button data-testid="create">新增员工</button>' },
    })

    expect(wrapper.find('[data-testid="create"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('新增员工')
  })

  it('renders only the fallback slot when denied', () => {
    sessionWith([])
    const wrapper = mount(Can, {
      props: { capability: 'people.employee.manage' },
      slots: {
        default: '<button>新增员工</button>',
        fallback: '<span data-testid="fallback">只读</span>',
      },
    })

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('[data-testid="fallback"]').text()).toBe('只读')
  })
})

describe('ActionGate', () => {
  it('behaves like Can in the default mode', () => {
    sessionWith([])
    const denied = mount(ActionGate, {
      props: { capability: 'people.employee.manage' },
      slots: { default: '<button>新增员工</button>' },
    })
    expect(denied.find('button').exists()).toBe(false)

    sessionWith(['people.employee.manage'])
    const allowed = mount(ActionGate, {
      props: { capability: 'people.employee.manage' },
      slots: { default: '<button>新增员工</button>' },
    })
    expect(allowed.find('button').exists()).toBe(true)
  })

  it('renders explanatory text and never a button in disable mode', () => {
    sessionWith([])
    const wrapper = mount(ActionGate, {
      props: { capability: 'people.employee.manage', mode: 'disable' },
      slots: { default: '<button>新增员工</button>' },
    })

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.text()).toContain('缺少动作能力 people.employee.manage')
  })
})
