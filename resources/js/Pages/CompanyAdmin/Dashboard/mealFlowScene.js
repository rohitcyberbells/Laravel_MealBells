import { gsap } from 'gsap';
import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';

/*
 * The dashboard strip in WebGL: a small diorama of the kitchen, the van and
 * the office. The SVG drawing in MealFlowStrip.vue stays as the fallback for
 * phones, reduced motion and browsers without WebGL.
 *
 * World units are the SVG drawing's divided by ten, on the same axes: x runs
 * down-right along the road, z down-left, y up. An isometric camera looking
 * down (1, 1, 1) puts them where the drawing has them.
 *
 * Colours are read from the --mb-s-* tokens in app.css rather than repeated
 * here, so day and night are defined in one place for both renderings.
 *
 * The idle motion (smoke, clouds, twinkling, the van's engine) runs on a
 * frame loop capped at 40 fps, and only while the strip is on screen and the
 * tab is visible. Scrolled away or in a background tab, it costs nothing.
 */

// The strip is 1100x560 now, and 42 keeps a building about the size it was in
// the old 1100x340 frame (same scale would be 46) while the taller viewport
// shows far more depth. The city is built wider than this on purpose: the
// roads run off every edge, which is what makes it read as a city rather than
// as a model of one.
const FRUSTUM_HEIGHT = 62;
const SKY_VIEWBOX = [1100, 560];
const TARGET = new THREE.Vector3(32, 0, -10);
/*
 * The kitchen moved to the far block so the van has somewhere to come from.
 * Everything inside the kitchen group is still written in the coordinates it
 * was drawn in; this shifts the group as a whole.
 */
const KITCHEN_OFFSET = new THREE.Vector3(-8, 0, -17);

/*
 * And the office moved a block east, across the far avenue, so the van's last
 * leg is a run down the main road rather than a few units of it. Same trick:
 * the group is drawn where it always was and shifted whole.
 */
const OFFICE_OFFSET = new THREE.Vector3(32, 0, 0);

/*
 * The van's round trip, as corners on the street centre lines: it loads at the
 * kitchen door on the northern street, runs east, turns down the avenue
 * between the blocks, and turns again onto the main road at the office.
 *
 * Legs are axis-aligned on purpose - the van is a box with a front, and
 * turning it at a corner is the only way it reads as driving rather than
 * sliding.
 */
const VAN_ROUTE = [
    [0, -16.5],
    [19.5, -16.5],
    [19.5, 3.5],
    [59, 3.5],
];
const VAN_SPEED = 7.5;
const FADE_SECONDS = 0.6;
const FRAME_MS = 1000 / 40;

// Isometric: 45 degrees round, 54.7 down from vertical. The pointer leans the
// camera a few degrees either way, which is all the parallax a dashboard needs.
const AZIMUTH = Math.PI / 4;
const POLAR = Math.acos(1 / Math.sqrt(3));
const LEAN = { azimuth: THREE.MathUtils.degToRad(3), polar: THREE.MathUtils.degToRad(2) };
const CAMERA_DISTANCE = 140;

const LIGHTING = {
    light: { hemi: 2.0, sky: '#ffffff', groundLight: '#b6c4d4', key: 2.4, keyColor: '#fff1d6', kitchen: 0, night: 0 },
    dark: { hemi: 1.25, sky: '#6b80aa', groundLight: '#0b1220', key: 0.8, keyColor: '#c7d2fe', kitchen: 60, night: 1 },
};

// The SVG's stars, in its own coordinates; placed by projecting back through
// the camera so they keep their spot in the corner at any width.
const STARS = [[40, 30], [96, 62], [150, 22], [210, 48], [262, 18], [36, 104], [120, 98], [470, 24], [700, 36], [742, 96], [860, 30], [930, 70], [1010, 24], [1060, 92], [905, 128], [780, 140]];

// Street furniture follows the pavements rather than being sprinkled about:
// z = -2.6 and 9.8 are the verges of the main road, -20.6 and 27.8 the verges
// of the two cross streets, and x = -14.6 / 55.4 the avenue verges.
/*
 * Seasons, on a timer rather than a calendar.
 *
 * The city turns over a year every twenty minutes - five minutes a season,
 * the way a game does it - because this is a screen someone leaves open, not
 * an almanac. Nothing in the data drives it and nothing reads it back. It
 * exists so a dashboard that is otherwise identical at 09:00 and at 16:00 has
 * something that is not.
 *
 * These colours live here rather than in app.css with the rest of the scene.
 * They are not a theme: there are four of them, each with a day and a night
 * form, and thirty-two more custom properties read back one at a time would
 * cost more than they explained. The summer day values are the grass and tree
 * tokens as they were, so the first season anyone sees is the city they
 * already knew.
 */
const SEASON_SECONDS = 300;
const SEASONS = [
    {
        name: 'spring',
        day: { grass: '#bdf0cd', tree: '#4ade80', treeDark: '#22c55e', trunk: '#92400e' },
        night: { grass: '#11382b', tree: '#15803d', treeDark: '#166534', trunk: '#451a03' },
        foliage: 1,
    },
    {
        name: 'summer',
        day: { grass: '#c6efd8', tree: '#34d399', treeDark: '#10b981', trunk: '#92400e' },
        night: { grass: '#12302a', tree: '#065f46', treeDark: '#064e3b', trunk: '#451a03' },
        foliage: 1,
    },
    {
        name: 'autumn',
        day: { grass: '#e3dcbb', tree: '#f59e0b', treeDark: '#d97706', trunk: '#78350f' },
        night: { grass: '#2b2616', tree: '#92400e', treeDark: '#7c2d12', trunk: '#431407' },
        foliage: 1,
    },
    {
        // Bare: the leaves fade out and the trunks are what is left standing.
        name: 'winter',
        day: { grass: '#e8eef4', tree: '#cbd5e1', treeDark: '#94a3b8', trunk: '#57534e' },
        night: { grass: '#1b2431', tree: '#334155', treeDark: '#1e293b', trunk: '#292524' },
        foliage: 0,
    },
];

/*
 * The street grid. Everything on the ground is measured from it.
 *
 * Trees, bushes and lamps used to be hand-written lists of coordinates. When
 * the grid gained streets the lists did not follow, and ten of the eleven
 * conifers, seven of the ten round trees and nine of the eighteen lamps ended
 * up standing in the middle of a road. They are derived from this layout now,
 * so adding a street moves the furniture out of its way instead of leaving it
 * there to be noticed in a screenshot.
 */
const GROUND = { x0: -34, x1: 124, z0: -58, z1: 40 };
const PAVEMENT = 1.6;
// Each street as [start, end] on its cross axis. H runs along x, V along z.
const H_STREETS = [[-38, -31], [-20, -13], [0, 7], [18, 25]];
const V_STREETS = [[-20, -13], [16, 23], [48, 55], [78, 85]];
// The one the van uses. Found rather than indexed: a street added at the front
// of the array silently moved this when it was H_STREETS[1].
const MAIN = H_STREETS.find(([a, b]) => a <= 3.5 && 3.5 <= b);

/**
 * The stretches NOT covered by a street's carriageway.
 *
 * Pavements are built from these, so one street's kerb stops at the next
 * street's tarmac instead of running across it. They used to span the whole
 * ground, and since a kerb stands 0.13 high and the road surface lies at
 * 0.035, every junction had a pale kerb lying proud across the black.
 */
