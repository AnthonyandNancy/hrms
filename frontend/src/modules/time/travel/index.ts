/**
 * M12 travel module public surface.
 *
 * The router resolves `travel/index` through the Time workspace barrel. This
 * module owns its page, API boundary and components; it never imports the
 * legacy timepay travel pages.
 */
export { default as TravelWorkspacePage } from './pages/TravelWorkspacePage.vue'
export * from './api'
