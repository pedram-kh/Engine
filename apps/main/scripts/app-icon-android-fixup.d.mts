export const RES: string
export const ADAPTIVE_LAYER_PX: Record<
  'ldpi' | 'mdpi' | 'hdpi' | 'xhdpi' | 'xxhdpi' | 'xxxhdpi',
  number
>
export const LEGACY_ICON_PX: Record<
  'ldpi' | 'mdpi' | 'hdpi' | 'xhdpi' | 'xxhdpi' | 'xxxhdpi',
  number
>
export const LEGACY_SQUARE_RADIUS: number
export const ADAPTIVE_XML: string
export const BACKGROUND_COLOR_XML: string
export function withSplashBackground(stylesXml: string): string
export function fixAndroid(res?: string, sourceDir?: string): Promise<void>
