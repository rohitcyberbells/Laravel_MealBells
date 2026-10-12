import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';

/**
 * The public landing page.
 *
 * Astro is its own build tool, not a Vite plugin, so it cannot be added to the
 * application's vite.config.js - it wraps Vite with its own config and its own
 * `astro build`. That is why this is a separate project with a separate build,
 * and why `npm run build` and `npm run build:landing` are two commands.
 *
 * It emits static HTML into public/landing, which Laravel then serves at `/`.
 * Nothing here can reach the database: the landing page is for people who are
 * not signed in, and keeping it a static file means a marketing change cannot
 * break the application.
 */
export default defineConfig({
  outDir: '../../public/landing',

  /*
   * The HTML is served at `/` but its assets live under public/landing, so
   * emitted asset URLs have to carry that prefix or every one of them 404s.
   */
  base: '/landing',

  // Emitted into public/, which is checked into neither this project's output
  // nor git - see .gitignore.
  build: {
    assets: '_astro',
  },

  vite: {
    // The same Tailwind 4 plugin the application uses, so the landing page and
    // the signed-in screens cannot drift apart visually.
    plugins: [tailwindcss()],
  },
});
