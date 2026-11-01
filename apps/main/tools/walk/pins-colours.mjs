/**
 * The agency theme colours as computed `rgb()` strings, per theme, for the pin runner.
 * These are the values in `src/modules/agency/theme/agencyThemes.ts`, restated so a pin
 * fails if the stylesheet reads the wrong theme key.
 */
export const RGB = {
  dark: {
    canvas: 'rgb(11, 11, 12)',
    surface: 'rgb(27, 27, 29)',
    raised: 'rgb(35, 35, 38)',
    outline: 'rgb(58, 58, 64)',
    onSurface: 'rgb(237, 237, 237)',
    onSurfaceVariant: 'rgb(160, 160, 168)',
    primary: 'rgb(62, 207, 158)',
    onPrimary: 'rgb(11, 11, 12)',
    primaryText: 'rgb(62, 207, 158)',
    primaryContainer: 'rgb(22, 58, 48)',
    error: 'rgb(240, 80, 110)',
    errorText: 'rgb(243, 115, 139)',
    tertiary: 'rgb(138, 138, 146)',
    white: 'rgb(255, 255, 255)',
    border: 'rgb(46, 46, 51)',
    info: 'rgb(76, 141, 255)',
    infoText: 'rgb(112, 164, 255)',
    overlayShadow: 'rgba(0, 0, 0, 0.55) 0px 12px 32px 0px',
  },
  light: {
    canvas: 'rgb(244, 244, 245)',
    surface: 'rgb(255, 255, 255)',
    raised: 'rgb(244, 244, 245)',
    outline: 'rgb(212, 212, 216)',
    onSurface: 'rgb(24, 24, 27)',
    onSurfaceVariant: 'rgb(82, 82, 91)',
    primary: 'rgb(62, 207, 158)',
    onPrimary: 'rgb(11, 11, 12)',
    primaryText: 'rgb(11, 122, 90)',
    primaryContainer: 'rgb(228, 248, 241)',
    error: 'rgb(240, 80, 110)',
    errorText: 'rgb(186, 41, 70)',
    tertiary: 'rgb(107, 107, 116)',
    white: 'rgb(255, 255, 255)',
    border: 'rgb(228, 228, 231)',
    info: 'rgb(76, 141, 255)',
    infoText: 'rgb(36, 87, 197)',
    overlayShadow: 'rgba(24, 24, 27, 0.14) 0px 12px 32px 0px',
  },
}
