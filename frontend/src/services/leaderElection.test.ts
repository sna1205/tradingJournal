import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LeaderElection } from '@/services/leaderElection'

type MessageHandler = ((event: MessageEvent) => void) | null

class MockBroadcastChannel {
  private static channelsByName = new Map<string, Set<MockBroadcastChannel>>()
  readonly name: string
  onmessage: MessageHandler = null

  constructor(name: string) {
    this.name = name
    const bucket = MockBroadcastChannel.channelsByName.get(name) ?? new Set<MockBroadcastChannel>()
    bucket.add(this)
    MockBroadcastChannel.channelsByName.set(name, bucket)
  }

  postMessage(data: unknown): void {
    const bucket = MockBroadcastChannel.channelsByName.get(this.name)
    if (!bucket) return
    for (const peer of bucket) {
      if (peer === this) continue
      peer.onmessage?.({ data } as MessageEvent)
    }
  }

  close(): void {
    const bucket = MockBroadcastChannel.channelsByName.get(this.name)
    if (!bucket) return
    bucket.delete(this)
    if (bucket.size <= 0) {
      MockBroadcastChannel.channelsByName.delete(this.name)
    }
  }

  static reset(): void {
    MockBroadcastChannel.channelsByName.clear()
  }
}

describe('leader election', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    MockBroadcastChannel.reset()
    Object.defineProperty(globalThis, 'BroadcastChannel', {
      value: MockBroadcastChannel,
      configurable: true,
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps one leader across three tabs and re-elects when leader hides', () => {
    let visibleA = true
    let visibleB = true
    let visibleC = true

    const a = new LeaderElection({
      tabId: 'a',
      channelName: 'tj:leader',
      heartbeatMs: 2000,
      staleMs: 5000,
      isVisible: () => visibleA,
      createChannel: (name) => new BroadcastChannel(name),
    })
    const b = new LeaderElection({
      tabId: 'b',
      channelName: 'tj:leader',
      heartbeatMs: 2000,
      staleMs: 5000,
      isVisible: () => visibleB,
      createChannel: (name) => new BroadcastChannel(name),
    })
    const c = new LeaderElection({
      tabId: 'c',
      channelName: 'tj:leader',
      heartbeatMs: 2000,
      staleMs: 5000,
      isVisible: () => visibleC,
      createChannel: (name) => new BroadcastChannel(name),
    })

    a.start()
    b.start()
    c.start()
    vi.advanceTimersByTime(2100)

    expect([a.isLeader(), b.isLeader(), c.isLeader()].filter(Boolean)).toHaveLength(1)
    expect(a.isLeader()).toBe(true)

    visibleA = false
    a.refreshVisibility()
    vi.advanceTimersByTime(1)

    expect(a.isLeader()).toBe(false)
    expect([a.isLeader(), b.isLeader(), c.isLeader()].filter(Boolean)).toHaveLength(1)
    expect(b.isLeader()).toBe(true)

    a.stop()
    b.stop()
    c.stop()
  })

  it('responds to mocked document.hidden visibility changes', () => {
    let hidden = false
    const visibilityListeners = new Set<() => void>()
    Object.defineProperty(globalThis, 'document', {
      value: {
        get hidden() {
          return hidden
        },
        addEventListener: (event: string, listener: () => void) => {
          if (event === 'visibilitychange') {
            visibilityListeners.add(listener)
          }
        },
        removeEventListener: (event: string, listener: () => void) => {
          if (event === 'visibilitychange') {
            visibilityListeners.delete(listener)
          }
        },
      },
      configurable: true,
    })

    const election = new LeaderElection({
      tabId: 'solo',
      channelName: 'tj:leader',
      heartbeatMs: 2000,
      staleMs: 5000,
    })
    election.start()
    expect(election.isLeader()).toBe(true)

    hidden = true
    for (const listener of visibilityListeners) {
      listener()
    }
    expect(election.isLeader()).toBe(false)

    hidden = false
    for (const listener of visibilityListeners) {
      listener()
    }
    expect(election.isLeader()).toBe(true)

    election.stop()
  })
})
