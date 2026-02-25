import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    layout?: 'default' | 'auth'
    guestOnly?: boolean
    public?: boolean
  }
}
