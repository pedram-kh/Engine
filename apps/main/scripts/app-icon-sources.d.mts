export const APP_ROOT: string
export const LOGO_SVG: string
export const OUT_DIR: string
export const CANVAS: string
export const MARK_CROP: { x: number; y: number; size: number }
export const ICON_SIZE: number
export const SPLASH_SIZE: number
export const IOS_MARK_SHARE: number
export const ADAPTIVE_SAFE_ZONE: number
export const SPLASH_MARK_SHARE: number
export function resolveLogoSvg(svg: string): string
export function markSvg(svg: string, px: number): string
export function renderMark(svg: string, px: number): Promise<Buffer>
export function maxRadiusShare(png: Buffer, alphaThreshold?: number): Promise<number>
export function adaptiveMarkPx(): number
export function buildSources(outDir?: string): Promise<{
  iosPx: number
  adaptivePx: number
  splashPx: number
  files: string[]
}>
export const ADAPTIVE_WINDOW_SHARE: number
