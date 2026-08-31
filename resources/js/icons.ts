/**
 * FontAwesome via the SVG-in-Vue-component renderer, not the webfont/CSS
 * kit, and with no global registry: every component that renders an icon
 * imports `FontAwesomeIcon` and the specific icon definitions it needs
 * directly, so the build only ships icons actually referenced and there's
 * no string-name-to-definition indirection or global component name to
 * keep in sync.
 *
 * This module only carries the one process-wide side effect that has to
 * happen exactly once — import it (for its side effect) from bootstrap.ts.
 */
import { config } from '@fortawesome/fontawesome-svg-core';

// styles.css is imported once via resources/css/app.css — don't let the
// library inject its own copy too.
config.autoAddCss = false;
