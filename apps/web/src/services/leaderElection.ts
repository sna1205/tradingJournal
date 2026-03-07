type LeaderMessage =
  | {
    type: 'heartbeat'
    tab_id: string
    visible: boolean
    ts: number
  }
  | {
    type: 'resign'
    tab_id: string
    ts: number
  }

interface LeaderPeerState {
  visible: boolean
  last_seen_ms: number
}

interface LeaderElectionOptions {
  channelName?: string
  heartbeatMs?: number
  staleMs?: number
  tabId?: string
  now?: () => number
  isVisible?: () => boolean
  createChannel?: (name: string) => BroadcastChannel | null
}

type LeaderListener = (isLeader: boolean) => void

const DEFAULT_CHANNEL_NAME = 'tj:leader'
const DEFAULT_HEARTBEAT_MS = 2000

export class LeaderElection {
  private readonly channelName: string
  private readonly heartbeatMs: number
  private readonly staleMs: number
  private readonly tabId: string
  private readonly now: () => number
  private readonly isVisibleFn: () => boolean
  private readonly createChannelFn: (name: string) => BroadcastChannel | null

  private readonly peers = new Map<string, LeaderPeerState>()
  private readonly listeners = new Set<LeaderListener>()
  private channel: BroadcastChannel | null = null
  private heartbeatHandle: ReturnType<typeof setInterval> | null = null
  private started = false
  private leader = false
  private unbindVisibility: (() => void) | null = null
  private unbindBeforeUnload: (() => void) | null = null

  constructor(options: LeaderElectionOptions = {}) {
    this.channelName = options.channelName ?? DEFAULT_CHANNEL_NAME
    this.heartbeatMs = options.heartbeatMs ?? DEFAULT_HEARTBEAT_MS
    this.staleMs = options.staleMs ?? (this.heartbeatMs * 3)
    this.tabId = options.tabId ?? makeTabId()
    this.now = options.now ?? (() => Date.now())
    this.isVisibleFn = options.isVisible ?? defaultIsVisible
    this.createChannelFn = options.createChannel ?? defaultCreateChannel
  }

  start(): void {
    if (this.started) return
    this.started = true

    this.channel = this.createChannelFn(this.channelName)
    if (this.channel) {
      this.channel.onmessage = (event: MessageEvent<LeaderMessage>) => {
        this.onMessage(event.data)
      }
    }

    this.unbindVisibility = bindVisibilityChange(() => {
      this.refreshVisibility()
    })
    this.unbindBeforeUnload = bindBeforeUnload(() => {
      this.stop()
    })

    this.refreshVisibility()
  }

  stop(): void {
    if (!this.started) return
    this.started = false

    this.stopHeartbeat()
    this.publish({
      type: 'resign',
      tab_id: this.tabId,
      ts: this.now(),
    })

    this.channel?.close()
    this.channel = null

    this.unbindVisibility?.()
    this.unbindVisibility = null
    this.unbindBeforeUnload?.()
    this.unbindBeforeUnload = null

    this.peers.clear()
    this.updateLeader(false)
  }

  isLeader(): boolean {
    return this.leader
  }

  subscribe(listener: LeaderListener): () => void {
    this.listeners.add(listener)
    listener(this.leader)
    return () => {
      this.listeners.delete(listener)
    }
  }

  refreshVisibility(): void {
    if (!this.started) return

    if (this.isVisibleFn()) {
      this.startHeartbeat()
      this.sendHeartbeat()
      return
    }

    this.stopHeartbeat()
    this.publish({
      type: 'resign',
      tab_id: this.tabId,
      ts: this.now(),
    })
    this.cleanupStalePeers()
    this.recomputeLeader()
  }

  private startHeartbeat(): void {
    if (this.heartbeatHandle !== null) return
    this.heartbeatHandle = globalThis.setInterval(() => {
      this.sendHeartbeat()
    }, this.heartbeatMs)
  }

  private stopHeartbeat(): void {
    if (this.heartbeatHandle === null) return
    globalThis.clearInterval(this.heartbeatHandle)
    this.heartbeatHandle = null
  }

  private sendHeartbeat(): void {
    if (!this.started || !this.isVisibleFn()) return

    this.publish({
      type: 'heartbeat',
      tab_id: this.tabId,
      visible: true,
      ts: this.now(),
    })
    this.cleanupStalePeers()
    this.recomputeLeader()
  }

  private publish(message: LeaderMessage): void {
    this.channel?.postMessage(message)
  }

  private onMessage(message: LeaderMessage): void {
    if (!message || message.tab_id === this.tabId) return

    if (message.type === 'resign') {
      this.peers.delete(message.tab_id)
      this.cleanupStalePeers()
      this.recomputeLeader()
      return
    }

    if (message.type === 'heartbeat') {
      if (!message.visible) {
        this.peers.delete(message.tab_id)
      } else {
        this.peers.set(message.tab_id, {
          visible: true,
          last_seen_ms: this.now(),
        })
      }
      this.cleanupStalePeers()
      this.recomputeLeader()
    }
  }

  private cleanupStalePeers(): void {
    const threshold = this.now() - this.staleMs
    for (const [peerId, state] of this.peers.entries()) {
      if (state.last_seen_ms < threshold) {
        this.peers.delete(peerId)
      }
    }
  }

  private recomputeLeader(): void {
    if (!this.started) {
      this.updateLeader(false)
      return
    }

    if (!this.isVisibleFn()) {
      this.updateLeader(false)
      return
    }

    const candidates = [this.tabId]
    for (const [peerId, state] of this.peers.entries()) {
      if (!state.visible) continue
      candidates.push(peerId)
    }

    candidates.sort((left, right) => left.localeCompare(right))
    this.updateLeader(candidates[0] === this.tabId)
  }

  private updateLeader(value: boolean): void {
    if (this.leader === value) return
    this.leader = value
    for (const listener of this.listeners) {
      listener(value)
    }
  }
}

function defaultCreateChannel(name: string): BroadcastChannel | null {
  try {
    if (typeof BroadcastChannel === 'undefined') return null
    return new BroadcastChannel(name)
  } catch {
    return null
  }
}

function bindVisibilityChange(handler: () => void): (() => void) | null {
  if (typeof document === 'undefined' || typeof document.addEventListener !== 'function') {
    return null
  }
  document.addEventListener('visibilitychange', handler)
  return () => {
    document.removeEventListener('visibilitychange', handler)
  }
}

function bindBeforeUnload(handler: () => void): (() => void) | null {
  if (typeof window === 'undefined' || typeof window.addEventListener !== 'function') {
    return null
  }
  window.addEventListener('beforeunload', handler)
  return () => {
    window.removeEventListener('beforeunload', handler)
  }
}

function defaultIsVisible(): boolean {
  if (typeof document === 'undefined') return true
  return document.hidden !== true
}

function makeTabId(): string {
  const random = Math.random().toString(16).slice(2, 10)
  return `tab-${Date.now()}-${random}`
}

export const dashboardLeaderElection = new LeaderElection({
  channelName: DEFAULT_CHANNEL_NAME,
  heartbeatMs: DEFAULT_HEARTBEAT_MS,
})