function gaps(streets, from, to) {
    const edges = [from, ...streets.flatMap(([a, b]) => [a, b]), to];
    const out = [];
    for (let i = 0; i < edges.length; i += 2) {
        if (edges[i + 1] - edges[i] > 0.01) {
            out.push([edges[i], edges[i + 1]]);
        }
    }

    return out;
}

/** The gaps between streets, pavements excluded: where grass and buildings go. */
function spans(streets, from, to) {
    const edges = [from, ...streets.flatMap(([a, b]) => [a - PAVEMENT, b + PAVEMENT]), to];
    const out = [];
    for (let i = 0; i < edges.length; i += 2) {
        if (edges[i + 1] - edges[i] > 0.5) {
            out.push([edges[i], edges[i + 1]]);
        }
    }

    return out;
}

const X_BLOCKS = spans(V_STREETS, GROUND.x0, GROUND.x1);
const Z_BLOCKS = spans(H_STREETS, GROUND.z0, GROUND.z1);
const GRASS_BLOCKS = X_BLOCKS.flatMap(([xa, xb]) => Z_BLOCKS.map(([za, zb]) => [xa, xb, za, zb]));
// Clouds travel along (1, 0, -1), which is straight across the screen, in the
// band above the rooftops. `depth` is x + z relative to the target: it sets
// how high on screen the cloud sits, and keeps it behind the office.
const CLOUDS = [{ start: -30, y: 12, depth: -2, s: 1 }, { start: 5, y: 12.5, depth: -5, s: 1.25 }, { start: 30, y: 11.5, depth: -3.5, s: 0.9 }];
const CHIMNEY_TOP = new THREE.Vector3(6.5, 8.9, -10.3).add(KITCHEN_OFFSET);

const LABEL_CLASS = 'pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full border border-(--mb-s-label-line) bg-(--mb-s-label-bg) px-2.5 py-0.5 text-[11px] font-semibold text-(--mb-s-label) shadow-sm transition-[color,background-color,border-color,opacity] duration-[600ms]';

const TOKENS = ['bg', 'grid', 'road', 'dash', 'top', 'roof-office', 'roof-kitchen', 'roof-plain', 'win-on', 'win-off', 'van-top', 'van-stripe', 'lamp', 'tree', 'tree-dark', 'trunk', 'tiffin', 'lid-1', 'lid-2', 'lid-3', 'select', 'platform', 'platform-side', 'grass', 'walk', 'door', 'smoke', 'cloud'];

function parseColor(value) {
    // The CSS minifier rewrites rgb(30 41 59 / 0.7) as #1e293bb3, and
    // THREE.Color reads neither an alpha channel nor eight-digit hex.
    if (value.startsWith('#')) {
        if (value.length === 5 || value.length === 9) {
            const long = value.length === 5 ? `#${[...value.slice(1)].map((c) => c + c).join('')}` : value;

            return { color: new THREE.Color(long.slice(0, 7)), alpha: parseInt(long.slice(7, 9), 16) / 255 };
        }

        return { color: new THREE.Color(value), alpha: 1 };
    }

    const [r = 0, g = 0, b = 0, a = 1] = (value.match(/[\d.]+/g) ?? []).map(Number);

    return { color: new THREE.Color().setRGB(r / 255, g / 255, b / 255, THREE.SRGBColorSpace), alpha: a };
}

function readPalette(host) {
    const style = getComputedStyle(host);

    return Object.fromEntries(TOKENS.map((name) => [name, parseColor(style.getPropertyValue(`--mb-s-${name}`).trim() || '#000000')]));
}

/** A box spanning x0..x1, y0..y1, z0..z1, which is how the drawing measures. */
function block(x0, x1, y0, y1, z0, z1, material, radius = 0) {
    const [w, h, d] = [x1 - x0, y1 - y0, z1 - z0];
    const geometry = radius > 0 ? new RoundedBoxGeometry(w, h, d, 2, Math.min(radius, w / 2, h / 2, d / 2)) : new THREE.BoxGeometry(w, h, d);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.position.set((x0 + x1) / 2, (y0 + y1) / 2, (z0 + z1) / 2);
    mesh.castShadow = true;
    mesh.receiveShadow = true;

    return mesh;
}

function flatPlane(x0, x1, z0, z1, y, material) {
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(x1 - x0, z1 - z0), material);
    mesh.rotation.x = -Math.PI / 2;
    mesh.position.set((x0 + x1) / 2, y, (z0 + z1) / 2);
    mesh.receiveShadow = true;

    return mesh;
}

const UP = new THREE.Quaternion();
const ONE = new THREE.Vector3(1, 1, 1);

/** One instanced mesh from a list of [x, y, z] (and optional per-instance scale). */
function scatter(geometry, material, points, rotation = UP) {
    const mesh = new THREE.InstancedMesh(geometry, material, points.length);
    const m = new THREE.Matrix4();
    points.forEach(([x, y, z, s = 1], i) => mesh.setMatrixAt(i, m.compose(new THREE.Vector3(x, y, z), rotation, new THREE.Vector3(s, s, s))));
    mesh.castShadow = true;
    mesh.receiveShadow = true;

    return mesh;
}

/** Window panes on a face; `face` is 'z' (front-left) or 'x' (front-right). */
function panes(material, positions, face) {
    const turn = new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), face === 'x' ? Math.PI / 2 : 0);
    const mesh = scatter(new THREE.BoxGeometry(0.9, 0.9, 0.08), material, positions, turn);
    mesh.castShadow = false;

    return mesh;
}

/** Window positions on a face, split by which are lit, in the drawing's row/column order. */
function windowGrid({ cols, rows, from, span, fixed, base, height, face, lit }) {
    const on = [];
    const off = [];
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const along = from + (span / (cols + 1)) * (c + 1);
            const y = base + (height / (rows + 1)) * (r + 1);
            const pos = face === 'z' ? [along, y, fixed + 0.05] : [fixed + 0.05, y, along];
            (lit.some(([lr, lc]) => lr === r && lc === c) ? on : off).push(pos);
        }
    }

    return { on, off };
}

/**
 * @param {HTMLElement} host  an element inside .mb-theme; the canvas fills it
 * @param {{ theme: string, state: { isMealDay: boolean, isLocked: boolean, tiffinLabel: string }, selected: ?string, onSelect: (name: ?string) => void }} options
 * @returns {{ setTheme: Function, setState: Function, setSelected: Function, dispose: Function }}
 */
