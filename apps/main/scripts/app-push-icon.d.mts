export const PUSH_ICON_NAME: string
export const PUSH_ICON_DP: number
export const PUSH_MARK_DP: number
export const PUSH_DENSITY: Record<'mdpi' | 'hdpi' | 'xhdpi' | 'xxhdpi' | 'xxxhdpi', number>
export const RES: string
export function pushIconPng(svg: string, density: number): Promise<Buffer>
export function buildPushIcons(res?: string): Promise<string[]>
export const GLOW_CEILING: number
export const SOLID_FLOOR: number
export function shapeAlpha(alpha: number): number
