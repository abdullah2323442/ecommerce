/* eslint-disable @typescript-eslint/no-explicit-any */
import { useEffect, useRef, type CSSProperties } from 'react';
import * as THREE from 'three';

/**
 * LiquidEther: a WebGL fluid simulation used as a living background. It reads the pointer from the whole
 * window (the canvas itself ignores clicks, so content on top stays usable), drifts by itself when idle,
 * pauses when off screen or in a hidden tab, and is released on unmount.
 */
export interface LiquidEtherProps {
    mouseForce?: number;
    cursorSize?: number;
    isViscous?: boolean;
    viscous?: number;
    iterationsViscous?: number;
    iterationsPoisson?: number;
    dt?: number;
    BFECC?: boolean;
    resolution?: number;
    isBounce?: boolean;
    /** Keep this array stable (module constant or useMemo): a new array rebuilds the simulation. */
    colors?: string[];
    style?: CSSProperties;
    className?: string;
    autoDemo?: boolean;
    autoSpeed?: number;
    autoIntensity?: number;
    takeoverDuration?: number;
    autoResumeDelay?: number;
    autoRampDuration?: number;
}

interface SimOptions {
    iterations_poisson: number;
    iterations_viscous: number;
    mouse_force: number;
    resolution: number;
    cursor_size: number;
    viscous: number;
    isBounce: boolean;
    dt: number;
    isViscous: boolean;
    BFECC: boolean;
}

interface Handle {
    resize: () => void;
    start: () => void;
    pause: () => void;
    dispose: () => void;
}

const defaultColors = ['#5227FF', '#FF9FFC', '#B19EEF'];

