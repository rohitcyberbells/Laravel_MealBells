import * as THREE from 'three';

/*
 * The landing page's WebGL stage.
 *
 * Bundled by Astro from the root node_modules, never loaded from a CDN: the
 * page is served through Laravel, whose script-src is 'self' and
 * 'unsafe-inline' only, so a CDN script tag would be silently blocked.
 *
 * This is the starting point - a camera, a light and one placeholder mesh. The
 * lunchbox model is not wired up yet; loading a .glb needs the file served from
 * resources/landing/public and a GLTFLoader call, which is a separate decision.
 */

const CANVAS_SELECTOR = '#scene';

/**
 * Starts the stage, or returns without doing anything if it cannot.
 *
 * Every exit here is deliberate. The page's content is real HTML and reads
 * perfectly well with no canvas at all, so nothing in this file is allowed to
 * throw: a browser without WebGL, a reader with animation turned off, or a
 * missing canvas should all leave the page working rather than blank.
 *
 * @returns {() => void} a teardown function, for a hot reload or a future SPA
 */
export function initExperience() {
  const canvas = document.querySelector(CANVAS_SELECTOR);

  if (!(canvas instanceof HTMLCanvasElement)) {
    return () => {};
  }

  let renderer;

  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      antialias: true,
      alpha: true,
    });
  } catch {
    // No WebGL. The page is already readable without it.
    return () => {};
  }

  // Capped at 2: beyond that the pixel count costs far more than it shows, and
  // phones are where this hurts.
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100);
  camera.position.set(0, 0, 5);

  scene.add(new THREE.AmbientLight(0xffffff, 1.4));

  const keyLight = new THREE.DirectionalLight(0xffffff, 2);
  keyLight.position.set(3, 4, 5);
  scene.add(keyLight);

  const placeholder = new THREE.Mesh(
    new THREE.BoxGeometry(1.6, 1, 1.2),
    new THREE.MeshStandardMaterial({ roughness: 0.4, metalness: 0.1 }),
  );
  scene.add(placeholder);

  /*
   * Sized from the canvas's own box rather than the window, so the stage can be
   * laid out however the CSS likes without the camera's aspect ratio drifting
   * away from it and stretching everything.
   */
  const resize = () => {
    const { clientWidth, clientHeight } = canvas;

    if (clientWidth === 0 || clientHeight === 0) {
      return;
    }

    camera.aspect = clientWidth / clientHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(clientWidth, clientHeight, false);
    renderer.render(scene, camera);
  };

  const resizeObserver = new ResizeObserver(resize);
  resizeObserver.observe(canvas);
  resize();

  // Someone who has asked their system to reduce motion gets one still frame
  // rather than no image at all.
  const stillOnly = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (stillOnly) {
    placeholder.rotation.set(0.3, 0.6, 0);
    renderer.render(scene, camera);

    return () => {
      resizeObserver.disconnect();
      renderer.dispose();
    };
  }

  let frame = null;
  let last = performance.now();

  const tick = (now) => {
    // Driven by elapsed time, not by frame count, so the animation runs at the
    // same speed on a 60Hz and a 144Hz screen.
    const elapsed = (now - last) / 1000;
    last = now;

    placeholder.rotation.y += elapsed * 0.6;
    placeholder.rotation.x += elapsed * 0.2;

    renderer.render(scene, camera);
    frame = requestAnimationFrame(tick);
  };

  const start = () => {
    if (frame === null) {
      last = performance.now();
      frame = requestAnimationFrame(tick);
    }
  };

  const stop = () => {
    if (frame !== null) {
      cancelAnimationFrame(frame);
      frame = null;
    }
  };

  /*
   * Only runs while the stage is actually on screen. A requestAnimationFrame
   * loop rendering a canvas nobody is looking at is pure battery cost, and a
   * landing page is read by scrolling past it.
   */
  const visibility = new IntersectionObserver(
    ([entry]) => (entry.isIntersecting ? start() : stop()),
    { threshold: 0 },
  );

  visibility.observe(canvas);

  return () => {
    stop();
    visibility.disconnect();
    resizeObserver.disconnect();
    renderer.dispose();
  };
}