export function createMealFlowScene(host, options) {
    const renderer = new THREE.WebGLRenderer({ antialias: true });
    // Capped at 2: beyond that the pixel count costs far more than it shows.
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.domElement.className = 'absolute inset-0 w-full h-full';
    renderer.domElement.setAttribute('aria-hidden', 'true');
    host.appendChild(renderer.domElement);

    const scene = new THREE.Scene();
    scene.background = new THREE.Color();

    const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 400);

    // Materials are the unit of colour: one per token, tweened on a theme change.
    const lambert = (extra = {}) => new THREE.MeshLambertMaterial(extra);
    const mat = {
        platform: lambert(),
        platformSide: lambert(),
        grass: lambert(),
        walk: lambert(),
        road: lambert(),
        dash: lambert(),
        wall: lambert(),
        roofKitchen: lambert(),
        roofOffice: lambert(),
        roofPlain: lambert(),
        door: lambert(),
        kitchenWindow: lambert(),
        officeLit: lambert(),
        officeUnlit: lambert(),
        van: lambert(),
        stripe: lambert(),
        lamp: lambert(),
        pole: lambert(),
        wheel: lambert({ color: '#0f172a' }),
        // Transparent so winter can fade the leaves out and leave the trunks.
        tree: lambert({ transparent: true }),
        treeDark: lambert({ transparent: true }),
        trunk: lambert(),
        tiffin: lambert(),
        lid: lambert({ color: '#ffffff' }),
        cloud: lambert({ transparent: true, opacity: 0 }),
        beam: new THREE.MeshBasicMaterial({ transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }),
        pool: new THREE.MeshBasicMaterial({ transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }),
        grid: new THREE.LineBasicMaterial({ transparent: true }),
        // The sky ignores depth: seen from above, anything far enough away to
        // be "behind" the scene is under the ground.
        star: new THREE.PointsMaterial({ size: 2.2, sizeAttenuation: false, transparent: true, opacity: 0, vertexColors: true, depthTest: false }),
        select: new THREE.LineDashedMaterial({ dashSize: 0.6, gapSize: 0.5 }),
    };
    // Each puff fades on its own, so each needs its own material.
    const puffMaterials = Array.from({ length: 4 }, () => lambert({ transparent: true, opacity: 0, depthWrite: false }));

    /*
     * The ground: a rounded slab carrying a street grid.
     *
     * Three streets run east-west and two run north-south, every one of them
     * seven units wide with a 1.6-unit pavement either side. The slab is
     * larger than the camera ever sees, so the roads leave the frame instead
     * of stopping at a tidy edge - a diorama that ends where the picture ends
     * reads as a toy, and this is meant to read as a town the office is in.
     */
    const flat = new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(1, 0, 0), -Math.PI / 2);

    const platform = new THREE.Group();
    const slab = new THREE.Mesh(new RoundedBoxGeometry(GROUND.x1 - GROUND.x0 + 4, 1.4, GROUND.z1 - GROUND.z0 + 4, 3, 0.9), mat.platformSide);
    slab.position.set((GROUND.x0 + GROUND.x1) / 2, -0.7, (GROUND.z0 + GROUND.z1) / 2);
    slab.receiveShadow = true;
    platform.add(slab, flatPlane(GROUND.x0, GROUND.x1, GROUND.z0, GROUND.z1, 0.005, mat.platform));

    /*
     * The faint lattice, clipped to the grass.
     *
     * It used to be drawn straight across the ground, streets included. It
     * sits at y = 0.02 and the roads at 0.03, so it should have been hidden
     * under them - but 0.01 of depth in a frustum 400 deep is not enough to
     * win reliably, and the lines showed through, most visibly as a cross
     * over every junction. Clipping it is cheaper than fighting the depth
     * buffer, and the grid was only ever meant to texture the ground anyway.
     *
     * It also sat at 0.02 while the grass blocks run from 0 to 0.16, so it was
     * buried under the grass it was supposed to texture and visible only where
     * it was not wanted. It is above the grass now, at 0.17.
     */
    const GRID_STEP = 7;
    const gridPoints = [];
    for (const [xa, xb, za, zb] of GRASS_BLOCKS) {
        for (let x = Math.ceil((xa - GROUND.x0 - 2) / GRID_STEP) * GRID_STEP + GROUND.x0 + 2; x < xb; x += GRID_STEP) {
            if (x > xa) {
                gridPoints.push(x, 0.17, za, x, 0.17, zb);
            }
        }
        for (let z = Math.ceil((za - GROUND.z0 - 3) / GRID_STEP) * GRID_STEP + GROUND.z0 + 3; z < zb; z += GRID_STEP) {
            if (z > za) {
                gridPoints.push(xa, 0.17, z, xb, 0.17, z);
            }
        }
    }
    platform.add(new THREE.LineSegments(new THREE.BufferGeometry().setAttribute('position', new THREE.Float32BufferAttribute(gridPoints, 3)), mat.grid));

    // Grass fills what the streets and pavements leave.
    for (const [xa, xb, za, zb] of GRASS_BLOCKS) {
        platform.add(block(xa, xb, 0, 0.16, za, zb, mat.grass, 0.4));
    }

    for (const [z0, z1] of H_STREETS) {
        for (const [xa, xb] of gaps(V_STREETS, GROUND.x0, GROUND.x1)) {
            platform.add(
                block(xa, xb, 0, 0.13, z0 - PAVEMENT, z0, mat.walk, 0.1),
                block(xa, xb, 0, 0.13, z1, z1 + PAVEMENT, mat.walk, 0.1),
            );
        }
        platform.add(flatPlane(GROUND.x0, GROUND.x1, z0, z1, 0.03, mat.road));
    }
    for (const [x0, x1] of V_STREETS) {
        for (const [za, zb] of gaps(H_STREETS, GROUND.z0, GROUND.z1)) {
            platform.add(
                block(x0 - PAVEMENT, x0, 0, 0.13, za, zb, mat.walk, 0.1),
                block(x1, x1 + PAVEMENT, 0, 0.13, za, zb, mat.walk, 0.1),
            );
        }
        platform.add(flatPlane(x0, x1, GROUND.z0, GROUND.z1, 0.035, mat.road));
    }

    // Centre lines, broken at every junction and at the office crossing.
    const clearOf = (value, ranges, pad) => ranges.every(([a, b]) => value < a - pad || value > b + pad);
    const dashPoints = [];
    for (const [z0, z1] of H_STREETS) {
        for (let x = GROUND.x0 + 2; x < GROUND.x1; x += 5) {
            if (clearOf(x, V_STREETS, 2) && (z0 !== MAIN[0] || x < 21 || x > 26)) {
                dashPoints.push([x, 0.06, (z0 + z1) / 2]);
            }
        }
    }
    const dashesAcross = [];
    for (const [x0, x1] of V_STREETS) {
        for (let z = GROUND.z0 + 2; z < GROUND.z1; z += 5) {
            if (clearOf(z, H_STREETS, 2)) {
                dashesAcross.push([(x0 + x1) / 2, 0.06, z]);
            }
        }
    }
    const turn = new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), Math.PI / 2).premultiply(flat);
    const dashes = scatter(new THREE.PlaneGeometry(2.4, 0.4), mat.dash, dashPoints, flat);
    const acrossDashes = scatter(new THREE.PlaneGeometry(2.4, 0.4), mat.dash, dashesAcross, turn);
    const zebra = scatter(new THREE.PlaneGeometry(3, 0.6), mat.dash, [0.8, 1.9, 3, 4.1, 5.2, 6.3].map((z) => [23.5, 0.06, z]), flat);
    dashes.castShadow = acrossDashes.castShadow = zebra.castShadow = false;
    platform.add(dashes, acrossDashes, zebra);
    scene.add(platform);

    // Kitchen.
    const awning = block(5, 12.2, 4.1, 4.4, -4, -2.4, mat.roofKitchen, 0.08);
    awning.rotation.x = 0.28;
    const kitchen = new THREE.Group();
    kitchen.add(
        block(4, 13, 0, 5.8, -12, -4, mat.wall, 0.3),
        block(3.6, 13.4, 5.8, 6.5, -12.4, -3.6, mat.roofKitchen, 0.2),
        block(5.8, 7.2, 6.5, 8.8, -11, -9.6, mat.wall, 0.15),
        block(6, 11, 6.5, 7.9, -4.3, -4.0, mat.roofKitchen, 0.1),
        block(10.9, 12.3, 0, 2.7, -4.06, -3.96, mat.door),
        awning,
        panes(mat.kitchenWindow, [[6.1, 2.6, -3.95], [8.3, 2.6, -3.95]], 'z'),
        panes(mat.kitchenWindow, [[13.05, 2.9, -9.33], [13.05, 2.9, -6.67]], 'x'),
    );
    kitchen.position.copy(KITCHEN_OFFSET);
    scene.add(kitchen);

    // Chimney smoke, cycled from the chimney top while the kitchen is open.
    const puffs = puffMaterials.map((material) => new THREE.Mesh(new THREE.IcosahedronGeometry(0.55, 1), material));
    puffs.forEach((puff) => scene.add(puff));

    // Office, with today's tiffins stacked at the door.
    const left = windowGrid({ cols: 4, rows: 4, from: 26, span: 11, fixed: -4, base: 3.4, height: 7.6, face: 'z', lit: [[0, 1], [1, 0], [1, 2], [2, 3], [3, 1], [3, 2]] });
    const right = windowGrid({ cols: 3, rows: 4, from: -13, span: 9, fixed: 37, base: 1.4, height: 9.4, face: 'x', lit: [[0, 0], [1, 2], [2, 1], [3, 0]] });
    const office = new THREE.Group();
    office.add(
        block(26, 37, 0, 11.2, -13, -4, mat.wall, 0.35),
        block(25.7, 37.3, 11.2, 11.9, -13.3, -3.7, mat.roofOffice, 0.2),
        block(28.5, 31, 11.9, 12.9, -11.5, -9.2, mat.wall, 0.15),
        block(32.6, 35.6, 11.9, 13.2, -10.6, -7.6, mat.wall, 0.15),
        block(29, 33, 3, 3.3, -4, -2.3, mat.roofOffice, 0.08),
        block(30, 32, 0, 2.8, -4.06, -3.96, mat.officeLit),
        panes(mat.officeLit, left.on, 'z'),
        panes(mat.officeUnlit, left.off, 'z'),
        panes(mat.officeLit, right.on, 'x'),
        panes(mat.officeUnlit, right.off, 'x'),
    );

    const tiffins = new THREE.Group();
    const tierPoints = [0, 1, 2, 3].map((n) => [35.3, n * 1.1 + 0.6, -1.4]);
    const tiers = new THREE.InstancedMesh(new RoundedBoxGeometry(2.2, 0.9, 2.2, 2, 0.15), mat.tiffin, 4);
    const lids = new THREE.InstancedMesh(new RoundedBoxGeometry(2.3, 0.22, 2.3, 2, 0.08), mat.lid, 4);
    const m4 = new THREE.Matrix4();
    tierPoints.forEach(([x, y, z], n) => {
        tiers.setMatrixAt(n, m4.compose(new THREE.Vector3(x, y, z), UP, ONE));
        lids.setMatrixAt(n, m4.compose(new THREE.Vector3(x, y + 0.45, z), UP, ONE));
        // Set before the first frame: the shader is compiled without
        // per-instance colour if none exist yet, and adding them later is ignored.
        lids.setColorAt(n, new THREE.Color(1, 1, 1));
    });
    tiers.castShadow = true;
    tiffins.add(tiers, lids);
    office.add(tiffins);
    office.position.copy(OFFICE_OFFSET);
    scene.add(office);

    /*
     * The rest of the town.
     *
     * Plain blocks with a roof slab and two lit faces - enough to read as
     * buildings at this size, and cheap enough that twenty of them cost less
     * than the office does. They are deliberately not selectable: the kitchen,
     * the van and the office are the three things the picture is about, and a
     * hover target on a background block would dilute that.
     *
     * Which windows are lit is a hash of the position rather than Math.random,
     * so the skyline looks the same on every reload and in every screenshot.
     */
    const CITY = [
        // West of the avenue.
        [-29, -22.5, -13, -3, 6],
        [-29, -22.5, 9.6, 16, 5],
        // East of the far avenue. The office has the near plot, and nothing is
        // built opposite its door for the same reason as before.
        [58, 66, -30, -21.6, 6],
        [86, 98, -13, -3, 9],
        [86, 97, 9.6, 16, 6],
        [86, 96, -30, -21.6, 7],
        // South of the main road, either side of the avenue the van comes down.
        // Nothing is built opposite the office door: a block there stands between
        // the camera and the one spot the van is driving to.
        [-8, 2, 9.6, 15.4, 5.5],
        [5, 14.4, 9.6, 16, 7.5],
        [36, 45, 9.6, 16, 8.5],
        // North, beyond the cross street. The kitchen has the western plot.
        [8, 14.4, -30, -21.6, 10],
        [24.6, 36, -30, -21.6, 6],
        // The block the office used to stand on, and its neighbours.
        [26, 37, -13, -4, 8],
        [-8, 4, -13, -3, 6],
        [36, 45, -30, -21.6, 7],
        // The far side of town.
        [-8, 4, 27.6, 35, 6],
        [24.6, 36, 27.6, 34, 9],
        [40, 48, 27.6, 34, 6],
        [58, 70, 27.6, 35, 8],
        // The streets beyond, which mostly leave the frame.
        [-6, 6, -52, -39.6, 8],
        [12, 26, -52, -39.6, 11],
        [32, 44, -52, -39.6, 7],
        [52, 66, -52, -39.6, 9],
        [86, 98, -52, -39.6, 8],
    ];

    const city = new THREE.Group();
    for (const [x0, x1, z0, z1, h] of CITY) {
        const cols = Math.max(2, Math.round((x1 - x0) / 3));
        const rows = Math.max(2, Math.round(h / 2.6));
        const depthCols = Math.max(2, Math.round((z1 - z0) / 3));
        const seed = Math.abs(Math.round(x0 * 31 + z0 * 17 + h * 7));
        const litFace = (r, c, salt) => ((seed + r * 13 + c * 7 + salt) % 5) < 2;
        const pick = (rs, cs, salt) => {
            const on = [];
            for (let r = 0; r < rs; r++) {
                for (let c = 0; c < cs; c++) {
                    if (litFace(r, c, salt)) {
                        on.push([r, c]);
                    }
                }
            }

            return on;
        };

        const front = windowGrid({ cols, rows, from: x0, span: x1 - x0, fixed: z1, base: 0.6, height: h - 1.2, face: 'z', lit: pick(rows, cols, 0) });
        const side = windowGrid({ cols: depthCols, rows, from: z0, span: z1 - z0, fixed: x1, base: 0.6, height: h - 1.2, face: 'x', lit: pick(rows, depthCols, 3) });

        city.add(
            block(x0, x1, 0, h, z0, z1, mat.wall, 0.3),
            block(x0 - 0.3, x1 + 0.3, h, h + 0.7, z0 - 0.3, z1 + 0.3, mat.roofPlain, 0.2),
            panes(mat.officeLit, front.on, 'z'),
            panes(mat.officeUnlit, front.off, 'z'),
            panes(mat.officeLit, side.on, 'x'),
            panes(mat.officeUnlit, side.off, 'x'),
        );
    }
    scene.add(city);

    /*
     * Street furniture, kept off the carriageway by construction rather than
     * by hand. Trees stand inside the grass blocks, inset from the kerb and
     * clear of anything built; bushes sit along the block edges; lamps stand
     * on the pavement, where lamps go, skipping the junctions.
     *
     * Which corner gets what is a hash of its position, not Math.random, so
     * the city is the same on every reload and screenshots stay comparable.
     */
    const OCCUPIED = [
        ...CITY.map(([x0, x1, z0, z1]) => [x0, x1, z0, z1]),
        [4 + KITCHEN_OFFSET.x, 13 + KITCHEN_OFFSET.x, -12 + KITCHEN_OFFSET.z, -4 + KITCHEN_OFFSET.z],
        [26 + OFFICE_OFFSET.x, 37 + OFFICE_OFFSET.x, -13 + OFFICE_OFFSET.z, -4 + OFFICE_OFFSET.z],
    ];
    const clearOfBuildings = (x, z, pad = 2) =>
        OCCUPIED.every(([x0, x1, z0, z1]) => x < x0 - pad || x > x1 + pad || z < z0 - pad || z > z1 + pad);

    const CONE_TREES = [];
    const ROUND_TREES = [];
    const BUSHES = [];
    for (const [xa, xb, za, zb] of GRASS_BLOCKS) {
        if (xb - xa < 6 || zb - za < 6) {
            continue;
        }
        const inset = 2.4;
        const spots = [
            [xa + inset, za + inset], [xb - inset, za + inset],
            [xa + inset, zb - inset], [xb - inset, zb - inset],
            [(xa + xb) / 2, za + inset], [(xa + xb) / 2, zb - inset],
        ];
        for (const [x, z] of spots) {
            if (!clearOfBuildings(x, z)) {
                continue;
            }
            const pick = Math.abs(Math.round(x * 3 + z * 5)) % 7;
            if (pick === 0 || pick === 4) {
                CONE_TREES.push([x, z]);
            } else if (pick === 1 || pick === 5) {
                ROUND_TREES.push([x, z]);
            } else if (pick === 2) {
                BUSHES.push([x, z]);
            }
        }
    }

    const LAMPS = [];
    const offJunction = (value, streets) => streets.every(([a, b]) => value < a - PAVEMENT || value > b + PAVEMENT);
    for (const [z0, z1] of H_STREETS) {
        for (let x = GROUND.x0 + 7; x < GROUND.x1; x += 13) {
            if (offJunction(x, V_STREETS) && clearOfBuildings(x, z0, 0.5)) {
                LAMPS.push([x, z0 - PAVEMENT / 2], [x, z1 + PAVEMENT / 2]);
            }
        }
    }
    for (const [x0, x1] of V_STREETS) {
        for (let z = GROUND.z0 + 11; z < GROUND.z1; z += 16) {
            if (offJunction(z, H_STREETS) && clearOfBuildings(x0, z, 0.5)) {
                LAMPS.push([x0 - PAVEMENT / 2, z], [x1 + PAVEMENT / 2, z]);
            }
        }
    }

    // Van, built at its own origin and moved as a whole.
    const van = new THREE.Group();
    const beamShape = new THREE.Shape([new THREE.Vector2(5.8, -0.4), new THREE.Vector2(13, 1.2), new THREE.Vector2(13, -5.6), new THREE.Vector2(5.8, -3.8)]);
    const beam = new THREE.Mesh(new THREE.ShapeGeometry(beamShape), mat.beam);
    beam.rotation.x = -Math.PI / 2;
    beam.position.y = 0.06;
    const axle = new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(1, 0, 0), Math.PI / 2);
    const wheels = scatter(new THREE.CylinderGeometry(0.55, 0.55, 0.3, 14), mat.wheel, [[1.2, 0.55, 0], [1.2, 0.55, 4.2], [4.8, 0.55, 0], [4.8, 0.55, 4.2]], axle);
    const chassis = new THREE.Group();
    chassis.add(
        block(0, 4.4, 0.4, 3.4, 0, 4.2, mat.van, 0.3),
        block(4.3, 5.8, 0.4, 2.5, 0, 4.2, mat.van, 0.3),
        block(5.78, 5.86, 1.5, 2.3, 0.5, 3.7, mat.door),
        block(0.4, 4, 1.4, 2, 4.2, 4.27, mat.stripe),
        block(5.8, 5.88, 0.8, 1.3, 0.4, 1, mat.lamp),
        block(5.8, 5.88, 0.8, 1.3, 3.2, 3.8, mat.lamp),
    );
    // The van is drawn from its back-left corner. Hanging it off an inner
    // group puts its middle on the outer group's origin, so a turn rotates it
    // about itself instead of swinging it round a corner.
    const vanBody = new THREE.Group();
    vanBody.position.set(-2.9, 0, -2.1);
    vanBody.add(chassis, wheels, beam);
    van.add(vanBody);
    scene.add(van);

    // Trees, bushes and street lamps.
    const greenery = new THREE.Group();
    const cones = scatter(new THREE.ConeGeometry(1.3, 3.4, 7), mat.tree, CONE_TREES.map(([x, z]) => [x, 2.9, z]));
    const crowns = scatter(new THREE.IcosahedronGeometry(1.6, 0), mat.treeDark, ROUND_TREES.map(([x, z]) => [x, 2.9, z]));
    const trunks = scatter(new THREE.CylinderGeometry(0.2, 0.25, 1.4, 6), mat.trunk, [...CONE_TREES, ...ROUND_TREES].map(([x, z]) => [x, 0.7, z]));
    const bushes = scatter(new THREE.IcosahedronGeometry(0.75, 0), mat.tree, BUSHES.map(([x, z], i) => [x, 0.55, z, 0.8 + (i % 3) * 0.15]));
    greenery.add(cones, crowns, trunks, bushes);

    const poles = scatter(new THREE.CylinderGeometry(0.12, 0.15, 4.2, 6), mat.pole, LAMPS.map(([x, z]) => [x, 2.1, z]));
    const heads = scatter(new RoundedBoxGeometry(1, 0.3, 0.6, 2, 0.1), mat.lamp, LAMPS.map(([x, z]) => [x, 4.3, z]));
    const pools = scatter(new THREE.CircleGeometry(2.4, 24), mat.pool, LAMPS.map(([x, z]) => [x, 0.07, z]), flat);
    pools.castShadow = pools.receiveShadow = false;
    greenery.add(poles, heads, pools);
    scene.add(greenery);

    // Clouds by day, drifting along the road; three blobs of three spheres.
    const blob = [[0, 0, 0, 1.6], [1.8, -0.3, 0.4, 1.2], [-1.7, -0.4, -0.2, 1.1]];
    const clouds = new THREE.InstancedMesh(new THREE.IcosahedronGeometry(1, 1), mat.cloud, CLOUDS.length * blob.length);
    scene.add(clouds);

    // Sky: placed in screen space, so it is repositioned whenever the camera
    // moves. Stars only - there is no sun or moon. One orb in the corner of a
    // city this wide read as a sticker on the glass rather than as weather.
    const stars = new THREE.Points(new THREE.BufferGeometry(), mat.star);
    stars.geometry.setAttribute('color', new THREE.Float32BufferAttribute(new Array(STARS.length * 3).fill(1), 3));
    stars.renderOrder = 2;
    // Their positions are rewritten as the camera leans, which leaves the
    // bounds three.js culls by out of date; the sky is always in view anyway.
    stars.frustumCulled = false;
    scene.add(stars);

    // Light.
    const hemi = new THREE.HemisphereLight();
    const key = new THREE.DirectionalLight();
    key.position.copy(TARGET).add(new THREE.Vector3(-30, 45, -10));
    key.target.position.copy(TARGET);
    key.shadow.mapSize.set(2048, 2048);
    key.shadow.bias = -0.0004;
    key.shadow.normalBias = 0.03;
    Object.assign(key.shadow.camera, { left: -85, right: 85, top: 85, bottom: -85, near: 1, far: 260 });
    const hearth = new THREE.PointLight('#f59e0b', 0, 22, 2);
    hearth.position.set(8.5, 2.5, -1.5).add(KITCHEN_OFFSET);
    scene.add(hemi, key, key.target, hearth);

    // Selection outline, re-parented to whatever is selected so it follows the van.
    const outline = new THREE.LineSegments(new THREE.BufferGeometry(), mat.select);

    // Labels are HTML over the canvas: crisp at any size, and themed by the
    // same CSS tokens as everything else.
    const labels = {
        kitchen: { text: 'Kitchen', at: () => new THREE.Vector3(8.5, 10.4, -8).add(kitchen.position) },
        office: { text: 'Office', at: () => new THREE.Vector3(31.5, 14.6, -8.5).add(office.position) },
        van: { text: 'Van', at: () => van.position.clone().add(new THREE.Vector3(0, 4.8, 0)) },
        tiffins: { text: options.state.tiffinLabel, at: () => new THREE.Vector3(37, 0, 1.4).add(office.position) },
    };
    for (const label of Object.values(labels)) {
        label.el = document.createElement('span');
        label.el.className = LABEL_CLASS;
        label.el.textContent = label.text;
        label.el.style.opacity = '0';
        host.appendChild(label.el);
    }

    const groups = { kitchen, office, van };
    for (const [name, group] of Object.entries(groups)) {
        group.traverse((object) => {
            object.userData.select = name;
        });
    }
    // The beam lies on the road and should not make the road clickable.
    beam.userData.select = null;

    let theme = options.theme;
    let state = { ...options.state };
    let selected = null;
    let hovered = null;
    let width = 1;
    let height = 1;
    let night = 0;
    let hearthLevel = 0;
    // Summer first, so the city anyone opens looks like the one they knew.
    let seasonIndex = 1;
    const lean = { x: 0, y: 0, tx: 0, ty: 0 };
    const lift = { kitchen: 0, office: 0, van: 0 };

    function placeClouds(t) {
        let n = 0;
        CLOUDS.forEach((cloud, c) => {
            const along = ((cloud.start + t * (0.5 + c * 0.12) + 45) % 90) - 45;
            const x = TARGET.x + cloud.depth / 2 + along;
            const z = TARGET.z + cloud.depth / 2 - along;
            for (const [dx, dy, dz, s] of blob) {
                clouds.setMatrixAt(n++, m4.compose(new THREE.Vector3(x + dx * cloud.s, cloud.y + dy, z - dx * cloud.s + dz), UP, new THREE.Vector3(s, s * 0.75, s).multiplyScalar(cloud.s)));
            }
        });
        clouds.instanceMatrix.needsUpdate = true;
    }

    function placeCamera() {
        const azimuth = AZIMUTH + lean.x * LEAN.azimuth;
        const polar = POLAR - lean.y * LEAN.polar;
        camera.position.setFromSphericalCoords(CAMERA_DISTANCE, polar, azimuth).add(TARGET);
        camera.lookAt(TARGET);
        camera.updateMatrixWorld();
    }

    function placeSky() {
        const toWorld = ([x, y]) => new THREE.Vector3((x / SKY_VIEWBOX[0]) * 2 - 1, 1 - (y / SKY_VIEWBOX[1]) * 2, 0.95).unproject(camera);
        stars.geometry.setAttribute('position', new THREE.Float32BufferAttribute(STARS.flatMap((s) => toWorld(s).toArray()), 3));
    }

    function render() {
        renderer.render(scene, camera);
        const point = new THREE.Vector3();
        for (const [name, label] of Object.entries(labels)) {
            const hidden = (name === 'van' || name === 'tiffins') && !state.isMealDay;
            label.el.style.display = hidden ? 'none' : '';
            if (!hidden) {
                point.copy(label.at()).project(camera);
                label.el.style.left = `${((point.x + 1) / 2) * width}px`;
                label.el.style.top = `${((1 - point.y) / 2) * height}px`;
            }
        }
    }

    function resize() {
        width = host.clientWidth || 1;
        height = host.clientHeight || 1;
        renderer.setSize(width, height, false);
        const halfHeight = FRUSTUM_HEIGHT / 2;
        const halfWidth = halfHeight * (width / height);
        Object.assign(camera, { left: -halfWidth, right: halfWidth, top: halfHeight, bottom: -halfHeight });
        camera.updateProjectionMatrix();
        placeCamera();
        placeSky();
        render();
    }

    /** Everything that moves on its own, at time `t` in seconds. */
    function animate(t, dt) {
        const ease = 1 - Math.pow(0.001, dt);

        // The year turns. t is seconds since the page loaded, so the cycle
        // pauses with the rest of the scene when nobody is looking at it.
        const turned = (Math.floor(t / SEASON_SECONDS) + 1) % SEASONS.length;
        if (turned !== seasonIndex) {
            seasonIndex = turned;
            applySeason(true);
        }

        // Camera lean follows the pointer, eased.
        const leaning = Math.abs(lean.tx - lean.x) > 0.001 || Math.abs(lean.ty - lean.y) > 0.001;
        lean.x += (lean.tx - lean.x) * ease;
        lean.y += (lean.ty - lean.y) * ease;
        if (leaning) {
            placeCamera();
            placeSky();
        }

        // Hover lifts the building under the pointer a little off the ground.
        for (const name of Object.keys(lift)) {
            lift[name] += ((hovered === name ? 0.35 : 0) - lift[name]) * ease;
        }
        kitchen.position.y = lift.kitchen;
        office.position.y = lift.office;
        chassis.position.y = lift.van + Math.sin(t * 9) * 0.035;

        // Smoke: four puffs, each a quarter of a cycle behind the last.
        const smoking = state.isMealDay;
        puffs.forEach((puff, i) => {
            const phase = (t * 0.22 + i / puffs.length) % 1;
            puff.visible = smoking;
            puff.position.set(CHIMNEY_TOP.x + phase * 1.6, CHIMNEY_TOP.y + phase * 4.2 + lift.kitchen, CHIMNEY_TOP.z - phase * 0.6);
            puff.scale.setScalar(0.5 + phase * 1.1);
            puff.material.opacity = Math.sin(phase * Math.PI) * 0.55;
        });

        // Clouds drift by day and are gone at night.
        if (mat.cloud.opacity > 0.01) {
            placeClouds(t);
        }

        // Stars twinkle at night; the kitchen's light flickers like a stove.
        if (night > 0.01) {
            const colors = stars.geometry.getAttribute('color');
            for (let i = 0; i < STARS.length; i++) {
                const b = 0.55 + 0.45 * Math.sin(t * (1.3 + (i % 5) * 0.4) + i * 1.7);
                colors.setXYZ(i, b, b, b);
            }
            colors.needsUpdate = true;
        }
        hearth.intensity = hearthLevel * (1 + 0.07 * Math.sin(t * 7.3) + 0.05 * Math.sin(t * 12.1));
    }

    // Frame loop, only while on screen and in a visible tab.
    let raf = 0;
    let running = false;
    let onScreen = true;
    let lastFrame = 0;
    function tick(now) {
        raf = requestAnimationFrame(tick);
        if (now - lastFrame < FRAME_MS) {
            return;
        }
        const dt = Math.min(0.1, (now - (lastFrame || now)) / 1000);
        lastFrame = now;
        animate(now / 1000, dt);
        render();
    }
    function updateRunning() {
        const should = onScreen && !document.hidden;
        if (should && !running) {
            running = true;
            lastFrame = 0;
            raf = requestAnimationFrame(tick);
        } else if (!should && running) {
            running = false;
            cancelAnimationFrame(raf);
        }
    }

    const tweened = new Set();
    function tween(target, vars) {
        tweened.add(target);
        gsap.to(target, { duration: FADE_SECONDS, ease: 'power1.inOut', overwrite: 'auto', ...vars });
    }
    const toColor = (color, to, animate) => (animate ? tween(color, { r: to.r, g: to.g, b: to.b }) : color.copy(to));
    const toValue = (object, prop, to, animate) => (animate ? tween(object, { [prop]: to }) : (object[prop] = to));

    const black = new THREE.Color(0, 0, 0);
    const holder = { night: 0, hearth: 0 };

    /** Brings every colour, light and flag in line with the theme and today's state. */
    /*
     * Grass, leaves and bark come from the season rather than from the CSS
     * tokens, which is why those four are missing from the list below.
     *
     * Foliage fades rather than shrinks. An InstancedMesh scales about the
     * world origin, not about each instance, so scaling the trees down would
     * have slid them all into the middle of the city; opacity leaves every
     * trunk exactly where it stood. Shadows are turned off with it, or winter
     * would cast the shadow of a tree that is not there.
     */
    function applySeason(animated) {
        const season = SEASONS[seasonIndex];
        const colours = theme === 'dark' ? season.night : season.day;

        toColor(mat.grass.color, new THREE.Color(colours.grass), animated);
        toColor(mat.tree.color, new THREE.Color(colours.tree), animated);
        toColor(mat.treeDark.color, new THREE.Color(colours.treeDark), animated);
        toColor(mat.trunk.color, new THREE.Color(colours.trunk), animated);
        toValue(mat.tree, 'opacity', season.foliage, animated);
        toValue(mat.treeDark, 'opacity', season.foliage, animated);
        cones.castShadow = crowns.castShadow = bushes.castShadow = season.foliage > 0.5;
    }

    function apply(animate) {
        const p = readPalette(host);
        const isNight = theme === 'dark';
        const light = LIGHTING[isNight ? 'dark' : 'light'];
        const open = state.isMealDay;

        applySeason(animate);

        toColor(scene.background, p.bg.color, animate);
        [
            [mat.platform, 'platform'], [mat.platformSide, 'platform-side'], [mat.walk, 'walk'],
            [mat.road, 'road'], [mat.dash, 'dash'], [mat.wall, 'top'], [mat.roofKitchen, 'roof-kitchen'],
            [mat.roofOffice, 'roof-office'], [mat.roofPlain, 'roof-plain'], [mat.door, 'door'], [mat.officeLit, 'win-on'], [mat.officeUnlit, 'win-off'],
            [mat.van, 'van-top'], [mat.stripe, 'van-stripe'], [mat.lamp, 'lamp'], [mat.pole, 'door'],
            [mat.tiffin, 'tiffin'],
            [mat.cloud, 'cloud'], [mat.beam, 'lamp'], [mat.pool, 'lamp'], [mat.grid, 'grid'], [mat.select, 'select'],
            // A holiday closes the kitchen in both themes.
            [mat.kitchenWindow, open ? 'win-on' : 'win-off'],
        ].forEach(([material, token]) => toColor(material.color, p[token].color, animate));
        puffMaterials.forEach((material) => toColor(material.color, p.smoke.color, animate));
        toValue(mat.grid, 'opacity', p.grid.alpha * 0.6, animate);

        // Lit windows, headlights and street lamps glow at night only.
        toColor(mat.officeLit.emissive, isNight ? p['win-on'].color : black, animate);
        toColor(mat.kitchenWindow.emissive, isNight && open ? p['win-on'].color : black, animate);
        toColor(mat.lamp.emissive, isNight ? p.lamp.color : black, animate);
        toValue(mat.beam, 'opacity', isNight ? 0.18 : 0, animate);
        toValue(mat.pool, 'opacity', isNight ? 0.2 : 0, animate);

        [p['lid-1'], p['lid-2'], p['lid-3'], p['lid-1']].forEach((token, i) => lids.setColorAt(i, token.color));
        lids.instanceColor.needsUpdate = true;

        toValue(hemi, 'intensity', light.hemi, animate);
        toColor(hemi.color, new THREE.Color(light.sky), animate);
        toColor(hemi.groundColor, new THREE.Color(light.groundLight), animate);
        toValue(key, 'intensity', light.key, animate);
        toColor(key.color, new THREE.Color(light.keyColor), animate);

        // Read each frame by the loop, so they are tweened on a holder.
        const sync = () => {
            night = holder.night;
            hearthLevel = holder.hearth;
        };
        if (animate) {
            tween(holder, { night: light.night, hearth: open ? light.kitchen : 0, onUpdate: sync });
        } else {
            holder.night = light.night;
            holder.hearth = open ? light.kitchen : 0;
            sync();
        }

        toValue(mat.star, 'opacity', light.night * 0.9, animate);
        toValue(mat.cloud, 'opacity', (1 - light.night) * 0.9, animate);

        // Shadows only by day: at night there is nothing bright enough to cast one worth
        // its cost.
        key.castShadow = !isNight;

        van.visible = open;
        tiffins.visible = open;
        labels.tiffins.el.textContent = state.tiffinLabel;
        render();
    }

    function setSelected(name) {
        selected = name;
        outline.removeFromParent();
        const group = groups[name];
        if (group) {
            // Only the parts that can be picked: the van's headlight beam lies
            // far out along the road and would stretch the box with it.
            const box = new THREE.Box3();
            group.traverse((child) => {
                if (child.isMesh && child.userData.select && child !== beam) {
                    box.expandByObject(child);
                }
            });
            box.expandByScalar(0.6);
            const size = box.getSize(new THREE.Vector3());
            outline.geometry.dispose();
            outline.geometry = new THREE.EdgesGeometry(new THREE.BoxGeometry(size.x, size.y, size.z));
            outline.position.copy(box.getCenter(new THREE.Vector3())).sub(group.position);
            outline.computeLineDistances();
            group.add(outline);
        }
        render();
    }

    // Picking.
    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();

    function pick(event) {
        const rect = renderer.domElement.getBoundingClientRect();
        pointer.set(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        // The raycaster does not consult visibility, so a van hidden for a
        // holiday has to be left out by hand.
        const pickable = [kitchen, office, ...(van.visible ? [van] : [])];
        const hit = raycaster.intersectObjects(pickable, true).find((h) => h.object.userData.select && (h.object.parent?.visible ?? true));

        return hit?.object.userData.select ?? null;
    }

    let hoverFrame = 0;
    function onPointerMove(event) {
        const rect = renderer.domElement.getBoundingClientRect();
        lean.tx = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        lean.ty = -(((event.clientY - rect.top) / rect.height) * 2 - 1);
        if (hoverFrame) {
            return;
        }
        hoverFrame = requestAnimationFrame(() => {
            hoverFrame = 0;
            hovered = pick(event);
            renderer.domElement.style.cursor = hovered ? 'pointer' : '';
        });
    }

    function onPointerLeave() {
        lean.tx = 0;
        lean.ty = 0;
        hovered = null;
    }

    function onClick(event) {
        const name = pick(event);
        if (name) {
            options.onSelect(name);
        }
    }

    const canvas = renderer.domElement;
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerleave', onPointerLeave);
    canvas.addEventListener('click', onClick);
    document.addEventListener('visibilitychange', updateRunning);

    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(host);
    const visibilityObserver = new IntersectionObserver(([entry]) => {
        onScreen = entry.isIntersecting;
        updateRunning();
    });
    visibilityObserver.observe(host);

    /*
     * Driving the route.
     *
     * The van runs its round trip on any day there are meals - out to the
     * office, back to the kitchen for the next load, round again - and does
     * not wait for the count to lock.
     *
     * That is a deliberate trade, made by the owner after it was put to them
     * twice: the van's position no longer says anything. It used to, parked
     * meaning nothing had been sent yet, and a van that is always moving
     * cannot carry that. The lock state is still on the screen - the status
     * card above the scene says it in words, and the summary line repeats it -
     * so nothing is lost that was only ever here. What is gained is a picture
     * that is alive on a dashboard people leave open all day.
     *
     * parkVanAtKitchen is kept for the only case that still stops the van: a
     * day with no meals, when it is hidden anyway.
     */
    const headingFor = ([x0, z0], [x1, z1]) =>
        (Math.abs(x1 - x0) >= Math.abs(z1 - z0)
            ? (x1 >= x0 ? 0 : Math.PI)
            : (z1 >= z0 ? -Math.PI / 2 : Math.PI / 2));

    let vanLoop = null;

    function stopVanLoop() {
        vanLoop?.kill();
        vanLoop = null;
    }

    function parkVanAtKitchen(animated) {
        stopVanLoop();
        const [x, z] = VAN_ROUTE[0];
        if (!animated) {
            van.position.set(x, 0, z);
            van.rotation.y = 0;

            return;
        }
        gsap.to(van.position, { x, z, duration: 1.2, ease: 'power2.inOut', overwrite: 'auto' });
        gsap.to(van.rotation, { y: 0, duration: 0.5, ease: 'power2.inOut', overwrite: 'auto' });
    }

    function runVanLoop() {
        stopVanLoop();
        // Out along the route and back the same way: the van returns to the
        // kitchen for the next load rather than teleporting there.
        const legs = [...VAN_ROUTE, ...VAN_ROUTE.slice(0, -1).reverse()];
        // Turns take the short way round. Tweening to a bare angle can spin
        // the van three quarters of a circle to reach a quarter turn.
        let heading = van.rotation.y;
        const shortestTo = (target) => {
            let next = target;
            while (next - heading > Math.PI) {
                next -= 2 * Math.PI;
            }
            while (next - heading < -Math.PI) {
                next += 2 * Math.PI;
            }
            heading = next;

            return next;
        };

        const timeline = gsap.timeline({ repeat: -1 });
        for (let i = 1; i < legs.length; i++) {
            const from = legs[i - 1];
            const to = legs[i];
            const distance = Math.hypot(to[0] - from[0], to[1] - from[1]);
            timeline.to(van.rotation, { y: shortestTo(headingFor(from, to)), duration: 0.4, ease: 'power2.inOut' });
            timeline.to(van.position, { x: to[0], z: to[1], duration: distance / VAN_SPEED, ease: 'power1.inOut' });
            // Unloading at the office, which is the far end of the outward run.
            if (i === VAN_ROUTE.length - 1) {
                timeline.to({}, { duration: 1.8 });
            }
        }
        // Face the way it set off, and land on exactly zero so the next lap
        // starts where this one began rather than a full turn away.
        timeline.to(van.rotation, { y: shortestTo(0), duration: 0.4, ease: 'power2.inOut' });
        timeline.set(van.rotation, { y: 0 });
        timeline.to({}, { duration: 1.8 });

        vanLoop = timeline;
    }

    parkVanAtKitchen(false);
    placeClouds(0);
    resize();
    apply(false);
    setSelected(options.selected);

    // First appearance: the town grows out of the platform and the van drives
    // in. Instanced meshes grow from y = 0, which their positions sit just above.
    const growing = [kitchen, office, greenery];
    growing.forEach((group) => group.scale.set(1, 0.001, 1));
    const vanX = van.position.x;
    van.position.x = vanX - 22;
    tweened.add(van.position);
    tweened.add(van.rotation);
    const intro = gsap.timeline();
    growing.forEach((group, i) => {
        tweened.add(group.scale);
        intro.to(group.scale, { y: 1, duration: 0.7, ease: 'back.out(1.6)' }, i * 0.12);
    });
    intro.to(van.position, { x: vanX, duration: 1.1, ease: 'power3.out' }, 0.25);
    intro.add(() => Object.values(labels).forEach((label) => (label.el.style.opacity = '1')));
    // Only once the van has finished arriving, or the loop would fight the
    // intro for the same property.
    intro.add(() => {
        if (state.isMealDay) {
            runVanLoop();
        }
    });

    updateRunning();

    return {
        setTheme(next) {
            theme = next;
            apply(true);
        },

        setState(next) {
            const wasMealDay = state.isMealDay;
            state = { ...next };
            // The round trip runs on any day there are meals, whatever the
            // count is doing. Only a day with no meals at all stops it, and on
            // such a day the van is hidden anyway.
            if (state.isMealDay !== wasMealDay) {
                if (state.isMealDay) {
                    runVanLoop();
                } else {
                    parkVanAtKitchen(true);
                }
            }
            apply(true);
        },

        setSelected,

        dispose() {
            running = false;
            cancelAnimationFrame(raf);
            cancelAnimationFrame(hoverFrame);
            intro.kill();
            stopVanLoop();
            tweened.forEach((target) => gsap.killTweensOf(target));
            resizeObserver.disconnect();
            visibilityObserver.disconnect();
            document.removeEventListener('visibilitychange', updateRunning);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerleave', onPointerLeave);
            canvas.removeEventListener('click', onClick);
            outline.geometry.dispose();
            scene.traverse((object) => {
                object.geometry?.dispose();
            });
            [...Object.values(mat), ...puffMaterials].forEach((material) => material.dispose());
            renderer.dispose();
            renderer.forceContextLoss();
            canvas.remove();
            Object.values(labels).forEach((label) => label.el.remove());
        },
    };
}
