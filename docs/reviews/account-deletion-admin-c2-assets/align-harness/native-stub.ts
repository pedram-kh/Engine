export function isNativePlatform(): boolean {
  return !location.search.includes('web')
}
export function nativePlatform(): 'ios' | null {
  return isNativePlatform() ? 'ios' : null
}
