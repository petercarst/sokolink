/**
 * Tailwind is UTILITIES ONLY in this project. Bootstrap 5 owns components.
 *
 *  prefix: 'tw-'          every utility is tw-*, so a Tailwind class can never
 *                         collide with a Bootstrap class name.
 *  preflight: false       CRITICAL. Tailwind's CSS reset would otherwise fight
 *                         Bootstrap's Reboot and silently break component
 *                         rendering in ways that look like random CSS bugs.
 *
 * See docs/DESIGN_SYSTEM.md section 6.
 */
module.exports = {
  prefix: 'tw-',
  corePlugins: { preflight: false },
  content: [
    './app/Views/**/*.php',
    './public/assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        ink:            'var(--c-ink)',
        'on-dark':      'var(--c-on-dark)',
        night:          'var(--c-canvas-night)',
        'night-el':     'var(--c-canvas-night-el)',
        'dark-el':      'var(--c-surface-dark-el)',
        light:          'var(--c-canvas-light)',
        cream:          'var(--c-canvas-cream)',
        aloe:           'var(--c-aloe-10)',
        pistachio:      'var(--c-pistachio-10)',
        'shade-30':     'var(--c-shade-30)',
        'shade-40':     'var(--c-shade-40)',
        'shade-50':     'var(--c-shade-50)',
        'shade-60':     'var(--c-shade-60)',
        'shade-70':     'var(--c-shade-70)',
        'hairline':     'var(--c-hairline-light)',
        'hairline-dark':'var(--c-hairline-dark)',
      },
      borderRadius: {
        xs: 'var(--r-xs)', sm: 'var(--r-sm)', md: 'var(--r-md)',
        lg: 'var(--r-lg)', xl: 'var(--r-xl)', pill: 'var(--r-pill)',
      },
      fontFamily: {
        display: 'var(--f-display)',
        body:    'var(--f-body)',
        mono:    'var(--f-mono)',
      },
      spacing: {
        xxs: 'var(--s-xxs)', xs: 'var(--s-xs)', sm: 'var(--s-sm)',
        md: 'var(--s-md)',  lg: 'var(--s-lg)', xl: 'var(--s-xl)',
        xxl: 'var(--s-xxl)', huge: 'var(--s-huge)',
      },
      boxShadow: {
        e1: 'var(--e-1)', e2: 'var(--e-2)', e3: 'var(--e-3)', e4: 'var(--e-4)',
      },
    },
  },
  plugins: [],
};