export default function LiquidEther({
    mouseForce = 20,
    cursorSize = 100,
    isViscous = false,
    viscous = 30,
    iterationsViscous = 32,
    iterationsPoisson = 32,
    dt = 0.014,
    BFECC = true,
    resolution = 0.5,
    isBounce = false,
    colors = defaultColors,
    style = {},
    className = '',
    autoDemo = true,
    autoSpeed = 0.5,
    autoIntensity = 2.2,
    takeoverDuration = 0.25,
    autoResumeDelay = 1000,
    autoRampDuration = 0.6,
}: LiquidEtherProps) {
    const mountRef = useRef<HTMLDivElement | null>(null);
    const webglRef = useRef<Handle | null>(null);
    const optionsRef = useRef<SimOptions | null>(null);
    const isVisibleRef = useRef(true);

    useEffect(() => {
        const mount = mountRef.current;
        if (!mount) return;

        let raf: number | null = null;
        let resizeRaf: number | null = null;

        const makePaletteTexture = (stops: string[]) => {
            const arr = stops.length > 0 ? (stops.length === 1 ? [stops[0], stops[0]] : stops) : ['#ffffff', '#ffffff'];
            const data = new Uint8Array(arr.length * 4);
            arr.forEach((hex, i) => {
                const c = new THREE.Color(hex);
                data.set([Math.round(c.r * 255), Math.round(c.g * 255), Math.round(c.b * 255), 255], i * 4);
            });
            const tex = new THREE.DataTexture(data, arr.length, 1, THREE.RGBAFormat);
            tex.magFilter = THREE.LinearFilter;
            tex.minFilter = THREE.LinearFilter;
            tex.wrapS = THREE.ClampToEdgeWrapping;
            tex.wrapT = THREE.ClampToEdgeWrapping;
            tex.generateMipmaps = false;
            tex.needsUpdate = true;
            return tex;
        };

        const paletteTex = makePaletteTexture(colors);
        const bgVec4 = new THREE.Vector4(0, 0, 0, 0);

        class CommonClass {
            width = 0;
            height = 0;
            pixelRatio = 1;
            time = 0;
            delta = 0;
            container: HTMLElement | null = null;
            renderer: THREE.WebGLRenderer | null = null;
            clock: THREE.Clock | null = null;

            init(container: HTMLElement) {
                this.container = container;
                this.pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
                this.resize();
                this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
                this.renderer.autoClear = false;
                this.renderer.setClearColor(new THREE.Color(0x000000), 0);
                this.renderer.setPixelRatio(this.pixelRatio);
                this.renderer.setSize(this.width, this.height);
                const el = this.renderer.domElement;
                el.style.width = '100%';
                el.style.height = '100%';
                el.style.display = 'block';
                this.clock = new THREE.Clock();
                this.clock.start();
            }

            resize() {
                if (!this.container) return;
                const rect = this.container.getBoundingClientRect();
                this.width = Math.max(1, Math.floor(rect.width));
                this.height = Math.max(1, Math.floor(rect.height));
                if (this.renderer) this.renderer.setSize(this.width, this.height, false);
            }

            update() {
                if (!this.clock) return;
                this.delta = this.clock.getDelta();
                this.time += this.delta;
            }
        }

        const Common = new CommonClass();

        class MouseClass {
            mouseMoved = false;
            coords = new THREE.Vector2();
            coords_old = new THREE.Vector2();
            diff = new THREE.Vector2();
            timer: number | null = null;
            container: HTMLElement | null = null;
            listenerTarget: Window | null = null;
            isHoverInside = false;
            hasUserControl = false;
            isAutoActive = false;
            autoIntensity = 2.0;
            takeoverActive = false;
            takeoverStartTime = 0;
            takeoverDuration = 0.25;
            takeoverFrom = new THREE.Vector2();
            takeoverTo = new THREE.Vector2();
            onInteract: (() => void) | null = null;

            private _move = this.onMove.bind(this);
            private _touchStart = this.onTouchStart.bind(this);
            private _touchMove = this.onTouchMove.bind(this);
            private _end = () => {
                this.isHoverInside = false;
            };

            init(container: HTMLElement) {
                this.container = container;
                this.listenerTarget = window;
                window.addEventListener('mousemove', this._move);
                window.addEventListener('touchstart', this._touchStart, { passive: true });
                window.addEventListener('touchmove', this._touchMove, { passive: true });
                window.addEventListener('touchend', this._end);
                document.addEventListener('mouseleave', this._end);
            }

            dispose() {
                window.removeEventListener('mousemove', this._move);
                window.removeEventListener('touchstart', this._touchStart);
                window.removeEventListener('touchmove', this._touchMove);
                window.removeEventListener('touchend', this._end);
                document.removeEventListener('mouseleave', this._end);
                if (this.timer) window.clearTimeout(this.timer);
                this.container = null;
            }

            private inside(x: number, y: number) {
                if (!this.container) return false;
                const r = this.container.getBoundingClientRect();
                return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
            }

            setCoords(x: number, y: number) {
                if (!this.container) return;
                if (this.timer) window.clearTimeout(this.timer);
                const r = this.container.getBoundingClientRect();
                if (r.width === 0 || r.height === 0) return;
                this.coords.set(((x - r.left) / r.width) * 2 - 1, -(((y - r.top) / r.height) * 2 - 1));
                this.mouseMoved = true;
                this.timer = window.setTimeout(() => {
                    this.mouseMoved = false;
                }, 100);
            }

            setNormalized(nx: number, ny: number) {
                this.coords.set(nx, ny);
                this.mouseMoved = true;
            }

            onMove(e: MouseEvent) {
                this.isHoverInside = this.inside(e.clientX, e.clientY);
                if (!this.isHoverInside) return;
                this.onInteract?.();
                if (this.isAutoActive && !this.hasUserControl && !this.takeoverActive && this.container) {
                    const r = this.container.getBoundingClientRect();
                    this.takeoverFrom.copy(this.coords);
                    this.takeoverTo.set(((e.clientX - r.left) / r.width) * 2 - 1, -(((e.clientY - r.top) / r.height) * 2 - 1));
                    this.takeoverStartTime = performance.now();
                    this.takeoverActive = true;
                    this.hasUserControl = true;
                    this.isAutoActive = false;
                    return;
                }
                this.setCoords(e.clientX, e.clientY);
                this.hasUserControl = true;
            }

            onTouchStart(e: TouchEvent) {
                if (e.touches.length !== 1) return;
                const t = e.touches[0];
                this.isHoverInside = this.inside(t.clientX, t.clientY);
                if (!this.isHoverInside) return;
                this.onInteract?.();
                this.setCoords(t.clientX, t.clientY);
                this.hasUserControl = true;
            }

            onTouchMove(e: TouchEvent) {
                if (e.touches.length !== 1) return;
                const t = e.touches[0];
                this.isHoverInside = this.inside(t.clientX, t.clientY);
                if (!this.isHoverInside) return;
                this.onInteract?.();
                this.setCoords(t.clientX, t.clientY);
            }

            update() {
                if (this.takeoverActive) {
                    const t = (performance.now() - this.takeoverStartTime) / (this.takeoverDuration * 1000);
                    if (t >= 1) {
                        this.takeoverActive = false;
                        this.coords.copy(this.takeoverTo);
                        this.coords_old.copy(this.coords);
                        this.diff.set(0, 0);
                    } else {
                        this.coords.copy(this.takeoverFrom).lerp(this.takeoverTo, t * t * (3 - 2 * t));
                    }
                }
                this.diff.subVectors(this.coords, this.coords_old);
                this.coords_old.copy(this.coords);
                if (this.coords_old.x === 0 && this.coords_old.y === 0) this.diff.set(0, 0);
                if (this.isAutoActive && !this.takeoverActive) this.diff.multiplyScalar(this.autoIntensity);
            }
        }

        const Mouse = new MouseClass();

        class AutoDriver {
            enabled: boolean;
            speed: number;
            resumeDelay: number;
            rampDurationMs: number;
            active = false;
            current = new THREE.Vector2(0, 0);
            target = new THREE.Vector2();
            lastTime = performance.now();
            activationTime = 0;
            margin = 0.2;
            private _dir = new THREE.Vector2();

            constructor(
                private mouse: MouseClass,
                private manager: { lastUserInteraction: number },
                opts: { enabled: boolean; speed: number; resumeDelay: number; rampDuration: number },
            ) {
                this.enabled = opts.enabled;
                this.speed = opts.speed;
                this.resumeDelay = opts.resumeDelay || 3000;
                this.rampDurationMs = (opts.rampDuration || 0) * 1000;
                this.pickNewTarget();
            }

            pickNewTarget() {
                const r = Math.random;
                this.target.set((r() * 2 - 1) * (1 - this.margin), (r() * 2 - 1) * (1 - this.margin));
            }

            forceStop() {
                this.active = false;
                this.mouse.isAutoActive = false;
            }

            update() {
                if (!this.enabled) return;
                const now = performance.now();
                if (now - this.manager.lastUserInteraction < this.resumeDelay || this.mouse.isHoverInside) {
                    if (this.active) this.forceStop();
                    return;
                }
                if (!this.active) {
                    this.active = true;
                    this.current.copy(this.mouse.coords);
                    this.lastTime = now;
                    this.activationTime = now;
                }
                this.mouse.isAutoActive = true;
                let dtSec = (now - this.lastTime) / 1000;
                this.lastTime = now;
                if (dtSec > 0.2) dtSec = 0.016;
                const dir = this._dir.subVectors(this.target, this.current);
                const dist = dir.length();
                if (dist < 0.01) {
                    this.pickNewTarget();
                    return;
                }
                dir.normalize();
                let ramp = 1;
                if (this.rampDurationMs > 0) {
                    const t = Math.min(1, (now - this.activationTime) / this.rampDurationMs);
                    ramp = t * t * (3 - 2 * t);
                }
                this.current.addScaledVector(dir, Math.min(this.speed * dtSec * ramp, dist));
                this.mouse.setNormalized(this.current.x, this.current.y);
            }
        }

        const face_vert = `
      attribute vec3 position;
      uniform vec2 px;
      uniform vec2 boundarySpace;
      varying vec2 uv;
      precision highp float;
      void main(){
        vec3 pos = position;
        vec2 scale = 1.0 - boundarySpace * 2.0;
        pos.xy = pos.xy * scale;
        uv = vec2(0.5)+(pos.xy)*0.5;
        gl_Position = vec4(pos, 1.0);
      }
    `;

        const line_vert = `
      attribute vec3 position;
      uniform vec2 px;
      precision highp float;
      varying vec2 uv;
      void main(){
        vec3 pos = position;
        uv = 0.5 + pos.xy * 0.5;
        vec2 n = sign(pos.xy);
        pos.xy = abs(pos.xy) - px * 1.0;
        pos.xy *= n;
        gl_Position = vec4(pos, 1.0);
      }
    `;

        const mouse_vert = `
      precision highp float;
      attribute vec3 position;
      attribute vec2 uv;
      uniform vec2 center;
      uniform vec2 scale;
      uniform vec2 px;
      varying vec2 vUv;
      void main(){
        vec2 pos = position.xy * scale * 2.0 * px + center;
        vUv = uv;
        gl_Position = vec4(pos, 0.0, 1.0);
      }
    `;

        const advection_frag = `
      precision highp float;
      uniform sampler2D velocity;
      uniform float dt;
      uniform bool isBFECC;
      uniform vec2 fboSize;
      uniform vec2 px;
      varying vec2 uv;
      void main(){
        vec2 ratio = max(fboSize.x, fboSize.y) / fboSize;
        if(isBFECC == false){
          vec2 vel = texture2D(velocity, uv).xy;
          vec2 uv2 = uv - vel * dt * ratio;
          vec2 newVel = texture2D(velocity, uv2).xy;
          gl_FragColor = vec4(newVel, 0.0, 0.0);
        } else {
          vec2 spot_new = uv;
          vec2 vel_old = texture2D(velocity, uv).xy;
          vec2 spot_old = spot_new - vel_old * dt * ratio;
          vec2 vel_new1 = texture2D(velocity, spot_old).xy;
          vec2 spot_new2 = spot_old + vel_new1 * dt * ratio;
          vec2 error = spot_new2 - spot_new;
          vec2 spot_new3 = spot_new - error / 2.0;
          vec2 vel_2 = texture2D(velocity, spot_new3).xy;
          vec2 spot_old2 = spot_new3 - vel_2 * dt * ratio;
          vec2 newVel2 = texture2D(velocity, spot_old2).xy;
          gl_FragColor = vec4(newVel2, 0.0, 0.0);
        }
      }
    `;

        const color_frag = `
      precision highp float;
      uniform sampler2D velocity;
      uniform sampler2D palette;
      uniform vec4 bgColor;
      varying vec2 uv;
      void main(){
        vec2 vel = texture2D(velocity, uv).xy;
        float lenv = clamp(length(vel), 0.0, 1.0);
        vec3 c = texture2D(palette, vec2(lenv, 0.5)).rgb;
        vec3 outRGB = mix(bgColor.rgb, c, lenv);
        float outA = mix(bgColor.a, 1.0, lenv);
        gl_FragColor = vec4(outRGB, outA);
      }
    `;

        const divergence_frag = `
      precision highp float;
      uniform sampler2D velocity;
      uniform float dt;
      uniform vec2 px;
      varying vec2 uv;
      void main(){
        float x0 = texture2D(velocity, uv-vec2(px.x, 0.0)).x;
        float x1 = texture2D(velocity, uv+vec2(px.x, 0.0)).x;
        float y0 = texture2D(velocity, uv-vec2(0.0, px.y)).y;
        float y1 = texture2D(velocity, uv+vec2(0.0, px.y)).y;
        float divergence = (x1 - x0 + y1 - y0) / 2.0;
        gl_FragColor = vec4(divergence / dt);
      }
    `;

        const externalForce_frag = `
      precision highp float;
      uniform vec2 force;
      uniform vec2 center;
      uniform vec2 scale;
      uniform vec2 px;
      varying vec2 vUv;
      void main(){
        vec2 circle = (vUv - 0.5) * 2.0;
        float d = 1.0 - min(length(circle), 1.0);
        d *= d;
        gl_FragColor = vec4(force * d, 0.0, 1.0);
      }
    `;

        const poisson_frag = `
      precision highp float;
      uniform sampler2D pressure;
      uniform sampler2D divergence;
      uniform vec2 px;
      varying vec2 uv;
      void main(){
        float p0 = texture2D(pressure, uv + vec2(px.x * 2.0, 0.0)).r;
        float p1 = texture2D(pressure, uv - vec2(px.x * 2.0, 0.0)).r;
        float p2 = texture2D(pressure, uv + vec2(0.0, px.y * 2.0)).r;
        float p3 = texture2D(pressure, uv - vec2(0.0, px.y * 2.0)).r;
        float div = texture2D(divergence, uv).r;
        float newP = (p0 + p1 + p2 + p3) / 4.0 - div;
        gl_FragColor = vec4(newP);
      }
    `;

        const pressure_frag = `
      precision highp float;
      uniform sampler2D pressure;
      uniform sampler2D velocity;
      uniform vec2 px;
      uniform float dt;
      varying vec2 uv;
      void main(){
        float step = 1.0;
        float p0 = texture2D(pressure, uv + vec2(px.x * step, 0.0)).r;
        float p1 = texture2D(pressure, uv - vec2(px.x * step, 0.0)).r;
        float p2 = texture2D(pressure, uv + vec2(0.0, px.y * step)).r;
        float p3 = texture2D(pressure, uv - vec2(0.0, px.y * step)).r;
        vec2 v = texture2D(velocity, uv).xy;
        vec2 gradP = vec2(p0 - p1, p2 - p3) * 0.5;
        v = v - gradP * dt;
        gl_FragColor = vec4(v, 0.0, 1.0);
      }
    `;

        const viscous_frag = `
      precision highp float;
      uniform sampler2D velocity;
      uniform sampler2D velocity_new;
      uniform float v;
      uniform vec2 px;
      uniform float dt;
      varying vec2 uv;
      void main(){
        vec2 old = texture2D(velocity, uv).xy;
        vec2 new0 = texture2D(velocity_new, uv + vec2(px.x * 2.0, 0.0)).xy;
        vec2 new1 = texture2D(velocity_new, uv - vec2(px.x * 2.0, 0.0)).xy;
        vec2 new2 = texture2D(velocity_new, uv + vec2(0.0, px.y * 2.0)).xy;
        vec2 new3 = texture2D(velocity_new, uv - vec2(0.0, px.y * 2.0)).xy;
        vec2 newv = 4.0 * old + v * dt * (new0 + new1 + new2 + new3);
        newv /= 4.0 * (1.0 + v * dt);
        gl_FragColor = vec4(newv, 0.0, 0.0);
      }
    `;

        class ShaderPass {
            props: any;
            uniforms?: Record<string, { value: any }>;
            scene: THREE.Scene | null = null;
            camera: THREE.Camera | null = null;
            material: THREE.RawShaderMaterial | null = null;
            geometry: THREE.BufferGeometry | null = null;
            plane: THREE.Mesh | null = null;

            constructor(props: any) {
                this.props = props || {};
                this.uniforms = this.props.material?.uniforms;
            }

            init() {
                this.scene = new THREE.Scene();
                this.camera = new THREE.Camera();
                if (this.uniforms) {
                    this.material = new THREE.RawShaderMaterial(this.props.material);
                    this.geometry = new THREE.PlaneGeometry(2, 2);
                    this.plane = new THREE.Mesh(this.geometry, this.material);
                    this.scene.add(this.plane);
                }
            }

            update(..._args: any[]) {
                if (!Common.renderer || !this.scene || !this.camera) return;
                Common.renderer.setRenderTarget(this.props.output || null);
                Common.renderer.render(this.scene, this.camera);
                Common.renderer.setRenderTarget(null);
            }
        }

        class Advection extends ShaderPass {
            line!: THREE.LineSegments;
            constructor(p: any) {
                super({
                    material: {
                        vertexShader: face_vert,
                        fragmentShader: advection_frag,
                        uniforms: {
                            boundarySpace: { value: p.cellScale },
                            px: { value: p.cellScale },
                            fboSize: { value: p.fboSize },
                            velocity: { value: p.src.texture },
                            dt: { value: p.dt },
                            isBFECC: { value: true },
                        },
                    },
                    output: p.dst,
                });
                this.init();
            }
            init() {
                super.init();
                const g = new THREE.BufferGeometry();
                g.setAttribute(
                    'position',
                    new THREE.BufferAttribute(new Float32Array([-1, -1, 0, -1, 1, 0, -1, 1, 0, 1, 1, 0, 1, 1, 0, 1, -1, 0, 1, -1, 0, -1, -1, 0]), 3),
                );
                this.line = new THREE.LineSegments(
                    g,
                    new THREE.RawShaderMaterial({ vertexShader: line_vert, fragmentShader: advection_frag, uniforms: this.uniforms! }),
                );
                this.scene!.add(this.line);
            }
            update(args: any) {
                if (!this.uniforms) return;
                this.uniforms.dt.value = args.dt;
                this.line.visible = args.isBounce;
                this.uniforms.isBFECC.value = args.BFECC;
                super.update();
            }
        }

        class ExternalForce extends ShaderPass {
            mouse!: THREE.Mesh;
            constructor(p: any) {
                super({ output: p.dst });
                super.init();
                this.mouse = new THREE.Mesh(
                    new THREE.PlaneGeometry(1, 1),
                    new THREE.RawShaderMaterial({
                        vertexShader: mouse_vert,
                        fragmentShader: externalForce_frag,
                        blending: THREE.AdditiveBlending,
                        depthWrite: false,
                        uniforms: {
                            px: { value: p.cellScale },
                            force: { value: new THREE.Vector2(0, 0) },
                            center: { value: new THREE.Vector2(0, 0) },
                            scale: { value: new THREE.Vector2(p.cursor_size, p.cursor_size) },
                        },
                    }),
                );
                this.scene!.add(this.mouse);
            }
            update(args: any) {
                const u = (this.mouse.material as THREE.RawShaderMaterial).uniforms;
                u.force.value.set((Mouse.diff.x / 2) * args.mouse_force, (Mouse.diff.y / 2) * args.mouse_force);
                u.center.value.set(Mouse.coords.x, Mouse.coords.y);
                u.scale.value.set(args.cursor_size, args.cursor_size);
                super.update();
            }
        }

        class Viscous extends ShaderPass {
            constructor(p: any) {
                super({
                    material: {
                        vertexShader: face_vert,
                        fragmentShader: viscous_frag,
                        uniforms: {
                            boundarySpace: { value: p.boundarySpace },
                            velocity: { value: p.src.texture },
                            velocity_new: { value: p.dst_.texture },
                            v: { value: p.viscous },
                            px: { value: p.cellScale },
                            dt: { value: p.dt },
                        },
                    },
                    output: p.dst,
                    output0: p.dst_,
                    output1: p.dst,
                });
                this.init();
            }
            update(args: any) {
                for (let i = 0; i < args.iterations; i++) {
                    const fin = i % 2 === 0 ? this.props.output0 : this.props.output1;
                    const fout = i % 2 === 0 ? this.props.output1 : this.props.output0;
                    this.uniforms!.velocity_new.value = fin.texture;
                    this.props.output = fout;
                    this.uniforms!.dt.value = args.dt;
                    super.update();
                }
                return args.iterations % 2 === 0 ? this.props.output0 : this.props.output1;
            }
        }

        class Divergence extends ShaderPass {
            constructor(p: any) {
                super({
                    material: {
                        vertexShader: face_vert,
                        fragmentShader: divergence_frag,
                        uniforms: {
                            boundarySpace: { value: p.boundarySpace },
                            velocity: { value: p.src.texture },
                            px: { value: p.cellScale },
                            dt: { value: p.dt },
                        },
                    },
                    output: p.dst,
                });
                this.init();
            }
            update(args: any) {
                if (this.uniforms) this.uniforms.velocity.value = args.vel.texture;
                super.update();
            }
        }

        class Poisson extends ShaderPass {
            constructor(p: any) {
                super({
                    material: {
                        vertexShader: face_vert,
                        fragmentShader: poisson_frag,
                        uniforms: {
                            boundarySpace: { value: p.boundarySpace },
                            pressure: { value: p.dst_.texture },
                            divergence: { value: p.src.texture },
                            px: { value: p.cellScale },
                        },
                    },
                    output: p.dst,
                    output0: p.dst_,
                    output1: p.dst,
                });
                this.init();
            }
            update(args: any) {
                for (let i = 0; i < args.iterations; i++) {
                    const pin = i % 2 === 0 ? this.props.output0 : this.props.output1;
                    const pout = i % 2 === 0 ? this.props.output1 : this.props.output0;
                    this.uniforms!.pressure.value = pin.texture;
                    this.props.output = pout;
                    super.update();
                }
                return args.iterations % 2 === 0 ? this.props.output0 : this.props.output1;
            }
        }

        class Pressure extends ShaderPass {
            constructor(p: any) {
                super({
                    material: {
                        vertexShader: face_vert,
                        fragmentShader: pressure_frag,
                        uniforms: {
                            boundarySpace: { value: p.boundarySpace },
                            pressure: { value: p.src_p.texture },
                            velocity: { value: p.src_v.texture },
                            px: { value: p.cellScale },
                            dt: { value: p.dt },
                        },
                    },
                    output: p.dst,
                });
                this.init();
            }
            update(args: any) {
                this.uniforms!.velocity.value = args.vel.texture;
                this.uniforms!.pressure.value = args.pressure.texture;
                super.update();
            }
        }

        class Simulation {
            options: SimOptions;
            fbos: Record<string, THREE.WebGLRenderTarget | null> = {
                vel_0: null,
                vel_1: null,
                vel_viscous0: null,
                vel_viscous1: null,
                div: null,
                pressure_0: null,
                pressure_1: null,
            };
            fboSize = new THREE.Vector2();
            cellScale = new THREE.Vector2();
            boundarySpace = new THREE.Vector2();
            advection!: Advection;
            externalForce!: ExternalForce;
            viscous!: Viscous;
            divergence!: Divergence;
            poisson!: Poisson;
            pressure!: Pressure;

            constructor() {
                this.options = {
                    iterations_poisson: iterationsPoisson,
                    iterations_viscous: iterationsViscous,
                    mouse_force: mouseForce,
                    resolution,
                    cursor_size: cursorSize,
                    viscous,
                    isBounce,
                    dt,
                    isViscous,
                    BFECC,
                };
                optionsRef.current = this.options;
                this.calcSize();
                const type = /(iPad|iPhone|iPod)/i.test(navigator.userAgent) ? THREE.HalfFloatType : THREE.FloatType;
                const opts = {
                    type,
                    depthBuffer: false,
                    stencilBuffer: false,
                    minFilter: THREE.LinearFilter,
                    magFilter: THREE.LinearFilter,
                    wrapS: THREE.ClampToEdgeWrapping,
                    wrapT: THREE.ClampToEdgeWrapping,
                };
                for (const key in this.fbos) this.fbos[key] = new THREE.WebGLRenderTarget(this.fboSize.x, this.fboSize.y, opts);
                const f = this.fbos;
                this.advection = new Advection({ cellScale: this.cellScale, fboSize: this.fboSize, dt: this.options.dt, src: f.vel_0, dst: f.vel_1 });
                this.externalForce = new ExternalForce({ cellScale: this.cellScale, cursor_size: this.options.cursor_size, dst: f.vel_1 });
                this.viscous = new Viscous({
                    cellScale: this.cellScale,
                    boundarySpace: this.boundarySpace,
                    viscous: this.options.viscous,
                    src: f.vel_1,
                    dst: f.vel_viscous1,
                    dst_: f.vel_viscous0,
                    dt: this.options.dt,
                });
                this.divergence = new Divergence({
                    cellScale: this.cellScale,
                    boundarySpace: this.boundarySpace,
                    src: f.vel_viscous0,
                    dst: f.div,
                    dt: this.options.dt,
                });
                this.poisson = new Poisson({
                    cellScale: this.cellScale,
                    boundarySpace: this.boundarySpace,
                    src: f.div,
                    dst: f.pressure_1,
                    dst_: f.pressure_0,
                });
                this.pressure = new Pressure({
                    cellScale: this.cellScale,
                    boundarySpace: this.boundarySpace,
                    src_p: f.pressure_0,
                    src_v: f.vel_viscous0,
                    dst: f.vel_0,
                    dt: this.options.dt,
                });
            }

            calcSize() {
                const w = Math.max(1, Math.round(this.options.resolution * Common.width));
                const h = Math.max(1, Math.round(this.options.resolution * Common.height));
                this.cellScale.set(1 / w, 1 / h);
                this.fboSize.set(w, h);
            }

            resize() {
                this.calcSize();
                for (const key in this.fbos) this.fbos[key]!.setSize(this.fboSize.x, this.fboSize.y);
            }

            dispose() {
                for (const key in this.fbos) this.fbos[key]?.dispose();
            }

            update() {
                this.boundarySpace.copy(this.options.isBounce ? new THREE.Vector2(0, 0) : this.cellScale);
                this.advection.update({ dt: this.options.dt, isBounce: this.options.isBounce, BFECC: this.options.BFECC });
                this.externalForce.update({ cursor_size: this.options.cursor_size, mouse_force: this.options.mouse_force });
                let vel = this.fbos.vel_1;
                if (this.options.isViscous) vel = this.viscous.update({ iterations: this.options.iterations_viscous, dt: this.options.dt });
                this.divergence.update({ vel });
                const pressure = this.poisson.update({ iterations: this.options.iterations_poisson });
                this.pressure.update({ vel, pressure });
            }
        }

        class Output {
            simulation = new Simulation();
            scene = new THREE.Scene();
            camera = new THREE.Camera();
            output: THREE.Mesh;
            constructor() {
                this.output = new THREE.Mesh(
                    new THREE.PlaneGeometry(2, 2),
                    new THREE.RawShaderMaterial({
                        vertexShader: face_vert,
                        fragmentShader: color_frag,
                        transparent: true,
                        depthWrite: false,
                        uniforms: {
                            velocity: { value: this.simulation.fbos.vel_0!.texture },
                            boundarySpace: { value: new THREE.Vector2() },
                            palette: { value: paletteTex },
                            bgColor: { value: bgVec4 },
                        },
                    }),
                );
                this.scene.add(this.output);
            }
            resize() {
                this.simulation.resize();
            }
            update() {
                this.simulation.update();
                if (Common.renderer) {
                    Common.renderer.setRenderTarget(null);
                    Common.renderer.render(this.scene, this.camera);
                }
            }
        }

        class WebGLManager implements Handle {
            output!: Output;
            autoDriver: AutoDriver;
            lastUserInteraction = performance.now();
            running = false;
            private _loop = this.loop.bind(this);
            private _resize = this.resize.bind(this);
            private _visibility = () => {
                if (document.hidden) this.pause();
                else if (isVisibleRef.current) this.start();
            };

            constructor() {
                Common.init(mount!);
                Mouse.init(mount!);
                Mouse.autoIntensity = autoIntensity;
                Mouse.takeoverDuration = takeoverDuration;
                Mouse.onInteract = () => {
                    this.lastUserInteraction = performance.now();
                    this.autoDriver.forceStop();
                };
                this.autoDriver = new AutoDriver(Mouse, this, {
                    enabled: autoDemo,
                    speed: autoSpeed,
                    resumeDelay: autoResumeDelay,
                    rampDuration: autoRampDuration,
                });
                if (Common.renderer) {
                    mount!.prepend(Common.renderer.domElement);
                    this.output = new Output();
                }
                window.addEventListener('resize', this._resize);
                document.addEventListener('visibilitychange', this._visibility);
            }

            resize() {
                Common.resize();
                this.output.resize();
            }

            loop() {
                if (!this.running) return;
                this.autoDriver.update();
                Mouse.update();
                Common.update();
                this.output.update();
                raf = requestAnimationFrame(this._loop);
            }

            start() {
                if (this.running) return;
                this.running = true;
                this._loop();
            }

            pause() {
                this.running = false;
                if (raf) cancelAnimationFrame(raf);
                raf = null;
            }

            dispose() {
                this.pause();
                window.removeEventListener('resize', this._resize);
                document.removeEventListener('visibilitychange', this._visibility);
                Mouse.dispose();
                this.output.simulation.dispose();
                paletteTex.dispose();
                if (Common.renderer) {
                    const canvas = Common.renderer.domElement;
                    canvas.parentNode?.removeChild(canvas);
                    Common.renderer.dispose();
                    Common.renderer.forceContextLoss();
                }
            }
        }

        let webgl: WebGLManager;
        try {
            webgl = new WebGLManager();
        } catch {
            return; // no WebGL on this device: the section keeps its plain background
        }
        webglRef.current = webgl;
        webgl.start();

        const io = new IntersectionObserver(
            (entries) => {
                isVisibleRef.current = entries[0].isIntersecting;
                if (isVisibleRef.current && !document.hidden) webglRef.current?.start();
                else webglRef.current?.pause();
            },
            { threshold: 0.05 },
        );
        io.observe(mount);

        const ro = new ResizeObserver(() => {
            if (resizeRaf) cancelAnimationFrame(resizeRaf);
            resizeRaf = requestAnimationFrame(() => webglRef.current?.resize());
        });
        ro.observe(mount);

        return () => {
            if (resizeRaf) cancelAnimationFrame(resizeRaf);
            ro.disconnect();
            io.disconnect();
            webglRef.current?.dispose();
            webglRef.current = null;
        };
        // The simulation is rebuilt only when the look-defining props change; tuning props are applied live below.
    }, [colors, autoDemo, autoSpeed, autoIntensity, takeoverDuration, autoResumeDelay, autoRampDuration]);

    // Live tuning without rebuilding the simulation.
    useEffect(() => {
        const o = optionsRef.current;
        if (!o) return;
        o.mouse_force = mouseForce;
        o.cursor_size = cursorSize;
        o.isViscous = isViscous;
        o.viscous = viscous;
        o.iterations_viscous = iterationsViscous;
        o.iterations_poisson = iterationsPoisson;
        o.dt = dt;
        o.BFECC = BFECC;
        o.isBounce = isBounce;
        if (resolution !== o.resolution) {
            o.resolution = resolution;
            webglRef.current?.resize();
        }
    }, [mouseForce, cursorSize, isViscous, viscous, iterationsViscous, iterationsPoisson, dt, BFECC, isBounce, resolution]);

    return (
        <div
            ref={mountRef}
            aria-hidden
            className={`pointer-events-none relative h-full w-full touch-none overflow-hidden ${className}`}
            style={style}
        />
    );
}
